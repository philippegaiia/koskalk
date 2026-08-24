<?php

use App\Enums\IngredientCategory;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Services\RecipeWorkbenchService;
use App\Services\SoapCuredOutputBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('keeps the soap preview available when an ingredient has no inci name', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $oleicAcid = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
        'saturation_class' => 'monounsaturated',
        'default_group_key' => 'mu',
    ]);
    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $oliveOil->id,
        'fatty_acid_id' => $oleicAcid->id,
        'percentage' => 71,
    ]);
    $bacuriButter = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Bacuri Butter',
        'inci_name' => null,
        'is_active' => true,
    ]);
    $unusedFragrance = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]);

    $payload = soapDraftPayloadWithFragrance($oliveOil, $unusedFragrance);
    $payload['phase_items']['fragrance'] = [];
    $payload['phase_items']['additives'] = [[
        'ingredient_id' => $bacuriButter->id,
        'percentage' => 2,
        'weight' => 20,
        'note' => null,
    ]];

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['calculation'])->not->toBeNull()
        ->and($result['calculation']['properties']['fatty_acid_groups']['mu'])->toBe(71.0)
        ->and($result['labeling']['final_labels'])->toContain('BACURI BUTTER')
        ->and(collect($result['labeling']['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'Bacuri Butter'),
        ))->toBeFalse();
});

it('returns a generated ingredient list and declaration details in the live preview', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
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
        ->and($incorporatedVariant['final_labels'])->not->toContain('SODIUM OLIVATE', 'GLYCERIN')
        ->and($result['labeling']['plain_language_list']['final_label_text'])->toStartWith('Saponified Oils of')
        ->and($listVariants['saponified_with_superfat']['plain_label_text'])
        ->toBe($result['labeling']['plain_language_list']['final_label_text'])
        ->and($incorporatedVariant['plain_label_text'])
        ->toContain('Sodium Hydroxide')
        ->not->toContain('Saponified Oils of');
});

it('calculates declaration percentages on the cured soap basis', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient([
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
    ]);
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Super Oil',
        'inci_name' => 'LAVANDULA HYBRIDA OIL',
        'is_active' => true,
    ]);
    $linalool = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $linalool->id,
        'concentration_percent' => 10,
        'source_notes' => null,
    ]);

    $payload = soapDraftPayloadWithFragrance($oliveOil, $lavenderOil);
    $payload['oil_weight'] = 770;
    $payload['phase_items']['saponified_oils'][0]['weight'] = 770;
    $payload['phase_items']['saponified_oils'][0]['percentage'] = 100;
    $payload['phase_items']['fragrance'][0]['weight'] = 15.4;
    $payload['phase_items']['fragrance'][0]['percentage'] = 2;

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    $declaration = collect($result['labeling']['declaration_rows'])
        ->firstWhere('label', 'LINALOOL');
    $expectedCuredPercentage = (15.4 * 0.1 / $result['labeling']['basis']['cured_weight']) * 100;

    expect($result['ok'])->toBeTrue()
        ->and($result['labeling']['basis']['cured_weight'])->toBeGreaterThan(900)
        ->and($declaration['percent_of_cured_basis'])
        ->toBeGreaterThan($expectedCuredPercentage - 0.00001)
        ->toBeLessThan($expectedCuredPercentage + 0.00001);
});

it('uses each oil SAP value when assigning theoretical soap salt mass', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $almondOil = makeSoapOilIngredient([
        'display_name' => 'Almond Oil',
        'inci_name' => 'PRUNUS AMYGDALUS DULCIS (SWEET ALMOND) OIL',
        'soap_inci_naoh_name' => 'SODIUM ALMONDATE',
        'soap_inci_koh_name' => 'POTASSIUM ALMONDATE',
    ]);
    $coconutOil = makeSoapOilIngredient([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA (COCONUT) OIL',
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => 'POTASSIUM COCOATE',
    ], 0.257);
    $fragrance = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]);

    $payload = soapDraftPayloadWithFragrance($almondOil, $fragrance);
    $payload['oil_weight'] = 770;
    $payload['superfat'] = 7;
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $almondOil->id,
            'percentage' => 75,
            'weight' => 577.5,
            'note' => null,
        ],
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => 25,
            'weight' => 192.5,
            'note' => null,
        ],
    ];
    $payload['phase_items']['fragrance'] = [];

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    $ingredientRows = collect($result['labeling']['ingredient_rows'])->keyBy('label');

    expect($result['ok'])->toBeTrue()
        ->and($ingredientRows['SODIUM ALMONDATE']['weight'])->toBe(553.8132)
        ->and($ingredientRows['SODIUM COCOATE']['weight'])->toBe(186.6521)
        ->and($ingredientRows['GLYCERIN']['weight'])->toBe(80.4311);
});

