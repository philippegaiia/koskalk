<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\User;
use App\SoapSap;
use Illuminate\Validation\ValidationException;
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

        foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
            $rows = data_get($payload, "phase_items.{$phaseKey}", []);
            $this->lyeLiquidIngredientValidator->assertMaximumRows(is_array($rows) ? $rows : [], "phase_items.{$phaseKey}");
        }

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
            'mass_precision' => SoapSap::calculationMassPrecision((string) ($payload['oil_unit'] ?? 'g')),
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
        } catch (ValidationException $exception) {
            $calculation['liquid_process'] = ['status' => 'invalid', 'message' => (string) collect($exception->errors())->flatten()->first()];
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
    public function previewLiquidSoapProcess(array $payload, array $calculation, bool $includeTemporaryAdditions = false): array
    {
        $paste = $this->liquidSoapProcessCalculator->pasteComposition($calculation, $payload['oil_unit'] ?? 'g');
        $liquidProcess = is_array($payload['liquid_process'] ?? null) ? $payload['liquid_process'] : [];
        $neutralizerRow = collect($payload['phase_items']['neutralization'] ?? [])
            ->first(fn (mixed $row): bool => is_array($row) && is_numeric($row['ingredient_id'] ?? null));
        $neutralizer = $this->liquidSoapFormulaProcessor->neutralizer(
            is_array($neutralizerRow) ? [...$neutralizerRow, 'weight' => $this->previewRowWeight($neutralizerRow, $payload)] : null,
            $liquidProcess,
        );
        $finalBatchWeight = $this->liquidSoapFormulaProcessor->finalBatchWeight($paste, $liquidProcess, $neutralizer);

        $additions = collect(RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES)
            ->flatMap(fn (string $phaseKey): array => collect($payload['phase_items'][$phaseKey] ?? [])
                ->filter(fn (mixed $row): bool => is_array($row) && (is_numeric($row['ingredient_id'] ?? null) || ($includeTemporaryAdditions && ($row['ingredient_id'] ?? null) === null)))
                ->map(fn (array $row): array => [
                    'id' => $row['id'] ?? null,
                    'phase_key' => $phaseKey,
                    'ingredient_id' => isset($row['ingredient_id']) ? (int) $row['ingredient_id'] : null,
                    'weight' => $this->liquidSoapFormulaProcessor->additionWeight($row, $finalBatchWeight, $this->previewRowWeight($row, $payload)),
                    'requested_final_percentage' => is_numeric($row['final_percentage'] ?? null) ? (float) $row['final_percentage'] : null,
                ])
                ->values()
                ->all())
            ->values();
        $additionsWeight = $additions->reduce(fn (string $total, array $addition): string => bcadd($total, $addition['weight'], 18), '0');

        $process = $this->liquidSoapFormulaProcessor->process($paste, $liquidProcess, $neutralizer, $additionsWeight);
        $achievedFinalWeight = $process['final_weight'];
        $dilutionLiquids = $process['status'] === 'ok'
            ? $this->liquidSoapFormulaProcessor->dilutionLiquids($process, $payload['phase_items'] ?? [])
            : null;

        if ($dilutionLiquids !== null) {
            $process = $this->withDilutionLiquids($process, $dilutionLiquids);
        }

        return [
            ...$process,
            'paste_composition' => $paste,
            'dilution_liquids' => $dilutionLiquids,
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
        $calculation ??= $this->previewSoapCalculation($payload);

        return $this->inciGenerationService->generate($this->withLiquidAdditionWeights($payload, $calculation), $calculation);
    }

    /**
     * Liquid soap additions weigh what the process sized from the final
     * batch, so labels and restrictions read the same weights.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    private function withLiquidAdditionWeights(array $payload, ?array $calculation): array
    {
        $process = $calculation['liquid_process'] ?? null;

        if (! is_array($process) || ($process['status'] ?? null) !== 'ok' || ! is_array($payload['phase_items'] ?? null)) {
            return $payload;
        }

        return [
            ...$payload,
            'phase_items' => $this->liquidSoapFormulaProcessor->withDilutionLiquidWeights(
                $this->liquidSoapFormulaProcessor->withAdditionWeights($payload['phase_items'], $process, $payload['oil_weight'] ?? 0),
                $process,
                $payload['oil_weight'] ?? 0,
            ),
        ];
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
        $calculation ??= $this->previewSoapCalculation($payload);

        return $this->substanceComplianceService->evaluate($this->withLiquidAdditionWeights($payload, $calculation), $calculation);
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
            ->filter(fn (array $context): bool => in_array($context['phase_key'], RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES, true)
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
            'phase_items' => collect(RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES)
                ->mapWithKeys(fn (string $phase): array => [$phase => $payload['phase_items'][$phase] ?? []])
                ->all(),
        ], ['sapProfile']);
    }

    /**
     * Fatty additions, including compound carriers, that cannot share the
     * alkali because their soap chemistry has not been verified.
     *
     * @param  array<int, array{phase_key: string, weight: float, ingredient: Ingredient|null}>  $additionContexts
     * @return array<int, string>
     */
    private function uncountedPostReactionOils(array $additionContexts): array
    {
        return collect($additionContexts)
            ->filter(fn (array $context): bool => in_array($context['phase_key'], RecipeWorkbenchPhaseBlueprints::ADDITION_PHASES, true)
                && $context['ingredient'] instanceof Ingredient
                && $context['ingredient']->mayCarrySoapChemistry()
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

    /**
     * The final composition with the dilution liquids out of the water, and
     * a warning for each step whose liquids end up unused.
     *
     * @param  array<string, mixed>  $process  Process result with status ok.
     * @param  array<string, array{water_weight: float, liquids: list<array<string, mixed>>}>  $dilutionLiquids
     * @return array<string, mixed>
     */
    private function withDilutionLiquids(array $process, array $dilutionLiquids): array
    {
        $liquidsWeight = collect($dilutionLiquids)
            ->flatMap(fn (array $step): array => $step['liquids'])
            ->reduce(fn (string $total, array $liquid): string => bcadd($total, (string) $liquid['weight'], 18), '0');
        $finalWeight = (string) $process['final_weight'];
        $share = fn (string $weight): string => bccomp($finalWeight, '0', 18) > 0
            ? $this->fourDecimals(bcdiv(bcmul($weight, '100', 18), $finalWeight, 18))
            : '0.0000';

        $process['composition']['water_weight'] = $this->fourDecimals(bcsub((string) $process['composition']['water_weight'], $liquidsWeight, 18));
        $process['composition']['dilution_liquids_weight'] = $this->fourDecimals($liquidsWeight);
        $process['composition_percentages']['water_weight'] = $share($process['composition']['water_weight']);
        $process['composition_percentages']['dilution_liquids_weight'] = $share($liquidsWeight);

        foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
            if ($dilutionLiquids[$phaseKey]['liquids'] !== [] && bccomp((string) data_get($process, "stages.{$phaseKey}.add_weight", '0'), '0', 4) <= 0) {
                $process['warnings'][] = "dilution_liquid_unused_{$phaseKey}";
            }
        }

        return $process;
    }

    private function fourDecimals(string $value): string
    {
        return bcadd(bcadd($value, '0.00005', 18), '0', 4);
    }
}
