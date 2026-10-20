<?php

use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientSapProfile;
use App\Models\IngredientSubstanceEntry;
use App\Models\ProductFamily;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Reference liquid soap: 1000 g olive-type oil (KOH SAP 0.19) at -3%
 * superfat, KOH 90%, 28% alkali solution, diluted to 35% then 25%, with
 * 6.5062 g of citric acid as a 20% solution: a 4760 g finished batch.
 */

/**
 * @return array{payload: array<string, mixed>, family: ProductFamily, neutralizer: Ingredient}
 */
function liquidSoapLabelFormula(LiquidSoapNeutralizer $neutralizerType = LiquidSoapNeutralizer::CitricAcidAnhydrous): array
{
    $oil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);

    $neutralizer = $neutralizerType === LiquidSoapNeutralizer::FattyAcid
        ? Ingredient::factory()->create([
            'category' => IngredientCategory::Lipids,
            'display_name' => 'Stearic Acid',
            'inci_name' => 'STEARIC ACID',
            'soap_inci_koh_name' => 'POTASSIUM STEARATE',
            'owner_type' => null,
            'owner_id' => null,
            'is_soap_saponification_trusted' => true,
            'is_active' => true,
            'liquid_soap_neutralizer' => $neutralizerType,
        ])
        : Ingredient::factory()->create([
            'category' => IngredientCategory::PhAdjustersBuffers,
            'display_name' => 'Citric Acid',
            'inci_name' => 'CITRIC ACID',
            'owner_type' => null,
            'owner_id' => null,
            'is_active' => true,
            'liquid_soap_neutralizer' => $neutralizerType,
        ]);

    if ($neutralizerType === LiquidSoapNeutralizer::FattyAcid) {
        IngredientSapProfile::factory()->create(['ingredient_id' => $neutralizer->id, 'koh_sap_value' => 0.1972]);
    }

    $neutralizerWeight = $neutralizerType === LiquidSoapNeutralizer::FattyAcid ? 28.9047 : 6.5062;

    return [
        'family' => ProductFamily::factory()->liquidSoapPaste()->create(),
        'neutralizer' => $neutralizer,
        'payload' => [
            'oil_weight' => 1000,
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'none',
            'editing_mode' => 'percentage',
            'lye_type' => 'koh',
            'koh_purity_percentage' => 90,
            'water_mode' => 'lye_concentration',
            'water_value' => 28,
            'superfat' => -3,
            'liquid_process' => [
                'first_dilution_percentage' => 35,
                'final_dilution_percentage' => 25,
                'neutralizer_solution_percentage' => 20,
            ],
            'phase_items' => [
                'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000]],
                'neutralization' => [['ingredient_id' => $neutralizer->id, 'percentage' => $neutralizerWeight / 10, 'weight' => $neutralizerWeight]],
                'additives' => [],
                'fragrance' => [],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $labeling
 * @return array<string, float>
 */
function liquidSoapLabelWeights(array $labeling, string $variantKey): array
{
    return collect(collect($labeling['list_variants'])->firstWhere('key', $variantKey)['ingredient_rows'])
        ->mapWithKeys(fn (array $row): array => [$row['label'] => round($row['weight'], 2)])
        ->all();
}

/**
 * @return array<string, mixed>
 */
function liquidSoapLabeling(array $formula): array
{
    $service = app(RecipeWorkbenchService::class);
    $calculation = $service->previewSoapCalculation($formula['payload'], null, $formula['family']);

    return $service->previewInci($formula['payload'], $calculation);
}

it('bases liquid soap label percentages on the finished batch without a cure', function () {
    $labeling = liquidSoapLabeling(liquidSoapLabelFormula());

    expect($labeling['basis']['formula_weight'])->toBe(4760.0)
        ->and($labeling['basis']['cured_weight'])->toBeNull();
});

it('lists what is in the bottle in the saponified list, with potassium citrate for citric acid', function () {
    $weights = liquidSoapLabelWeights(liquidSoapLabeling(liquidSoapLabelFormula()), 'saponified_with_superfat');

    expect(array_keys($weights))->toBe(['AQUA', 'POTASSIUM OLIVATE', 'GLYCERIN', 'POTASSIUM CITRATE'])
        ->and($weights['POTASSIUM CITRATE'])->toBe(10.38)
        ->and($weights['GLYCERIN'])->toBe(103.96)
        // The KOH flakes' non-KOH share (mostly water) is part of AQUA.
        ->and(array_sum($weights))->toEqualWithDelta(4760.0, 0.05);
});

it('lists what was weighed in the incorporated list, with citric acid and all added water', function () {
    $weights = liquidSoapLabelWeights(liquidSoapLabeling(liquidSoapLabelFormula()), 'incorporated_ingredients');

    expect($weights['CITRIC ACID'])->toBe(6.51)
        ->and($weights['POTASSIUM HYDROXIDE'])->toBe(217.44)
        ->and($weights['AQUA'])->toBe(round(503.2286 + 1679.327 + 26.0248 + 1327.469, 2))
        ->and(array_key_exists('POTASSIUM CITRATE', $weights))->toBeFalse();
});

it('lists a stearic acid neutralizer as its potassium soap in the saponified list', function () {
    $formula = liquidSoapLabelFormula(LiquidSoapNeutralizer::FattyAcid);

    $labeling = liquidSoapLabeling($formula);
    $saponified = liquidSoapLabelWeights($labeling, 'saponified_with_superfat');
    $incorporated = liquidSoapLabelWeights($labeling, 'incorporated_ingredients');

    expect($labeling['basis']['formula_weight'])->toBe(4891.0978)
        ->and($saponified['POTASSIUM STEARATE'])->toBe(32.77)
        ->and(array_key_exists('STEARIC ACID', $saponified))->toBeFalse()
        ->and($incorporated['STEARIC ACID'])->toBe(28.9);
});

it('judges fragrance allergens against the finished liquid soap batch', function () {
    $formula = liquidSoapLabelFormula();
    $lavender = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $linalool = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $lavender->id, 'allergen_id' => $linalool->id, 'concentration_percent' => 50, 'source_notes' => null]);
    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active'])->id,
        'allergen_id' => $linalool->id,
        'rinse_off_threshold_percent' => 0.01,
        'leave_on_threshold_percent' => 0.001,
        'threshold_operator' => 'greater_than_or_equal',
        'is_active' => true,
    ]);
    $formula['payload']['regulatory_regime'] = 'eu';
    $formula['payload']['phase_items']['fragrance'] = [['ingredient_id' => $lavender->id, 'percentage' => 0.06, 'weight' => 0.6]];

    $row = collect(liquidSoapLabeling($formula)['declaration_rows'])->firstWhere('label', 'LINALOOL');

    expect($row['percent_of_formula'])->toEqualWithDelta(0.3 * 100 / 4760, 0.00005)
        ->and($row['exceeds_threshold'])->toBeFalse()
        ->and($row['included_in_inci'])->toBeFalse();
});