it('generates a plain-language soap ingredient list starting with saponified oils', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $coconutOil = makeSoapOilIngredient([
        'display_name' => 'Coconut Oil',
        'inci_name' => 'COCOS NUCIFERA OIL',
        'soap_inci_naoh_name' => 'SODIUM COCOATE',
        'soap_inci_koh_name' => 'POTASSIUM COCOATE',
    ]);
    $sheaButter = makeSoapOilIngredient([
        'display_name' => 'Shea Butter',
        'inci_name' => 'BUTYROSPERMUM PARKII BUTTER',
        'soap_inci_naoh_name' => 'SODIUM SHEA BUTTERATE',
        'soap_inci_koh_name' => 'POTASSIUM SHEA BUTTERATE',
    ]);
    $greenClay = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'display_name' => 'Green Clay',
        'inci_name' => 'ILLITE',
        'is_active' => true,
    ]);
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);

    $payload = soapDraftPayloadWithFragrance($coconutOil, $lavenderOil);
    $payload['phase_items']['saponified_oils'] = [
        [
            'ingredient_id' => $coconutOil->id,
            'percentage' => 70,
            'weight' => 700,
            'note' => null,
        ],
        [
            'ingredient_id' => $sheaButter->id,
            'percentage' => 30,
            'weight' => 300,
            'note' => null,
        ],
    ];
    $payload['phase_items']['additives'][] = [
        'ingredient_id' => $greenClay->id,
        'percentage' => 1,
        'weight' => 10,
        'note' => null,
    ];

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    expect($result['labeling']['plain_language_list']['final_label_text'])
        ->toBe('Saponified Oils of (Coconut, Shea), Water, Glycerin, Lavender Essential Oil, Green Clay');
});

it('suppresses duplicate declaration rows when the ingredient list already names the same label', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
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
        'category' => IngredientCategory::AromaticMaterials,
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
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $orangeOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Orange Essential Oil',
        'inci_name' => 'CITRUS AURANTIUM DULCIS PEEL OIL',
        'is_active' => true,
    ]);
    $fragranceBlend = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
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

it('screens allergen declarations through the selected regulatory regime mapping', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);

    $linalool = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);
    $geraniol = Allergen::factory()->create([
        'inci_name' => 'GERANIOL',
    ]);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $linalool->id,
        'concentration_percent' => 90,
        'source_notes' => null,
    ]);
    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $geraniol->id,
        'concentration_percent' => 2,
        'source_notes' => null,
    ]);

    $regime = RegulatoryRegime::factory()->create([
        'code' => 'custom_market',
        'market_code' => 'eu',
        'name' => 'Custom Market',
        'status' => 'active',
    ]);

    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => $regime->id,
        'allergen_id' => $geraniol->id,
        'rinse_off_threshold_percent' => 0.01,
        'leave_on_threshold_percent' => 0.001,
        'is_active' => true,
    ]);

    $payload = soapDraftPayloadWithFragrance($oliveOil, $lavenderOil);
    $payload['regulatory_regime'] = 'custom_market';

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    $declarationRows = collect($result['labeling']['declaration_rows'])->keyBy('label');

    expect($result['ok'])->toBeTrue()
        ->and($declarationRows->keys()->all())->toBe(['GERANIOL'])
        ->and($declarationRows['GERANIOL']['included_in_inci'])->toBeTrue()
        ->and($result['labeling']['final_labels'])->toContain('GERANIOL')
        ->and($result['labeling']['final_labels'])->not->toContain('LINALOOL');
});

