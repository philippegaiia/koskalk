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
use Illuminate\Support\Facades\DB;

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
    $listVariants = collect($result['labeling']['list_variants'])->keyBy('key');
    $incorporatedVariant = $listVariants['incorporated_ingredients'];

    expect($result['ok'])->toBeTrue()
        ->and($result['labeling']['default_variant_key'])->toBe('saponified_with_superfat')
        ->and($result['labeling']['final_labels'])->toContain(
            'SODIUM OLIVATE',
            'OLEA EUROPAEA FRUIT OIL',
            'AQUA',
            'GLYCERIN',
            'LAVANDULA ANGUSTIFOLIA OIL',
            'LINALOOL',
        )
        ->and($result['labeling']['final_labels'])->not->toContain('LIMONENE')
        ->and($declarationRows['LINALOOL']['included_in_inci'])->toBeTrue()
        ->and($declarationRows['LINALOOL']['status_label'])->toBe('Added to INCI')
        ->and($declarationRows['LIMONENE']['included_in_inci'])->toBeFalse()
        ->and($declarationRows['LIMONENE']['status_label'])->toBe('Below threshold')
        ->and($incorporatedVariant['final_labels'])->toContain(
            'OLEA EUROPAEA FRUIT OIL',
            'AQUA',
            'SODIUM HYDROXIDE',
            'LAVANDULA ANGUSTIFOLIA OIL',
            'LINALOOL',
        )
        ->and($incorporatedVariant['final_labels'])->not->toContain('SODIUM OLIVATE', 'GLYCERIN');
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

it('batches ingredient graph loading for composite aromatics', function () {
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
        'inci_name' => 'LAVENDER ORANGE BLEND',
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

    $ingredientQueries = [];

    DB::listen(function ($query) use (&$ingredientQueries): void {
        if (str_contains($query->sql, '"ingredients"')) {
            $ingredientQueries[] = $query->sql;
        }
    });

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $component->previewCalculation(
        soapDraftPayloadWithFragrance($oliveOil, $fragranceBlend),
        app(RecipeWorkbenchService::class),
    );

    expect($ingredientQueries)->toHaveCount(3);
});

it('splits each saponified oil into soap and theoretical superfat rows', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $coconutOil = makeSoapOilIngredient([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => 'POTASSIUM COCOATE',
    ], 0.257);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $payload = soapDraftPayloadWithFragrance($oliveOil, Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]));
    $payload['superfat'] = 10;
    $payload['phase_items']['fragrance'] = [];
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $oliveOil->id,
            'percentage' => 80,
            'weight' => 800,
            'note' => null,
        ],
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => 20,
            'weight' => 200,
            'note' => null,
        ],
    ];

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    $ingredientRows = collect($result['labeling']['ingredient_rows'])->keyBy('label');

    expect($ingredientRows['SODIUM OLIVATE']['weight'])->toBe(720.0)
        ->and($ingredientRows['OLEA EUROPAEA FRUIT OIL']['weight'])->toBe(80.0)
        ->and($ingredientRows['SODIUM COCOATE']['weight'])->toBe(180.0)
        ->and($ingredientRows['COCOS NUCIFERA OIL']['weight'])->toBe(20.0);
});

it('marks merged rows as mixed when soap and superfat labels collapse to the same inci', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient([
        'soap_inci_naoh_name' => null,
        'soap_inci_koh_name' => null,
    ]);

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $payload = soapDraftPayloadWithFragrance($oliveOil, Ingredient::factory()->create([
        'category' => IngredientCategory::FragranceOil,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]));
    $payload['phase_items']['fragrance'] = [];

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    $ingredientRow = collect($result['labeling']['ingredient_rows'])
        ->firstWhere('label', 'OLEA EUROPAEA FRUIT OIL');

    expect($ingredientRow)->not->toBeNull()
        ->and($ingredientRow['weight'])->toBe(1000.0)
        ->and($ingredientRow['kind'])->toBe('mixed_saponified_superfat');
});

function makeSoapOilIngredient(array $overrides = [], float $kohSapValue = 0.188): Ingredient
{
    $ingredient = Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ], $overrides));

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => $kohSapValue,
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
