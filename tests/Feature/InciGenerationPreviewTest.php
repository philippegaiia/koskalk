<?php

use App\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Services\RecipeWorkbenchService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a generated ingredient list and declaration details in the live preview', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);

    $linalool = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);
    $limonene = Allergen::factory()->create([
        'inci_name' => 'LIMONENE',
    ]);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $linalool->id,
        'concentration_percent' => 50,
        'source_notes' => null,
    ]);
    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $limonene->id,
        'concentration_percent' => 0.5,
        'source_notes' => null,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        soapDraftPayloadWithFragrance($oliveOil, $lavenderOil),
        app(RecipeWorkbenchService::class),
    );

    $declarationRows = collect($result['labeling']['declaration_rows'])->keyBy('label');

    expect($result['ok'])->toBeTrue()
        ->and($result['labeling']['final_labels'])->toContain(
            'SODIUM OLIVATE',
            'AQUA',
            'GLYCERIN',
            'LAVANDULA ANGUSTIFOLIA OIL',
            'LINALOOL',
        )
        ->and($result['labeling']['final_labels'])->not->toContain('LIMONENE')
        ->and($declarationRows['LINALOOL']['included_in_inci'])->toBeTrue()
        ->and($declarationRows['LINALOOL']['status_label'])->toBe('Added to INCI')
        ->and($declarationRows['LIMONENE']['included_in_inci'])->toBeFalse()
        ->and($declarationRows['LIMONENE']['status_label'])->toBe('Below threshold');
});

it('suppresses duplicate declaration rows when the ingredient list already names the same label', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $lavenderDeclaration = Allergen::factory()->create([
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
    ]);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $lavenderDeclaration->id,
        'concentration_percent' => 100,
        'source_notes' => null,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        soapDraftPayloadWithFragrance($oliveOil, $lavenderOil),
        app(RecipeWorkbenchService::class),
    );

    $duplicateLabels = array_values(array_filter(
        $result['labeling']['final_labels'],
        fn (string $label): bool => $label === 'LAVANDULA ANGUSTIFOLIA OIL',
    ));
    $lavenderRow = collect($result['labeling']['declaration_rows'])
        ->firstWhere('label', 'LAVANDULA ANGUSTIFOLIA OIL');

    expect($duplicateLabels)->toHaveCount(1)
        ->and($lavenderRow['included_in_inci'])->toBeFalse()
        ->and($lavenderRow['suppressed_by_existing_label'])->toBeTrue()
        ->and($lavenderRow['status_label'])->toBe('Already named');
});

it('replaces an explicit ingredient label with the grouped eu declaration label when mapped at 100%', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $lavenderGroupedDeclaration = Allergen::factory()->create([
        'inci_name' => 'LAVANDULA OIL/EXTRACT',
    ]);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $lavenderGroupedDeclaration->id,
        'concentration_percent' => 100,
        'source_notes' => null,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        soapDraftPayloadWithFragrance($oliveOil, $lavenderOil),
        app(RecipeWorkbenchService::class),
    );

    $lavenderRow = collect($result['labeling']['declaration_rows'])
        ->firstWhere('label', 'LAVANDULA OIL/EXTRACT');

    expect($result['labeling']['final_labels'])->toContain('LAVANDULA OIL/EXTRACT')
        ->and($result['labeling']['final_labels'])->not->toContain('LAVANDULA ANGUSTIFOLIA OIL')
        ->and($lavenderRow['included_in_inci'])->toBeFalse()
        ->and($lavenderRow['suppressed_by_existing_label'])->toBeTrue()
        ->and($lavenderRow['status_label'])->toBe('Already named');
});

it('expands composite aromatics into child inci rows and child-derived declarations', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $orangeOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Orange Essential Oil',
        'inci_name' => 'CITRUS AURANTIUM DULCIS PEEL OIL',
        'is_active' => true,
    ]);
    $fragranceBlend = Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Lavender Orange Blend',
        'inci_name' => 'LAVANDER ORANGE BLEND',
        'is_active' => true,
    ]);

    IngredientComponent::factory()->create([
        'ingredient_id' => $fragranceBlend->id,
        'component_ingredient_id' => $lavenderOil->id,
        'percentage_in_parent' => 60,
        'sort_order' => 1,
        'source_notes' => null,
    ]);
    IngredientComponent::factory()->create([
        'ingredient_id' => $fragranceBlend->id,
        'component_ingredient_id' => $orangeOil->id,
        'percentage_in_parent' => 40,
        'sort_order' => 2,
        'source_notes' => null,
    ]);

    $linalool = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    $limonene = Allergen::factory()->create(['inci_name' => 'LIMONENE']);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $linalool->id,
        'concentration_percent' => 50,
        'source_notes' => null,
    ]);
    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $orangeOil->id,
        'allergen_id' => $limonene->id,
        'concentration_percent' => 95,
        'source_notes' => null,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        soapDraftPayloadWithFragrance($oliveOil, $fragranceBlend),
        app(RecipeWorkbenchService::class),
    );

    $declarationRows = collect($result['labeling']['declaration_rows'])->keyBy('label');

    expect($result['labeling']['final_labels'])->toContain(
        'LAVANDULA ANGUSTIFOLIA OIL',
        'CITRUS AURANTIUM DULCIS PEEL OIL',
        'LINALOOL',
        'LIMONENE',
    )
        ->and($result['labeling']['final_labels'])->not->toContain('LAVENDER ORANGE BLEND')
        ->and($declarationRows['LINALOOL']['source_ingredients'])->toBe(['Lavender Essential Oil'])
        ->and($declarationRows['LIMONENE']['source_ingredients'])->toBe(['Orange Essential Oil'])
        ->and($declarationRows['LINALOOL']['included_in_inci'])->toBeTrue()
        ->and($declarationRows['LIMONENE']['included_in_inci'])->toBeTrue();
});

function makeSoapOilIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function soapDraftPayloadWithFragrance(Ingredient $oilIngredient, Ingredient $fragranceIngredient): array
{
    return [
        'name' => 'Preview Formula',
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $oilIngredient->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [
                [
                    'ingredient_id' => $fragranceIngredient->id,
                    'percentage' => 2,
                    'weight' => 20,
                    'note' => null,
                ],
            ],
        ],
    ];
}