it('labels additions with the weight the process sized from the final batch', function () {
    $formula = liquidSoapLabelFormula();
    $lavender = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $formula['payload']['liquid_process']['final_dilution_percentage'] = 20;
    // Weighed for the 25% batch before the target changed to 20%.
    $formula['payload']['phase_items']['fragrance'] = [['id' => 'lavender', 'ingredient_id' => $lavender->id, 'percentage' => 4.76, 'weight' => 47.6, 'final_percentage' => 1]];

    $weights = liquidSoapLabelWeights(liquidSoapLabeling($formula), 'incorporated_ingredients');

    expect($weights['LAVANDULA ANGUSTIFOLIA OIL'])->toBe(59.5);
});

it('reports finished liquid free fat after neutralization and final dilution', function (float $finalDilution, float $addedWeight, float $expectedPercentage, string $phase): void {
    $formula = liquidSoapLabelFormula();
    $addition = Ingredient::factory()->create([
        'category' => IngredientCategory::Waxes,
        'inci_name' => 'SIMMONDSIA CHINENSIS SEED OIL',
        'is_soap_saponification_trusted' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $addition->id, 'koh_sap_value' => 0.09]);
    $formula['payload']['liquid_process']['final_dilution_percentage'] = $finalDilution;
    $formula['payload']['phase_items'][$phase] = [['ingredient_id' => $addition->id, 'percentage' => $addedWeight / 10, 'weight' => $addedWeight]];

    $labeling = liquidSoapLabeling($formula);

    expect($labeling['free_fat']['basis'])->toBe('finished_liquid_soap')
        ->and($labeling['free_fat']['weight'])->toEqualWithDelta($addedWeight, 0.0001)
        ->and($labeling['free_fat']['percentage'])->toEqualWithDelta($expectedPercentage, 0.0001);
})->with([
    'no additions after neutralization' => [25.0, 0.0, 0.0],
    'wax at twenty five percent soap' => [25.0, 47.6, 1.0],
    'same wax diluted further' => [20.0, 47.6, 0.8],
])->with(['additives', 'fragrance']);

