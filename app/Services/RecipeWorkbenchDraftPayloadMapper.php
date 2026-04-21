<?php

namespace App\Services;

class RecipeWorkbenchDraftPayloadMapper
{
    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function toPreviewPayload(array $draft): array
    {
        return [
            'manufacturing_mode' => $draft['manufacturingMode'] ?? 'saponify_in_formula',
            'exposure_mode' => $draft['exposureMode'] ?? 'rinse_off',
            'regulatory_regime' => $draft['regulatoryRegime'] ?? 'eu',
            'product_type_id' => $draft['productTypeId'] ?? null,
            'oil_weight' => $draft['oilWeight'] ?? 0,
            'lye_type' => $draft['lyeType'] ?? 'naoh',
            'koh_purity_percentage' => $draft['kohPurity'] ?? 90,
            'dual_lye_koh_percentage' => $draft['dualKohPercentage'] ?? 40,
            'water_mode' => $draft['waterMode'] ?? 'percent_of_oils',
            'water_value' => $draft['waterValue'] ?? 38,
            'superfat' => $draft['superfat'] ?? 5,
            'phase_items' => $draft['phaseItems'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function toSavePayload(array $draft): array
    {
        return [
            'name' => $draft['formulaName'] ?? 'Untitled Soap Formula',
            'oil_unit' => $draft['oilUnit'] ?? 'g',
            'oil_weight' => $draft['oilWeight'] ?? 0,
            'manufacturing_mode' => $draft['manufacturingMode'] ?? 'saponify_in_formula',
            'exposure_mode' => $draft['exposureMode'] ?? 'rinse_off',
            'regulatory_regime' => $draft['regulatoryRegime'] ?? 'eu',
            'product_type_id' => $draft['productTypeId'] ?? null,
            'editing_mode' => ($draft['editMode'] ?? 'percentage') === 'weight' ? 'weight' : 'percentage',
            'lye_type' => $draft['lyeType'] ?? 'naoh',
            'koh_purity_percentage' => $draft['kohPurity'] ?? 90,
            'dual_lye_koh_percentage' => $draft['dualKohPercentage'] ?? 40,
            'water_mode' => $draft['waterMode'] ?? 'percent_of_oils',
            'water_value' => $draft['waterValue'] ?? 38,
            'superfat' => $draft['superfat'] ?? 5,
            'ifra_product_category_id' => $draft['selectedIfraProductCategoryId'] ?? null,
            'phases' => $draft['phases'] ?? $this->phasesFromItems($draft['phaseItems'] ?? []),
            'phase_items' => $draft['phaseItems'] ?? [],
            'packaging_items' => $draft['packagingItems'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $phaseItems
     * @return array<int, array{key: string, name: string}>
     */
    private function phasesFromItems(array $phaseItems): array
    {
        return collect($phaseItems)
            ->keys()
            ->filter(fn (mixed $phaseKey): bool => is_string($phaseKey))
            ->map(fn (string $phaseKey): array => [
                'key' => $phaseKey,
                'name' => str($phaseKey)->replace('_', ' ')->title()->toString(),
            ])
            ->values()
            ->all();
    }
}