it('does not fall back to every recorded allergen when the selected regime has no active mappings', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $linalool = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);

    IngredientAllergenEntry::factory()->create([
        'ingredient_id' => $lavenderOil->id,
        'allergen_id' => $linalool->id,
        'concentration_percent' => 90,
        'source_notes' => null,
    ]);

    RegulatoryRegime::factory()->create([
        'code' => 'empty_market',
        'market_code' => 'eu',
        'name' => 'Empty Market',
        'status' => 'active',
    ]);

    $payload = soapDraftPayloadWithFragrance($oliveOil, $lavenderOil);
    $payload['regulatory_regime'] = 'empty_market';

    $component = app(RecipeWorkbench::class);
    $component->mount();

    $result = $component->previewCalculation(
        $payload,
        app(RecipeWorkbenchService::class),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['labeling']['declaration_rows'])->toBe([])
        ->and($result['labeling']['final_labels'])->not->toContain('LINALOOL');
});

it('batches ingredient graph loading for composite aromatics', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $lavenderOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Lavender Essential Oil',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'is_active' => true,
    ]);
    $orangeOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Orange Essential Oil',
        'inci_name' => 'CITRUS AURANTIUM DULCIS PEEL OIL',
        'is_active' => true,
    ]);
    $fragranceBlend = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
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
        'category' => IngredientCategory::AromaticMaterials,
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

    expect($ingredientRows['SODIUM OLIVATE']['weight'])->toBe(742.4391)
        ->and($ingredientRows['OLEA EUROPAEA FRUIT OIL']['weight'])->toBe(80.0)
        ->and($ingredientRows['SODIUM COCOATE']['weight'])->toBe(187.6687)
        ->and($ingredientRows['COCOS NUCIFERA OIL']['weight'])->toBe(20.0);
});

it('splits dual lye saponified oils into separate sodium and potassium rows', function () {
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
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]));
    $payload['lye_type'] = 'dual';
    $payload['dual_lye_koh_percentage'] = 40;
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

    expect($result['labeling']['final_labels'])->toContain(
        'SODIUM OLIVATE',
        'POTASSIUM OLIVATE',
        'SODIUM COCOATE',
        'POTASSIUM COCOATE',
    )
        ->and($ingredientRows)->not->toHaveKey('SODIUM OLIVATE, POTASSIUM OLIVATE')
        ->and($ingredientRows)->not->toHaveKey('SODIUM COCOATE, POTASSIUM COCOATE')
        ->and($ingredientRows['SODIUM OLIVATE']['weight'])->toBe(445.4635)
        ->and($ingredientRows['POTASSIUM OLIVATE']['weight'])->toBe(312.5193)
        ->and($ingredientRows['SODIUM COCOATE']['weight'])->toBe(112.6012)
        ->and($ingredientRows['POTASSIUM COCOATE']['weight'])->toBe(80.3796)
        ->and($ingredientRows['OLEA EUROPAEA FRUIT OIL']['weight'])->toBe(80.0)
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
        'category' => IngredientCategory::AromaticMaterials,
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
        ->and($ingredientRow['weight'])->toBe(1029.6072)
        ->and($ingredientRow['kind'])->toBe('mixed_saponified_superfat');
});

