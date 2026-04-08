<?php

namespace App\Services;

use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;

class RecipeWorkbenchVersionPayloadMapper
{
    /**
     * @param  array<int, array<string, mixed>>  $phaseBlueprints
     * @param  array<string, mixed>  $catalogReview
     * @return array<string, mixed>
     */
    public function toWorkbenchPayload(RecipeVersion $version, array $phaseBlueprints, array $catalogReview): array
    {
        $phaseRows = collect($phaseBlueprints)
            ->keyBy('key')
            ->map(fn (array $phase): array => [$phase['key'] => []])
            ->collapse()
            ->all();

        $version->phases
            ->sortBy('sort_order')
            ->each(function (RecipePhase $phase) use (&$phaseRows): void {
                $phaseRows[$phase->slug] = $phase->items
                    ->sortBy('position')
                    ->map(fn (RecipeItem $item): array => $this->mapItemToWorkbenchRow($item))
                    ->filter(fn (array $row): bool => $row['ingredient_id'] !== null)
                    ->values()
                    ->all();
            });

        /** @var array<string, mixed> $waterSettings */
        $waterSettings = $version->water_settings ?? [];
        /** @var array<string, mixed> $calculationContext */
        $calculationContext = $version->calculation_context ?? [];

        return [
            'recipe' => [
                'id' => $version->recipe_id,
                'draft_version_id' => $version->id,
                'version_number' => $version->version_number,
                'is_draft' => $version->is_draft,
            ],
            'formulaName' => $version->name,
            'oilUnit' => (string) ($calculationContext['oil_unit'] ?? $version->batch_unit),
            'oilWeight' => (float) ($calculationContext['oil_weight'] ?? $version->batch_size),
            'manufacturingMode' => in_array($version->manufacturing_mode, ['saponify_in_formula', 'blend_only'], true)
                ? $version->manufacturing_mode
                : 'saponify_in_formula',
            'exposureMode' => in_array($version->exposure_mode, ['rinse_off', 'leave_on'], true)
                ? $version->exposure_mode
                : 'rinse_off',
            'regulatoryRegime' => in_array($version->regulatory_regime, ['eu'], true)
                ? $version->regulatory_regime
                : 'eu',
            'editMode' => ($calculationContext['editing_mode'] ?? null) === 'weight' ? 'weight' : 'percentage',
            'lyeType' => in_array($calculationContext['lye_type'] ?? 'naoh', ['naoh', 'koh', 'dual'], true)
                ? $calculationContext['lye_type']
                : 'naoh',
            'kohPurity' => (float) ($calculationContext['koh_purity_percentage'] ?? 90),
            'dualKohPercentage' => (float) ($calculationContext['dual_lye_koh_percentage'] ?? 40),
            'waterMode' => in_array($waterSettings['mode'] ?? 'percent_of_oils', ['percent_of_oils', 'lye_ratio', 'lye_concentration'], true)
                ? $waterSettings['mode']
                : 'percent_of_oils',
            'waterValue' => (float) ($waterSettings['value'] ?? 38),
            'superfat' => (float) ($calculationContext['superfat'] ?? 5),
            'selectedIfraProductCategoryId' => $version->ifra_product_category_id,
            'phaseItems' => $phaseRows,
            'catalogReview' => $catalogReview,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItemToWorkbenchRow(RecipeItem $item): array
    {
        $ingredient = $item->ingredient;
        $sapProfile = $ingredient?->sapProfile;

        return [
            'id' => 'saved-'.$item->id,
            'ingredient_id' => $item->ingredient_id,
            'name' => $ingredient?->display_name,
            'inci_name' => $ingredient?->inci_name,
            'category' => $ingredient?->category?->value,
            'soap_inci_naoh_name' => $ingredient?->soap_inci_naoh_name,
            'soap_inci_koh_name' => $ingredient?->soap_inci_koh_name,
            'koh_sap_value' => $sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value,
            'naoh_sap_value' => $sapProfile?->naoh_sap_value,
            'fatty_acid_profile' => $ingredient?->normalizedFattyAcidProfile() ?? [],
            'percentage' => (float) $item->percentage,
            'note' => $item->note,
        ];
    }
}
