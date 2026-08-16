<?php

use App\Enums\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientFunction;
use App\Models\IngredientSapProfile;
use App\Models\Substance;
use App\Models\SupportedLocale;
use App\Services\IngredientDataEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('syncs current carrier oil data from the ingredient entry service', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'OO1',
        'is_active' => true,
    ]);

    $oleic = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
    ]);

    $palmitic = FattyAcid::factory()->create([
        'key' => 'palmitic',
        'name' => 'Palmitic',
    ]);

    $savedIngredient = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Olive Oil',
            'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
            'unit' => 'kg',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [
            'koh_sap_value' => 0.188,
            'iodine_value' => 86.4,
            'ins_value' => 102.8,
            'source_notes' => 'Trusted supplier average.',
        ],
        'fatty_acid_entries' => [
            [
                'fatty_acid_id' => $oleic->id,
                'percentage' => 71,
                'source_notes' => 'Main profile',
            ],
            [
                'fatty_acid_id' => $palmitic->id,
                'percentage' => 13,
                'source_notes' => null,
            ],
        ],
        'allergen_entries' => [],
    ]);

    expect($savedIngredient->display_name)->toBe('Olive Oil')
        ->and($savedIngredient->inci_name)->toBe('OLEA EUROPAEA FRUIT OIL')
        ->and((float) $savedIngredient->sapProfile->koh_sap_value)->toBe(0.188)
        ->and((float) $savedIngredient->sapProfile->iodine_value)->toBe(86.4)
        ->and((float) $savedIngredient->sapProfile->ins_value)->toBe(102.8)
        ->and($savedIngredient->fattyAcidEntries)->toHaveCount(2)
        ->and($savedIngredient->fattyAcidEntries->pluck('fatty_acid_id')->all())->toEqualCanonicalizing([$oleic->id, $palmitic->id]);
});

it('syncs current aromatic allergen data from the ingredient entry service', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'catalog_key' => 'EO1',
        'is_active' => true,
        'requires_aromatic_compliance' => true,
    ]);

    $linalool = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);

    $limonene = Allergen::factory()->create([
        'inci_name' => 'LIMONENE',
    ]);

    $savedIngredient = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Lavender Essential Oil',
            'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
            'unit' => 'kg',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [
            [
                'allergen_id' => $linalool->id,
                'concentration_percent' => 0.85,
                'source_notes' => null,
            ],
            [
                'allergen_id' => $limonene->id,
                'concentration_percent' => 0.22,
                'source_notes' => 'Trace level from supplier declaration.',
            ],
        ],
    ]);

    expect($savedIngredient->display_name)->toBe('Lavender Essential Oil')
        ->and($savedIngredient->allergenEntries)->toHaveCount(2)
        ->and($savedIngredient->allergenEntries->pluck('allergen_id')->all())->toEqualCanonicalizing([$linalool->id, $limonene->id]);
});

it('round trips current IFRA guidance and category limits from the ingredient entry service', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'requires_aromatic_compliance' => true,
    ]);
    $category = IfraProductCategory::factory()->create();

    $savedIngredient = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => ['display_name' => 'Lavender essential oil'],
        'ifra' => [
            'reference_label' => 'Supplier IFRA certificate',
            'ifra_amendment' => '51',
            'peroxide_value' => '2.5',
            'source_notes' => 'Supplier document dated 2026-08-01.',
            'limits' => [[
                'ifra_product_category_id' => $category->id,
                'max_percentage' => '4.2',
                'restriction_note' => 'Finished product maximum.',
            ]],
        ],
    ]);

    $state = app(IngredientDataEntryService::class)->formData($savedIngredient);
    $certificate = $savedIngredient->ifraCertificates()->with('limits')->where('is_current', true)->first();

    expect($certificate)->not->toBeNull()
        ->and($certificate?->certificate_name)->toBe('Supplier IFRA certificate')
        ->and($certificate?->ifra_amendment)->toBe('51')
        ->and((float) $certificate?->peroxide_value)->toBe(2.5)
        ->and($certificate?->limits)->toHaveCount(1)
        ->and((float) $certificate?->limits->first()->max_percentage)->toBe(4.2)
        ->and($certificate?->limits->first()->restriction_note)->toBe('Finished product maximum.')
        ->and(data_get($state, 'ifra.reference_label'))->toBe('Supplier IFRA certificate')
        ->and(data_get($state, 'ifra.limits.0.ifra_product_category_id'))->toBe($category->id);
});

