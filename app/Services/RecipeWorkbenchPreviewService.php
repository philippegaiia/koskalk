<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use InvalidArgumentException;

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
        private readonly LiquidSoapProcessCalculator $liquidSoapProcessCalculator,
        private readonly LiquidSoapFormulaProcessor $liquidSoapFormulaProcessor,
    ) {}

    /**
     * Without post-reaction oils, saponifiable additions take no lye: the
     * liquid soap paste is neutralized before anything is added to it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewSoapCalculation(
        array $payload,
        ?User $user = null,
        bool $validateLyeIngredients = true,
        bool $enforceSuperfatFloors = true,
        bool $includePostReactionOils = true,
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
        $postReactionOils = $includePostReactionOils ? $this->postReactionOils($additionContexts) : [];

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
     * Paste-only calculation of a liquid soap formula with its process under
     * liquid_process. Settings that cannot be made report there as invalid
     * while the paste numbers stay available.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewLiquidSoapCalculation(
        array $payload,
        ?User $user = null,
        bool $validateLyeIngredients = true,
        bool $enforceSuperfatFloors = true,
    ): ?array {
        $calculation = $this->previewSoapCalculation(
            $payload,
            $user,
            $validateLyeIngredients,
            $enforceSuperfatFloors,
            includePostReactionOils: false,
        );

        if ($calculation === null) {
            return null;
        }

        try {
            $calculation['liquid_process'] = $this->previewLiquidSoapProcess($payload, $calculation);
        } catch (InvalidArgumentException $exception) {
            $calculation['liquid_process'] = ['status' => 'invalid', 'message' => $exception->getMessage()];
        }

        return $calculation;
    }

    /**
     * Liquid soap paste process for a KOH paste calculation: stages,
     * neutralizer, composition, and each addition as weight and achieved %
     * of the final batch. Additions sent with a final_percentage take their
     * weight from it, as a share of the target batch, and report it back as
     * requested_final_percentage.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $calculation  Output of previewSoapCalculation().
     * @return array<string, mixed>
     */
    public function previewLiquidSoapProcess(array $payload, array $calculation): array
    {
        $paste = $this->liquidSoapProcessCalculator->pasteComposition($calculation);
        $liquidProcess = is_array($payload['liquid_process'] ?? null) ? $payload['liquid_process'] : [];
        $neutralizerRow = collect($payload['phase_items']['neutralization'] ?? [])
            ->first(fn (mixed $row): bool => is_array($row) && is_numeric($row['ingredient_id'] ?? null));
        $neutralizer = $this->liquidSoapFormulaProcessor->neutralizer(
            is_array($neutralizerRow) ? [...$neutralizerRow, 'weight' => $this->previewRowWeight($neutralizerRow, $payload)] : null,
            $liquidProcess,
        );
        $finalBatchWeight = $this->liquidSoapFormulaProcessor->finalBatchWeight($paste, $liquidProcess, $neutralizer);

        $additions = collect(['additives', 'fragrance'])
            ->flatMap(fn (string $phaseKey): array => collect($payload['phase_items'][$phaseKey] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row) && is_numeric($row['ingredient_id'] ?? null))
                ->map(fn (array $row): array => [
                    'id' => $row['id'] ?? null,
                    'phase_key' => $phaseKey,
                    'ingredient_id' => (int) $row['ingredient_id'],
                    'weight' => $this->liquidSoapFormulaProcessor->additionWeight($row, $finalBatchWeight, $this->previewRowWeight($row, $payload)),
                    'requested_final_percentage' => is_numeric($row['final_percentage'] ?? null) ? (float) $row['final_percentage'] : null,
                ])
                ->values()
                ->all())
            ->values();
        $additionsWeight = $additions->reduce(fn (string $total, array $addition): string => bcadd($total, $addition['weight'], 18), '0');

        $process = $this->liquidSoapFormulaProcessor->process($paste, $liquidProcess, $neutralizer, $additionsWeight);
        $achievedFinalWeight = $process['final_weight'];

        return [
            ...$process,
            'paste_composition' => $paste,
            'target_final_weight' => $finalBatchWeight === null ? null : $this->fourDecimals($finalBatchWeight),
            'additions' => $additions
                ->map(fn (array $addition): array => [
                    ...$addition,
                    'weight' => $this->fourDecimals($addition['weight']),
                    'final_percentage' => $achievedFinalWeight === null || bccomp($achievedFinalWeight, '0', 4) <= 0
                        ? null
                        : $this->fourDecimals(bcdiv(bcmul($addition['weight'], '100', 18), $achievedFinalWeight, 18)),
                ])
                ->all(),
        ];
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
        // Only liquid soap paste versions are saved with liquid settings.
        $calculation = is_array($previewPayload['liquid_process'] ?? null)
            ? $this->previewLiquidSoapCalculation(
                $previewPayload,
                validateLyeIngredients: $validateLyeIngredients,
                enforceSuperfatFloors: $validateLyeIngredients,
            )
            : $this->previewSoapCalculation(
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

    private function fourDecimals(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', 18), '0', 4);
    }
}
