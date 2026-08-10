<?php

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an explicitly trusted wax with KOH SAP to drive saponification', function (): void {
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Waxes,
        'is_soap_saponification_trusted' => true,
        'requires_aromatic_compliance' => false,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.092,
    ]);

    expect($ingredient->fresh()->canDriveSoapSaponification())->toBeTrue();
});

it('uses only the saved aromatic capability regardless of category', function (): void {
    $aromaticWithoutCapability = Ingredient::factory()->create([
        'category' => IngredientCategory::AromaticMaterials,
        'requires_aromatic_compliance' => false,
    ]);

    $nonAromaticWithCapability = Ingredient::factory()->create([
        'category' => IngredientCategory::Other,
        'requires_aromatic_compliance' => true,
    ]);

    expect($aromaticWithoutCapability->requiresAromaticCompliance())->toBeFalse()
        ->and($nonAromaticWithCapability->requiresAromaticCompliance())->toBeTrue();
});

it('keeps ordinary canonical material families available as additives', function (IngredientCategory $category): void {
    $ingredient = Ingredient::factory()->create([
        'category' => $category,
        'is_soap_saponification_trusted' => false,
        'requires_aromatic_compliance' => false,
    ]);

    expect($ingredient->availableWorkbenchPhases())->toBe(['additives']);
})->with([
    IngredientCategory::Waxes,
    IngredientCategory::Hydrocarbons,
    IngredientCategory::Silicones,
    IngredientCategory::FattyDerivatives,
    IngredientCategory::Surfactants,
    IngredientCategory::Emulsifiers,
    IngredientCategory::HumectantsPolyols,
    IngredientCategory::RheologyModifiers,
    IngredientCategory::FunctionalPolymers,
    IngredientCategory::Actives,
    IngredientCategory::ExfoliantsAbrasives,
    IngredientCategory::BasesBlendsPremixes,
]);