it('syncs ingredient functions from the ingredient entry service', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'catalog_key' => 'ADD1',
        'is_active' => true,
    ]);

    $emollient = IngredientFunction::factory()->create([
        'key' => 'emollient',
        'name' => 'Emollient',
        'sort_order' => 10,
    ]);

    $skinConditioning = IngredientFunction::factory()->create([
        'key' => 'skin_conditioning',
        'name' => 'Skin conditioning',
        'sort_order' => 20,
    ]);

    $savedIngredient = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Calendula Balm Extract',
            'inci_name' => 'CALENDULA OFFICINALIS FLOWER EXTRACT',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'function_ids' => [$skinConditioning->id, $emollient->id, $emollient->id],
        'components' => [],
    ]);

    expect($savedIngredient->functions)->toHaveCount(2)
        ->and($savedIngredient->functions->pluck('id')->all())->toEqual([$emollient->id, $skinConditioning->id])
        ->and(app(IngredientDataEntryService::class)->formData($savedIngredient)['function_ids'])->toEqual([$emollient->id, $skinConditioning->id]);
});

it('round-trips identifiers aliases and simple substance composition', function (): void {
    $ingredient = Ingredient::factory()->create();
    $substance = Substance::factory()->create();

    $savedIngredient = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Sodium levulinate',
            'inci_name' => 'SODIUM LEVULINATE',
        ],
        'cas_number' => '19856-23-6',
        'additional_identifiers' => [[
            'scheme' => 'unii',
            'value' => 'VK3H1Z8Z6V',
            'is_primary' => true,
        ]],
        'aliases' => [[
            'locale' => 'und',
            'name' => 'Sodium 4-oxovalerate',
            'kind' => 'common',
        ]],
        'substance_entries' => [[
            'substance_id' => $substance->id,
            'concentration_percent' => 0.8,
        ]],
    ]);

    $state = app(IngredientDataEntryService::class)->formData($savedIngredient);

    expect($state['cas_number'])->toBe('19856-23-6')
        ->and($state['additional_identifiers'][0]['scheme'])->toBe('unii')
        ->and($state['aliases'][0]['name'])->toBe('Sodium 4-oxovalerate')
        ->and($state['substance_entries'][0]['substance_id'])->toBe($substance->id)
        ->and((float) $state['substance_entries'][0]['concentration_percent'])->toBe(0.8);
});

it('preserves omitted identity collections while allowing explicit collection clearing', function (): void {
    SupportedLocale::factory()->create(['code' => 'fr']);
    $ingredient = Ingredient::factory()->create();
    $ingredient->identifiers()->createMany([
        ['scheme' => 'cas', 'value' => '19856-23-6', 'normalized_value' => '19856-23-6', 'is_primary' => true],
        ['scheme' => 'unii', 'value' => 'VK3H1Z8Z6V', 'normalized_value' => 'VK3H1Z8Z6V', 'is_primary' => true],
    ]);
    $ingredient->aliases()->create([
        'locale' => 'fr',
        'name' => 'Lévulinate de sodium',
        'normalized_name' => 'lévulinate de sodium',
        'kind' => 'common',
    ]);

    $service = app(IngredientDataEntryService::class);
    $service->syncCurrentData($ingredient, [
        'current_version' => ['display_name' => 'Sodium levulinate'],
        'aliases' => [],
    ]);

    expect($ingredient->fresh()->identifiers)->toHaveCount(2)
        ->and($ingredient->fresh()->aliases)->toBeEmpty();

    $service->syncCurrentData($ingredient, [
        'current_version' => ['display_name' => 'Sodium levulinate'],
        'cas_number' => '19856-23-6',
    ]);

    expect($ingredient->fresh()->identifiers)->toHaveCount(2)
        ->and($ingredient->fresh()->aliases)->toBeEmpty();

    $service->syncCurrentData($ingredient, [
        'current_version' => ['display_name' => 'Sodium levulinate'],
        'additional_identifiers' => [],
    ]);

    expect($ingredient->fresh()->identifiers)->toHaveCount(1)
        ->and($ingredient->fresh()->aliases)->toBeEmpty();
});

