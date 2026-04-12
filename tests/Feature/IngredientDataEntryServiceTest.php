<?php

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientFunction;
use App\Models\IngredientSapProfile;
use App\Services\IngredientDataEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('syncs current carrier oil data from the ingredient entry service', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'OO1',
        'source_file' => 'admin',
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
        'category' => IngredientCategory::EssentialOil,
        'source_key' => 'EO1',
        'source_file' => 'admin',
        'is_active' => true,
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

it('syncs ingredient functions from the ingredient entry service', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'source_key' => 'ADD1',
        'source_file' => 'admin',
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

it('keeps sap profile reference metrics separate from fatty acid entries', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'AVO1',
        'source_file' => 'admin',
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
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'MAC1',
        'source_file' => 'admin',
        'is_active' => true,
    ]);

    $sunflowerOil = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'SUN1',
        'source_file' => 'admin',
        'is_active' => true,
    ]);

    $tocopherol = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'source_key' => 'TOC1',
        'source_file' => 'admin',
        'is_active' => true,
    ]);

    $calendulaExtract = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'source_key' => 'CAL1',
        'source_file' => 'admin',
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
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'MAC2',
        'source_file' => 'admin',
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
    ]))->toThrow(ValidationException::class, 'Composite components must reference existing catalog ingredients.');
});

it('clears stale dependent data when an ingredient is reclassified', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'REC1',
        'source_file' => 'admin',
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
        'category' => IngredientCategory::Clay,
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

    expect($savedIngredient->sapProfile)->toBeNull()
        ->and($savedIngredient->fattyAcidEntries)->toHaveCount(0)
        ->and($savedIngredient->allergenEntries)->toHaveCount(0);
});