it('does not report finished free fat before the final liquid batch can be calculated', function (): void {
    $formula = liquidSoapLabelFormula();
    $formula['payload']['liquid_process']['final_dilution_percentage'] = null;

    $labeling = liquidSoapLabeling($formula);

    expect($labeling['free_fat'])->toBeNull();
});

it('withholds the finished free fat estimate while excess KOH remains', function (float $neutralizerWeight): void {
    $formula = liquidSoapLabelFormula();
    $addition = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
    ]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $addition->id, 'koh_sap_value' => 0.19]);
    $formula['payload']['phase_items']['additives'] = [['ingredient_id' => $addition->id, 'percentage' => 6, 'weight' => 60]];
    $formula['payload']['phase_items']['neutralization'] = $neutralizerWeight > 0
        ? [['ingredient_id' => $formula['neutralizer']->id, 'percentage' => $neutralizerWeight / 10, 'weight' => $neutralizerWeight]]
        : [];

    $calculation = app(RecipeWorkbenchService::class)->previewSoapCalculation($formula['payload'], null, $formula['family']);
    $labeling = app(RecipeWorkbenchService::class)->previewInci($formula['payload'], $calculation);

    expect($calculation['liquid_process']['warnings'])->toContain('free_koh_remains')
        ->and($labeling['free_fat']['nominal_percentage'])->toBe(3.0)
        ->and($labeling['free_fat']['percentage'])->toBeNull()
        ->and($labeling['free_fat']['weight'])->toBeNull()
        ->and($labeling['free_fat']['unavailable_reason'])->toBe('free_koh_remains');
})->with([
    'no neutralizer' => 0.0,
    'insufficient neutralizer' => 3.0,
]);

it('includes excess fatty acid neutralizer in finished free fat without counting the reacted dose', function (): void {
    $formula = liquidSoapLabelFormula(LiquidSoapNeutralizer::FattyAcid);
    $formula['payload']['phase_items']['neutralization'][0]['weight'] = 38.9047;
    $formula['payload']['phase_items']['neutralization'][0]['percentage'] = 3.89047;

    $labeling = liquidSoapLabeling($formula);

    expect($labeling['free_fat']['weight'])->toEqualWithDelta(10, 0.001)
        ->and($labeling['free_fat']['nominal_percentage'])->toBe(-3.0)
        ->and($labeling['free_fat']['percentage'])->toEqualWithDelta(10 / $labeling['basis']['formula_weight'] * 100, 0.0001);
});

it('shares a compound neutralizer product between its components', function () {
    $formula = liquidSoapLabelFormula(LiquidSoapNeutralizer::FattyAcid);
    $blend = $formula['neutralizer'];
    $palmitic = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'inci_name' => 'PALMITIC ACID', 'soap_inci_koh_name' => 'POTASSIUM PALMITATE', 'is_active' => true]);
    $stearic = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'inci_name' => 'STEARIC ACID', 'soap_inci_koh_name' => 'POTASSIUM STEARATE', 'is_active' => true]);
    $blend->update(['inci_name' => 'STEARIC ACID, PALMITIC ACID']);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $stearic->id, 'percentage_in_parent' => 50, 'sort_order' => 1]);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $palmitic->id, 'percentage_in_parent' => 50, 'sort_order' => 2]);

    $weights = liquidSoapLabelWeights(liquidSoapLabeling($formula), 'saponified_with_superfat');

    expect($weights['POTASSIUM STEARATE'])->toBe(16.39)
        ->and($weights['POTASSIUM PALMITATE'])->toBe(16.39);
});

it('lists a dilution liquid at its process weight and keeps it out of AQUA', function () {
    $formula = liquidSoapLabelFormula();
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', 'is_active' => true]);
    $formula['payload']['phase_items']['final_dilution'] = [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0]];
    $labeling = liquidSoapLabeling($formula);
    $hydrosolWeight = round(1327.469 * 0.3, 2);

    $saponified = liquidSoapLabelWeights($labeling, 'saponified_with_superfat');
    $incorporated = liquidSoapLabelWeights($labeling, 'incorporated_ingredients');

    expect($saponified['LAVANDULA ANGUSTIFOLIA FLOWER WATER'])->toBe($hydrosolWeight)
        ->and(array_sum($saponified))->toEqualWithDelta(4760.0, 0.05)
        ->and($incorporated['LAVANDULA ANGUSTIFOLIA FLOWER WATER'])->toBe($hydrosolWeight)
        ->and($incorporated['AQUA'])->toEqualWithDelta(503.2286 + 1679.327 + 26.0248 + 1327.469 - 1327.469 * 0.3, 0.02)
        ->and(array_sum($incorporated))->toEqualWithDelta(4760.0, 0.05);
});