it('preserves CosIng function provenance when a workspace edits its function selection', function () {
    $ingredient = Ingredient::factory()->create();
    $cosing = IngredientFunction::factory()->create();
    $manual = IngredientFunction::factory()->create();

    $ingredient->functions()->attach($cosing, [
        'source' => 'cosing',
        'source_reference' => 'CosIng ref 123',
        'source_checked_at' => now(),
    ]);

    app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => ['display_name' => $ingredient->display_name],
        'function_ids' => [$cosing->id, $manual->id],
    ]);

    $assignments = $ingredient->fresh()->functions->keyBy('id');

    expect($assignments[$cosing->id]->pivot->source)->toBe('cosing')
        ->and($assignments[$cosing->id]->pivot->source_reference)->toBe('CosIng ref 123')
        ->and($assignments[$manual->id]->pivot->source)->toBe('manual');
});

it('removes only manual function assignments when a workspace clears its selection', function () {
    $ingredient = Ingredient::factory()->create();
    $cosing = IngredientFunction::factory()->create();
    $manual = IngredientFunction::factory()->create();

    $ingredient->functions()->attach($cosing, ['source' => 'cosing', 'source_reference' => 'CosIng ref']);
    $ingredient->functions()->attach($manual, ['source' => 'manual']);

    app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => ['display_name' => $ingredient->display_name],
        'function_ids' => [],
    ]);

    expect($ingredient->fresh()->functions->pluck('id')->all())->toBe([$cosing->id]);
});

it('preserves specialist records when their form state was not submitted', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => true,
    ]);
    $fattyAcid = FattyAcid::factory()->create();
    $allergen = Allergen::factory()->create();

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);
    $ingredient->fattyAcidEntries()->create([
        'fatty_acid_id' => $fattyAcid->id,
        'percentage' => 71,
    ]);
    $ingredient->allergenEntries()->create([
        'allergen_id' => $allergen->id,
        'concentration_percent' => 0.4,
    ]);

    app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Identity-only edit',
            'is_active' => true,
        ],
    ]);

    $ingredient->refresh();

    expect((float) $ingredient->sapProfile->koh_sap_value)->toBe(0.188)
        ->and($ingredient->fattyAcidEntries)->toHaveCount(1)
        ->and($ingredient->allergenEntries)->toHaveCount(1);
});

it('keeps sap profile reference metrics separate from fatty acid entries', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'AVO1',
        'is_active' => true,
    ]);

    $ingredient->update([
        'display_name' => 'Avocado Oil',
        'inci_name' => 'PERSEA GRATISSIMA OIL',
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
        'iodine_value' => 84.7,
        'ins_value' => 105.1,
        'source_notes' => 'Supplier reference sheet.',
    ]);

    $formData = app(IngredientDataEntryService::class)->formData($ingredient);

    expect($formData['fatty_acid_entries'])->toBe([])
        ->and($formData['sap_profile']['koh_sap_value'])->toBe(0.188)
        ->and($formData['sap_profile']['iodine_value'])->toBe(84.7)
        ->and($formData['sap_profile']['ins_value'])->toBe(105.1)
        ->and($formData['sap_profile']['source_notes'])->toBe('Supplier reference sheet.');
});

