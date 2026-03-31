<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
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
        'display_name' => 'Olive Oil',
        'inci_name' => 'Olea europaea fruit oil',
        'soap_inci_naoh_name' => 'Sodium olivate',
        'soap_inci_koh_name' => 'Potassium olivate',
        'is_potentially_saponifiable' => true,
    ]);

    IngredientSapProfile::factory()
        ->for($carrierOil, 'ingredient')
        ->create([
            'koh_sap_value' => 0.188,
        ]);

    $essentialOil = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
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

it('shows private user ingredients in the workbench only for their owner', function () {
    ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
        'calculation_basis' => 'initial_oils',
    ]);

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $privateIngredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Additive,
        'display_name' => 'Private Sodium Lactate',
        'inci_name' => 'SODIUM LACTATE',
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
        'source_file' => 'user',
        'source_key' => 'USR-OWN',
    ]);

    $this->actingAs($owner)
        ->get(route('recipes.create'))
        ->assertSuccessful()
        ->assertSee('Private Sodium Lactate');

    $this->actingAs($otherUser)
        ->get(route('recipes.create'))
        ->assertSuccessful()
        ->assertDontSee('Private Sodium Lactate');
});