it('leaves out a dilution liquid whose step adds no water', function () {
    $formula = liquidSoapLabelFormula();
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', 'is_active' => true]);
    $formula['payload']['liquid_process']['first_dilution_percentage'] = 80;
    $formula['payload']['phase_items']['first_dilution'] = [['ingredient_id' => $hydrosol->id, 'percentage' => 50, 'weight' => 0]];
    $labeling = liquidSoapLabeling($formula);

    foreach (['saponified_with_superfat', 'incorporated_ingredients'] as $variant) {
        expect(liquidSoapLabelWeights($labeling, $variant))->not->toHaveKey('LAVANDULA ANGUSTIFOLIA FLOWER WATER');
    }

    expect(array_sum(liquidSoapLabelWeights($labeling, 'incorporated_ingredients')))->toEqualWithDelta($labeling['basis']['formula_weight'], 0.05);
});

it('checks restricted constituents of a dilution liquid against the finished batch', function () {
    $formula = liquidSoapLabelFormula();
    $formula['payload']['regulatory_regime'] = 'eu';
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Mint hydrosol', 'is_active' => true]);
    $constituent = Substance::factory()->create(['name' => 'Pulegone', 'entity_type' => 'constituent']);
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu', 'status' => 'active']);
    IngredientSubstanceEntry::factory()->for($hydrosol, 'ingredient')->for($constituent, 'substance')->create(['concentration_percent' => 30, 'concentration_source' => 'supplier']);
    RegulatoryRegimeSubstanceRule::factory()->for($regime, 'regulatoryRegime')->for($constituent, 'substance')->create(['rule_type' => 'restricted', 'rinse_off_max_percent' => 5, 'leave_on_max_percent' => 1, 'is_active' => true]);
    $formula['payload']['phase_items']['final_dilution'] = [['ingredient_id' => $hydrosol->id, 'percentage' => 100, 'weight' => 0]];

    $service = app(RecipeWorkbenchService::class);
    $calculation = $service->previewSoapCalculation($formula['payload'], null, $formula['family']);
    $restrictions = $service->previewRestrictions($formula['payload'], $calculation);
    $row = collect($restrictions['rows'])->firstWhere('substance_name', 'Pulegone');

    expect($restrictions['basis']['formula_weight'])->toBe(4760.0)
        ->and($row['percent_of_formula'])->toEqualWithDelta(1327.469 * 0.3 / 4760 * 100, 0.001)
        ->and($row['status'])->toBe('over_limit');
});

it('reflects acid conversion in the bottled list while keeping the incorporated dose', function () {
    $formula = liquidSoapLabelFormula();
    $formula['payload']['phase_items']['neutralization'][0]['weight'] = 10;
    $formula['payload']['phase_items']['neutralization'][0]['percentage'] = 1;
    $service = app(RecipeWorkbenchService::class);
    $calculation = $service->previewSoapCalculation($formula['payload'], null, $formula['family']);

    $labeling = $service->previewInci($formula['payload'], $calculation);
    $rows = collect(collect($labeling['list_variants'])->firstWhere('key', 'saponified_with_superfat')['ingredient_rows']);
    $weights = liquidSoapLabelWeights($labeling, 'saponified_with_superfat');

    expect($weights['POTASSIUM OLIVATE'])->toBe(round((float) $calculation['liquid_process']['composition']['soap_salt_weight'], 2))
        ->and($rows->firstWhere('kind', 'derived_fatty_acids')['weight'])->toEqualWithDelta((float) $calculation['liquid_process']['composition']['free_fatty_acid_weight'], 0.001)
        ->and($rows->sum('weight'))->toEqualWithDelta($labeling['basis']['formula_weight'], 0.001)
        ->and($labeling['warnings'])->toContain(__('workbench.liquid.fatty_acid_label_review'))
        ->and(liquidSoapLabelWeights($labeling, 'incorporated_ingredients')['CITRIC ACID'])->toBe(10.0);
});

it('keeps unreacted acid in the bottled list after all available soap is converted', function () {
    $formula = liquidSoapLabelFormula();
    $formula['payload']['phase_items']['neutralization'][0]['weight'] = 1000;
    $formula['payload']['phase_items']['neutralization'][0]['percentage'] = 100;

    $labeling = liquidSoapLabeling($formula);
    $weights = liquidSoapLabelWeights($labeling, 'saponified_with_superfat');

    expect($weights)->not->toHaveKey('POTASSIUM OLIVATE')
        ->and($weights['CITRIC ACID'])->toBeGreaterThan(0)
        ->and(array_sum($weights))->toEqualWithDelta($labeling['basis']['formula_weight'], 0.05);
});
