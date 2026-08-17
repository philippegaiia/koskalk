<?php

namespace App\Services;

use App\Enums\MassUnit;
use App\Enums\ProductionOutputType;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\RegulatoryRegime;

class RecipeWorkbenchVersionPayloadMapper
{
    public function __construct(private readonly MassConverter $massConverter) {}

    /**
     * @param  array<int, array<string, mixed>>  $phaseBlueprints
     * @param  array<string, mixed>  $catalogReview
     * @param  array<int, array<string, mixed>>  $ingredientCatalogById
     * @return array<string, mixed>
     */
    public function toWorkbenchPayload(
        RecipeVersion $version,
        array $phaseBlueprints,
        array $catalogReview,
        array $ingredientCatalogById = [],
    ): array {
        $phaseRows = collect($phaseBlueprints)
            ->keyBy('key')
            ->map(fn (array $phase): array => [$phase['key'] => []])
            ->collapse()
            ->all();
        $phases = collect($phaseBlueprints)
            ->map(fn (array $phase): array => [
                'key' => $phase['key'],
                'name' => $phase['name'],
            ])
            ->keyBy('key')
            ->all();

        $version->phases
            ->sortBy('sort_order')
            ->each(function (RecipePhase $phase) use (&$phaseRows, &$phases, $ingredientCatalogById): void {
                $phaseRows[$phase->slug] = $phase->items
                    ->sortBy('position')
                    ->map(fn (RecipeItem $item): array => $this->mapItemToWorkbenchRow(
                        $item,
                        $ingredientCatalogById[$item->ingredient_id] ?? null,
                    ))
                    ->filter(fn (array $row): bool => $row['ingredient_id'] !== null)
                    ->values()
                    ->all();

                $phases[$phase->slug] = [
                    'key' => $phase->slug,
                    'name' => $phase->name,
                ];
            });

        /** @var array<string, mixed> $waterSettings */
        $waterSettings = $version->water_settings ?? [];
        /** @var array<string, mixed> $calculationContext */
        $calculationContext = $version->calculation_context ?? [];
        $lyeType = $calculationContext['lye_type'] ?? 'naoh';
        $waterMode = $waterSettings['mode'] ?? 'percent_of_oils';
        $displayUnit = $this->displayUnit($calculationContext['oil_unit'] ?? $version->batch_unit);
        $displayWeight = $version->batch_mass_grams === null
            ? (float) ($calculationContext['oil_weight'] ?? $version->batch_size)
            : (float) $this->massConverter->fromGrams($version->batch_mass_grams, $displayUnit);

        return [
            'recipe' => [
                'id' => $version->recipe_id,
                'current_version_id' => $version->id,
                'version_number' => $version->version_number,
                'is_current' => $version->is_current,
            ],
            'productTypeId' => $version->recipe?->product_type_id,
            'formulaName' => $version->name,
            'oilUnit' => $displayUnit->value,
            'oilWeight' => $displayWeight,
            'manufacturingMode' => in_array($version->manufacturing_mode, ['saponify_in_formula', 'blend_only'], true)
                ? $version->manufacturing_mode
                : 'saponify_in_formula',
            'manufacturingInstructions' => $version->manufacturing_instructions,
            'exposureMode' => in_array($version->exposure_mode, ['rinse_off', 'leave_on'], true)
                ? $version->exposure_mode
                : 'rinse_off',
            'regulatoryRegime' => RegulatoryRegime::normalizeCode(
                $version->regulatoryRegime?->code ?? $version->regulatory_regime,
            ),
            'editMode' => ($calculationContext['editing_mode'] ?? null) === 'weight' ? 'weight' : 'percentage',
            'lyeType' => in_array($lyeType, ['naoh', 'koh', 'dual'], true)
                ? $lyeType
                : 'naoh',
            'kohPurity' => (float) ($calculationContext['koh_purity_percentage'] ?? 90),
            'dualKohPercentage' => (float) ($calculationContext['dual_lye_koh_percentage'] ?? 40),
            'waterMode' => in_array($waterMode, ['percent_of_oils', 'lye_ratio', 'lye_concentration'], true)
                ? $waterMode
                : 'percent_of_oils',
            'waterValue' => (float) ($waterSettings['value'] ?? 38),
            'superfat' => (float) ($calculationContext['superfat'] ?? 5),
            'selectedIfraProductCategoryId' => $version->ifra_product_category_id,
            'finalIngredientList' => $version->final_ingredient_list,
            'finalIngredientListBasisHash' => $version->final_ingredient_list_basis_hash,
            'finalPlainIngredientList' => $version->final_plain_ingredient_list,
            'finalPlainIngredientListBasisHash' => $version->final_plain_ingredient_list_basis_hash,
            'phases' => array_values($phases),
            'phaseItems' => $phaseRows,
            'packagingItems' => $version->packagingItems
                ->sortBy('position')
                ->map(fn (RecipeVersionPackagingItem $item): array => [
                    'id' => 'saved-packaging-'.$item->id,
                    'packaging_item_id' => $item->packaging_item_id,
                    'name' => $item->name,
                    'components_per_unit' => (float) $item->components_per_unit,
                    'notes' => $item->notes,
                ])
                ->values()
                ->all(),
            'productionOutputType' => ($version->recipe?->production_output_type ?? ProductionOutputType::FinishedProduct)->value,
            'outputIngredientId' => $version->recipe?->output_ingredient_id,
            'readyDelayDays' => $version->recipe?->ready_delay_days,
            'productReference' => $version->recipe?->product_reference,
            'nominalContentValue' => $version->recipe?->nominal_content_value === null
                ? null
                : (float) $version->recipe->nominal_content_value,
            'nominalContentUnit' => $version->recipe?->nominal_content_unit?->value,
            'catalogReview' => $catalogReview,
        ];
    }

    private function displayUnit(mixed $value): MassUnit
    {
        try {
            return MassUnit::fromInput($value);
        } catch (\InvalidArgumentException) {
            return MassUnit::Gram;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItemToWorkbenchRow(RecipeItem $item, ?array $catalogIngredient = null): array
    {
        $ingredient = $catalogIngredient === null ? $item->ingredient : null;
        $sapProfile = $ingredient?->sapProfile;

        return [
            'id' => 'saved-'.$item->id,
            'ingredient_id' => $item->ingredient_id,
            'name' => $catalogIngredient['name'] ?? $ingredient?->display_name,
            'is_user_owned' => $catalogIngredient['is_user_owned'] ?? $ingredient?->owner_type !== null,
            'inci_name' => $catalogIngredient['inci_name'] ?? $ingredient?->inci_name,
            'category' => $catalogIngredient['category'] ?? $ingredient?->category?->value,
            'soap_inci_naoh_name' => $catalogIngredient['soap_inci_naoh_name'] ?? $ingredient?->soap_inci_naoh_name,
            'soap_inci_koh_name' => $catalogIngredient['soap_inci_koh_name'] ?? $ingredient?->soap_inci_koh_name,
            'koh_sap_value' => $catalogIngredient['koh_sap_value']
                ?? ($sapProfile?->koh_sap_value === null ? null : (float) $sapProfile->koh_sap_value),
            'naoh_sap_value' => $catalogIngredient['naoh_sap_value'] ?? $sapProfile?->naoh_sap_value,
            'fatty_acid_profile' => $catalogIngredient['fatty_acid_profile']
                ?? $ingredient?->normalizedFattyAcidProfile()
                ?? [],
            'percentage' => (float) $item->percentage,
            'weight' => (float) $item->weight,
            'note' => $item->note,
        ];
    }
}
