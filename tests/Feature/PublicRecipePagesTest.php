<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\IngredientVersion;
use App\Models\ProductFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the public recipes page', function () {
    ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
        'calculation_basis' => 'initial_oils',
    ]);

    Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
    ]);

    $this->get(route('recipes.index'))
        ->assertSuccessful()
        ->assertSee('Create soap formula')
        ->assertSee('Calculation basis is family-driven');
});

it('renders the public soap workbench with filtered catalog data', function () {
    ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
        'calculation_basis' => 'initial_oils',
    ]);

    $carrierOil = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'is_potentially_saponifiable' => true,
    ]);

    $carrierOilVersion = IngredientVersion::factory()
        ->for($carrierOil)
        ->create([
            'display_name' => 'Olive Oil',
            'inci_name' => 'Olea europaea fruit oil',
            'soap_inci_naoh_name' => 'Sodium olivate',
            'soap_inci_koh_name' => 'Potassium olivate',
        ]);

    IngredientSapProfile::factory()
        ->for($carrierOilVersion, 'ingredientVersion')
        ->create([
            'koh_sap_value' => 0.188,
            'oleic' => 71,
            'linoleic' => 10,
            'palmitic' => 13,
        ]);

    $essentialOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
    ]);

    IngredientVersion::factory()
        ->for($essentialOil)
        ->create([
            'display_name' => 'Lavender Essential Oil',
            'inci_name' => 'Lavandula angustifolia oil',
        ]);

    $this->get(route('recipes.create'))
        ->assertSuccessful()
        ->assertSee('Reaction core')
        ->assertSee('Additives and aromatics')
        ->assertSee('Olive Oil')
        ->assertSee('Lavender Essential Oil');
});
