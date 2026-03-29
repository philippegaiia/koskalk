<?php

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\IngredientVersion;
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

    $version = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
        'current_version' => [
            'display_name' => 'Olive Oil',
            'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
            'unit' => 'kg',
            'price_eur' => 12.5,
            'is_active' => true,
            'is_manufactured' => false,
        ],
        'sap_profile' => [
            'koh_sap_value' => 0.188,
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

    expect($version->display_name)->toBe('Olive Oil')
        ->and($version->inci_name)->toBe('OLEA EUROPAEA FRUIT OIL')
        ->and((float) $version->sapProfile->koh_sap_value)->toBe(0.188)
        ->and($version->fattyAcidEntries)->toHaveCount(2)
        ->and($version->fattyAcidEntries->pluck('fatty_acid_id')->all())->toEqualCanonicalizing([$oleic->id, $palmitic->id]);
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

    $version = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
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

    expect($version->display_name)->toBe('Lavender Essential Oil')
        ->and($version->allergenEntries)->toHaveCount(2)
        ->and($version->allergenEntries->pluck('allergen_id')->all())->toEqualCanonicalizing([$linalool->id, $limonene->id]);
});

it('hydrates fatty acid rows from the legacy sap profile when normalized rows are missing', function () {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'source_key' => 'AVO1',
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

    $version = IngredientVersion::factory()->create([
        'ingredient_id' => $ingredient->id,
        'is_current' => true,
        'display_name' => 'Avocado Oil',
        'inci_name' => 'PERSEA GRATISSIMA OIL',
        'source_key' => 'AVO1',
        'source_file' => 'admin',
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_version_id' => $version->id,
        'koh_sap_value' => 0.188,
        'oleic' => 67,
        'palmitic' => 14,
        'source_notes' => 'Legacy imported profile.',
    ]);

    $fattyAcidEntries = app(IngredientDataEntryService::class)->formData($ingredient)['fatty_acid_entries'];

    expect($fattyAcidEntries)->toHaveCount(2)
        ->and(collect($fattyAcidEntries)->pluck('fatty_acid_id')->all())->toEqualCanonicalizing([$oleic->id, $palmitic->id])
        ->and(collect($fattyAcidEntries)->pluck('source_notes')->unique()->values()->all())->toEqual(['Legacy imported profile.']);
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