it('syncs composite ingredient components from the ingredient entry service', function () {
    $macerate = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'MAC1',
        'is_active' => true,
    ]);

    $sunflowerOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'SUN1',
        'is_active' => true,
    ]);

    $tocopherol = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'catalog_key' => 'TOC1',
        'is_active' => true,
    ]);

    $calendulaExtract = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'catalog_key' => 'CAL1',
        'is_active' => true,
    ]);

    app(IngredientDataEntryService::class)->syncCurrentData($sunflowerOil, [
        'current_version' => [
            'display_name' => 'Sunflower Oil',
            'inci_name' => 'HELIANTHUS ANNUUS SEED OIL',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [],
    ]);

    app(IngredientDataEntryService::class)->syncCurrentData($tocopherol, [
        'current_version' => [
            'display_name' => 'Tocopherol',
            'inci_name' => 'TOCOPHEROL',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [],
    ]);

    app(IngredientDataEntryService::class)->syncCurrentData($calendulaExtract, [
        'current_version' => [
            'display_name' => 'Calendula Extract',
            'inci_name' => 'CALENDULA OFFICINALIS FLOWER EXTRACT',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [],
    ]);

    app(IngredientDataEntryService::class)->syncCurrentData($macerate, [
        'current_version' => [
            'display_name' => 'Calendula Macerate',
            'inci_name' => 'HELIANTHUS ANNUUS SEED OIL, CALENDULA OFFICINALIS FLOWER EXTRACT, TOCOPHEROL',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [
            [
                'component_ingredient_id' => $sunflowerOil->id,
                'percentage_in_parent' => 89.5,
                'source_notes' => 'Carrier oil base.',
            ],
            [
                'component_ingredient_id' => $calendulaExtract->id,
                'percentage_in_parent' => 10,
                'source_notes' => 'Botanical fraction.',
            ],
            [
                'component_ingredient_id' => $tocopherol->id,
                'percentage_in_parent' => 0.5,
                'source_notes' => 'Antioxidant.',
            ],
        ],
    ]);

    $components = $macerate->fresh()->components;

    expect($components)->toHaveCount(3)
        ->and($components->pluck('component_ingredient_id')->filter()->values()->all())->toEqualCanonicalizing([$sunflowerOil->id, $calendulaExtract->id, $tocopherol->id])
        ->and(app(IngredientDataEntryService::class)->formData($macerate)['components'])->toHaveCount(3);
});

it('rejects composite components that do not reference catalog ingredients', function () {
    $macerate = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'MAC2',
        'is_active' => true,
    ]);

    expect(fn () => app(IngredientDataEntryService::class)->syncCurrentData($macerate, [
        'current_version' => [
            'display_name' => 'Invalid Macerate',
            'inci_name' => 'TEST',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [
            [
                'percentage_in_parent' => 100,
                'source_notes' => 'Should fail.',
            ],
        ],
    ]))->toThrow(ValidationException::class, 'Each blend row must use an ingredient from your catalogue.');
});

it('preserves specialist data when an ingredient is reclassified', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'REC1',
        'is_active' => true,
    ]);

    $oleic = FattyAcid::factory()->create([
        'key' => 'oleic',
        'name' => 'Oleic',
    ]);

    $linalool = Allergen::factory()->create([
        'inci_name' => 'LINALOOL',
    ]);

    $service = app(IngredientDataEntryService::class);

    $savedIngredient = $service->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Reclassify Me',
            'inci_name' => 'TEST OIL',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [
            'koh_sap_value' => 0.188,
            'source_notes' => 'Initial chemistry',
        ],
        'fatty_acid_entries' => [
            [
                'fatty_acid_id' => $oleic->id,
                'percentage' => 71,
                'source_notes' => null,
            ],
        ],
        'allergen_entries' => [],
        'components' => [],
    ]);

    IngredientAllergenEntry::query()->create([
        'ingredient_id' => $savedIngredient->id,
        'allergen_id' => $linalool->id,
        'concentration_percent' => 0.5,
        'source_notes' => 'Old aromatic data',
    ]);

    $ingredient->update([
        'category' => IngredientCategory::MineralsSaltsPowders,
    ]);

    $service->syncCurrentData($ingredient->fresh(), [
        'current_version' => [
            'display_name' => 'Reclassify Me',
            'inci_name' => 'TEST CLAY',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [],
    ]);

    $savedIngredient = $savedIngredient->fresh(['sapProfile', 'fattyAcidEntries', 'allergenEntries']);

    expect($savedIngredient->sapProfile)->not->toBeNull()
        ->and($savedIngredient->fattyAcidEntries)->toHaveCount(1)
        ->and($savedIngredient->allergenEntries)->toHaveCount(1);
});

