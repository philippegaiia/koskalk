<?php

namespace App\Services;

use App\Models\Ingredient;

class RecipeWorkbenchPreviewService
{
    public function __construct(
        private readonly SoapCalculationService $soapCalculationService,
        private readonly InciGenerationService $inciGenerationService,
        private readonly RecipeWorkbenchDraftPayloadMapper $recipeWorkbenchDraftPayloadMapper,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function previewSoapCalculation(array $payload): ?array
    {
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

        return $this->soapCalculationService->calculate($oils, [
            'superfat' => (float) ($payload['superfat'] ?? 5),
            'lye_type' => $payload['lye_type'] ?? 'naoh',
            'dual_lye_koh_percentage' => (float) ($payload['dual_lye_koh_percentage'] ?? 40),
            'koh_purity_percentage' => (float) ($payload['koh_purity_percentage'] ?? 90),
            'water_mode' => $payload['water_mode'] ?? 'percent_of_oils',
            'water_value' => (float) ($payload['water_value'] ?? 38),
        ]);
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
     * @param  array<string, mixed>  $draft
     * @return array{draft: array<string, mixed>, calculation: array<string, mixed>|null, labeling: array<string, mixed>}
     */
    public function snapshotFromWorkbenchDraft(array $draft): array
    {
        $calculation = $this->calculationFromWorkbenchDraft($draft);

        return [
            'draft' => $draft,
            'calculation' => $calculation,
            'labeling' => $this->labelingFromWorkbenchDraft($draft, $calculation),
        ];
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