it('preserves lye liquid provenance when matching inci labels merge across phases', function () {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $unusedFragrance = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]);
    $hydrosol = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Rose hydrosol',
        'inci_name' => 'ROSA DAMASCENA FLOWER WATER',
        'is_active' => true,
    ]);
    $addedWater = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Added water',
        'inci_name' => 'AQUA',
        'is_active' => true,
    ]);
    $payload = soapDraftPayloadWithFragrance($oliveOil, $unusedFragrance);
    $payload['phase_items']['fragrance'] = [];
    $payload['phase_items']['lye_water'] = [[
        'ingredient_id' => $hydrosol->id,
        'percentage' => 50,
        'weight' => 0,
        'note' => null,
    ]];
    $payload['phase_items']['additives'] = [
        [
            'ingredient_id' => $hydrosol->id,
            'percentage' => 2,
            'weight' => 20,
            'note' => null,
        ],
        [
            'ingredient_id' => $addedWater->id,
            'percentage' => 1,
            'weight' => 10,
            'note' => null,
        ],
    ];

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $result = $component->previewCalculation($payload, app(RecipeWorkbenchService::class));
    $ingredientRows = collect($result['labeling']['ingredient_rows'])->keyBy('label');

    expect($ingredientRows['ROSA DAMASCENA FLOWER WATER']['weight'])->toBe(210.0)
        ->and($ingredientRows['ROSA DAMASCENA FLOWER WATER']['lye_liquid_weight'])->toBe(190.0)
        ->and($ingredientRows['AQUA']['weight'])->toBe(200.0)
        ->and($ingredientRows['AQUA']['lye_liquid_weight'])->toBe(190.0);
});

it('keeps a zero lye liquid placeholder from stealing the following replacement allocation', function (): void {
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $oliveOil = makeSoapOilIngredient();
    $unusedFragrance = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'display_name' => 'Unscented Base',
        'inci_name' => 'PARFUM',
        'is_active' => true,
    ]);
    $zeroHydrosol = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Zero hydrosol',
        'inci_name' => 'ROSA DAMASCENA FLOWER WATER',
        'is_active' => true,
    ]);
    $selectedMilk = Ingredient::factory()->create([
        'category' => IngredientCategory::WaterSolventsCarriers,
        'display_name' => 'Selected milk',
        'inci_name' => 'CAPRAE LAC',
        'is_active' => true,
    ]);
    $payload = soapDraftPayloadWithFragrance($oliveOil, $unusedFragrance);
    $payload['phase_items']['fragrance'] = [];
    $payload['phase_items']['lye_water'] = [
        [
            'ingredient_id' => $zeroHydrosol->id,
            'percentage' => 0,
            'weight' => 0,
            'note' => null,
        ],
        [
            'ingredient_id' => $selectedMilk->id,
            'percentage' => 50,
            'weight' => 0,
            'note' => null,
        ],
    ];

    $component = app(RecipeWorkbench::class);
    $component->mount();
    $result = $component->previewCalculation($payload, app(RecipeWorkbenchService::class));
    $ingredientRows = collect($result['labeling']['ingredient_rows'])->keyBy('label');

    expect($ingredientRows)->not->toHaveKey('ROSA DAMASCENA FLOWER WATER')
        ->and($ingredientRows['CAPRAE LAC']['weight'])->toBe(190.0)
        ->and($ingredientRows['CAPRAE LAC']['lye_liquid_weight'])->toBe(190.0)
        ->and($ingredientRows['AQUA']['lye_liquid_weight'])->toBe(190.0)
        ->and($ingredientRows->sum('lye_liquid_weight'))->toBe(380.0)
        ->and($result['labeling']['final_label_text'])->toContain('CAPRAE LAC')
        ->not->toContain('ROSA DAMASCENA FLOWER WATER');

    $curedWeight = (float) $result['labeling']['basis']['cured_weight'];
    $curedOutput = app(SoapCuredOutputBuilder::class)->build($result['labeling'], $curedWeight);
    $curedRows = collect($curedOutput['rows'])->keyBy('name');
    $expectedResidualShare = round($curedWeight * 0.11 * 0.5, 4);

    expect($curedRows)->not->toHaveKey('ROSA DAMASCENA FLOWER WATER')
        ->and($curedRows['CAPRAE LAC']['weight'])->toBe($expectedResidualShare)
        ->and($curedRows['AQUA']['weight'])->toBe($expectedResidualShare)
        ->and(round((float) collect($curedOutput['rows'])->sum('weight'), 4))->toBe(round($curedWeight, 4))
        ->and($curedOutput['inci'])->toContain('CAPRAE LAC')
        ->not->toContain('ROSA DAMASCENA FLOWER WATER');
});

function makeSoapOilIngredient(array $overrides = [], float $kohSapValue = 0.188): Ingredient
{
    $ingredient = Ingredient::factory()->create(array_merge([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
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