it('preserves existing per-row source notes when resynced without them', function () {
    $blend = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'BLN1',
        'is_active' => true,
    ]);
    $component = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'catalog_key' => 'CMP1',
        'is_active' => true,
    ]);

    $service = app(IngredientDataEntryService::class);

    $service->syncCurrentData($blend, [
        'current_version' => [
            'display_name' => 'Blend',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [
            [
                'component_ingredient_id' => $component->id,
                'percentage_in_parent' => 100,
                'source_notes' => 'Legacy lab report',
            ],
        ],
    ]);

    expect($blend->fresh()->components->first()->source_notes)->toBe('Legacy lab report');

    // The new UI no longer collects per-row source notes, so a resync omits them.
    // Existing evidence must be retained rather than silently wiped.
    $service->syncCurrentData($blend, [
        'current_version' => [
            'display_name' => 'Blend',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [
            [
                'component_ingredient_id' => $component->id,
                'percentage_in_parent' => 100,
            ],
        ],
    ]);

    expect($blend->fresh()->components->first()->source_notes)->toBe('Legacy lab report');
});

it('clears explicitly blank per-row source notes', function () {
    $fattyAcid = FattyAcid::factory()->create();
    $allergen = Allergen::factory()->create();
    $component = Ingredient::factory()->create();
    $carrierOil = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
    ]);
    $essentialOil = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'requires_aromatic_compliance' => true,
    ]);
    $blend = Ingredient::factory()->create();
    $service = app(IngredientDataEntryService::class);

    $service->syncCurrentData($carrierOil, [
        'current_version' => [
            'display_name' => 'Carrier Oil',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [[
            'fatty_acid_id' => $fattyAcid->id,
            'percentage' => 100,
            'source_notes' => 'Legacy fatty-acid source',
        ]],
        'allergen_entries' => [],
        'components' => [],
    ]);

    $service->syncCurrentData($essentialOil, [
        'current_version' => [
            'display_name' => 'Essential Oil',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [[
            'allergen_id' => $allergen->id,
            'concentration_percent' => 1,
            'source_notes' => 'Legacy allergen source',
        ]],
        'components' => [],
    ]);

    $service->syncCurrentData($blend, [
        'current_version' => [
            'display_name' => 'Blend',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [[
            'component_ingredient_id' => $component->id,
            'percentage_in_parent' => 100,
            'source_notes' => 'Legacy component source',
        ]],
    ]);

    $service->syncCurrentData($carrierOil->fresh(), [
        'current_version' => [
            'display_name' => 'Carrier Oil',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [[
            'fatty_acid_id' => $fattyAcid->id,
            'percentage' => 100,
            'source_notes' => '',
        ]],
        'allergen_entries' => [],
        'components' => [],
    ]);

    $service->syncCurrentData($essentialOil->fresh(), [
        'current_version' => [
            'display_name' => 'Essential Oil',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [[
            'allergen_id' => $allergen->id,
            'concentration_percent' => 1,
            'source_notes' => '',
        ]],
        'components' => [],
    ]);

    $service->syncCurrentData($blend->fresh(), [
        'current_version' => [
            'display_name' => 'Blend',
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [],
        'fatty_acid_entries' => [],
        'allergen_entries' => [],
        'components' => [[
            'component_ingredient_id' => $component->id,
            'percentage_in_parent' => 100,
            'source_notes' => '',
        ]],
    ]);

    expect($carrierOil->fresh()->fattyAcidEntries->first()->source_notes)->toBeNull()
        ->and($essentialOil->fresh()->allergenEntries->first()->source_notes)->toBeNull()
        ->and($blend->fresh()->components->first()->source_notes)->toBeNull();
});
