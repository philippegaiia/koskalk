<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;

class RecipeWorkbenchPreviewService
{
    public function __construct(
        private readonly SoapCalculationService $soapCalculationService,
        private readonly InciGenerationService $inciGenerationService,
        private readonly SubstanceComplianceService $substanceComplianceService,
        private readonly RecipeWorkbenchDraftPayloadMapper $recipeWorkbenchDraftPayloadMapper,
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly LyeLiquidIngredientValidator $lyeLiquidIngredientValidator,
        private readonly IngredientFormulaContextResolver $ingredientFormulaContextResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewSoapCalculation(
        array $payload,
        ?User $user = null,
        bool $validateLyeIngredients = true,
        bool $enforceSuperfatFloors = true,
    ): ?array {
        $rawLyeLiquidRows = data_get($payload, 'phase_items.lye_water', []);
        $rawLyeLiquidRows = is_array($rawLyeLiquidRows) ? $rawLyeLiquidRows : [];
        $this->lyeLiquidIngredientValidator->assertMaximumRows($rawLyeLiquidRows);

        if (($payload['manufacturing_mode'] ?? 'saponify_in_formula') !== 'saponify_in_formula') {
            return null;
        }

        $oilRows = collect($payload['phase_items']['saponified_oils'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();

        if ($oilRows->isEmpty()) {
            return null;
        }

        $ingredients = Ingredient::query()
            ->with(['sapProfile', 'fattyAcidEntries.fattyAcid'])
            ->whereKey($oilRows->pluck('ingredient_id')->filter()->map(fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        $oils = $oilRows
            ->map(function (array $row) use ($ingredients, $payload): ?array {
                $ingredientId = isset($row['ingredient_id']) ? (int) $row['ingredient_id'] : null;

                if ($ingredientId === null) {
                    return null;
                }

                $ingredient = $ingredients->get($ingredientId);

                if (! $ingredient instanceof Ingredient) {
                    return null;
                }

                $weight = $this->previewRowWeight($row, $payload);

                if ($weight <= 0) {
                    return null;
                }

                return [
                    'name' => $ingredient->display_name,
                    'weight' => $weight,
                    'koh_sap_value' => $ingredient->sapProfile?->koh_sap_value ?? 0,
                    'fatty_acid_profile' => $ingredient->normalizedFattyAcidProfile(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($oils === []) {
            return null;
        }

        $additionContexts = $this->additionContexts($payload);
        $postReactionOils = $this->postReactionOils($additionContexts);

        $calculation = $this->soapCalculationService->calculate($oils, [
            'superfat' => (float) ($payload['superfat'] ?? 5),
            'lye_type' => $payload['lye_type'] ?? 'naoh',
            'dual_lye_koh_percentage' => (float) ($payload['dual_lye_koh_percentage'] ?? 40),
            'koh_purity_percentage' => (float) ($payload['koh_purity_percentage'] ?? 90),
            'water_mode' => $payload['water_mode'] ?? 'percent_of_oils',
            'water_value' => (float) ($payload['water_value'] ?? 38),
            'enforce_superfat_floors' => $enforceSuperfatFloors,
        ], $postReactionOils);

        $lyeLiquidRows = $validateLyeIngredients
            ? $this->lyeLiquidIngredientValidator->validate($rawLyeLiquidRows, $user)
            : $this->lyeLiquidIngredientValidator->normalizeRows($rawLyeLiquidRows);
        $calculation['lye']['uncounted_post_reaction_oils'] = $this->uncountedPostReactionOils($additionContexts);
        $calculation['lye']['liquid_composition'] = $this->lyeLiquidAllocationService->allocateFresh(
            (string) $calculation['lye']['water']['weight'],
            $lyeLiquidRows,
        );

        return $calculation;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    public function calculationFromWorkbenchDraft(array $draft): ?array
    {
        return $this->previewSoapCalculation($this->recipeWorkbenchDraftPayloadMapper->toPreviewPayload($draft));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function previewInci(array $payload, ?array $calculation = null): array
    {
        return $this->inciGenerationService->generate(
            $payload,
            $calculation ?? $this->previewSoapCalculation($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function inciFromWorkbenchDraft(array $draft): array
    {
        return $this->labelingFromWorkbenchDraft($draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function labelingFromWorkbenchDraft(array $draft, ?array $calculation = null): array
    {
        $previewPayload = $this->recipeWorkbenchDraftPayloadMapper->toPreviewPayload($draft);

        return $this->previewInci(
            $previewPayload,
            $calculation ?? $this->calculationFromWorkbenchDraft($draft),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function previewRestrictions(array $payload, ?array $calculation = null): array
    {
        return $this->substanceComplianceService->evaluate(
            $payload,
            $calculation ?? $this->previewSoapCalculation($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    public function restrictionsFromWorkbenchDraft(array $draft, ?array $calculation = null): array
    {
        $previewPayload = $this->recipeWorkbenchDraftPayloadMapper->toPreviewPayload($draft);

        return $this->previewRestrictions(
            $previewPayload,
            $calculation ?? $this->calculationFromWorkbenchDraft($draft),
        );
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>, restrictions: array<string, mixed>}
     */
    public function snapshotFromWorkbenchDraft(array $draft): array
    {
        return $this->snapshot($draft, true);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>, restrictions: array<string, mixed>}
     */
    public function snapshotFromPersistedWorkbenchDraft(array $draft): array
    {
        return $this->snapshot($draft, false);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>, restrictions: array<string, mixed>}
     */
    private function snapshot(array $draft, bool $validateLyeIngredients): array
    {
        $previewPayload = $this->recipeWorkbenchDraftPayloadMapper->toPreviewPayload($draft);
        // Saved versions are read with the rules they were saved under, so
        // tighter authoring floors never make an existing formula unreadable.
        $calculation = $this->previewSoapCalculation(
            $previewPayload,
            validateLyeIngredients: $validateLyeIngredients,
            enforceSuperfatFloors: $validateLyeIngredients,
        );

        return [
            'draft' => $draft,
            'calculation' => $calculation,
            'labeling' => $this->previewInci($previewPayload, $calculation),
            'restrictions' => $this->previewRestrictions($previewPayload, $calculation),
        ];
    }

    /**
     * Saponifiable oils added after the reaction, expanded through compound
     * ingredients exactly as the labeling does, so the lye they absorb and the
     * share shown as saponified on labels always describe the same oils.
     *
     * @param  array<int, array{phase_key: string, weight: float, ingredient: Ingredient|null}>  $additionContexts
     * @return array<int, array{weight: float, koh_sap_value: float|string}>
     */
    private function postReactionOils(array $additionContexts): array
    {
        return collect($additionContexts)
            ->filter(fn (array $context): bool => $context['phase_key'] === 'additives'
                && $context['ingredient'] instanceof Ingredient
                && $context['ingredient']->canDriveSoapSaponification())
            ->map(fn (array $context): array => [
                'weight' => $context['weight'],
                'koh_sap_value' => $context['ingredient']->sapProfile?->koh_sap_value ?? 0,
            ])
            ->values()
            ->all();
    }

    /**
     * Additions phase rows, expanded through compound ingredients.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{phase_key: string, weight: float, ingredient: Ingredient|null}>
     */
    private function additionContexts(array $payload): array
    {
        return $this->ingredientFormulaContextResolver->resolve([
            'oil_weight' => $payload['oil_weight'] ?? 0,
            'phase_items' => ['additives' => $payload['phase_items']['additives'] ?? []],
        ], ['sapProfile']);
    }

    /**
     * Oils and butters in the additions phase that cannot share the lye
     * because they have no verified saponification data, such as workspace
     * oils that were not copied from a trusted platform ingredient.
     *
     * @param  array<int, array{phase_key: string, weight: float, ingredient: Ingredient|null}>  $additionContexts
     * @return array<int, string>
     */
    private function uncountedPostReactionOils(array $additionContexts): array
    {
        return collect($additionContexts)
            ->filter(fn (array $context): bool => $context['phase_key'] === 'additives'
                && $context['ingredient'] instanceof Ingredient
                && $context['ingredient']->category === IngredientCategory::Lipids
                && ! $context['ingredient']->canDriveSoapSaponification())
            ->map(fn (array $context): string => $context['ingredient']->localizedDisplayName() ?? $context['ingredient']->display_name)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     */
    private function previewRowWeight(array $row, array $payload): float
    {
        $explicitWeight = (float) ($row['weight'] ?? 0);

        if ($explicitWeight > 0) {
            return $explicitWeight;
        }

        $oilWeight = (float) ($payload['oil_weight'] ?? 0);
        $percentage = (float) ($row['percentage'] ?? 0);

        if ($oilWeight <= 0 || $percentage <= 0) {
            return 0;
        }

        return $oilWeight * ($percentage / 100);
    }
}
