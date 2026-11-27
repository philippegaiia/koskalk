<?php

use App\Enums\IngredientFunctionSource;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use Database\Seeders\IngredientFunctionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves assignments from a retired function to its CosIng successor with provenance intact', function (): void {
    $emollient = IngredientFunction::factory()->create(['key' => 'emollient', 'name' => 'Emollient']);
    $ingredient = Ingredient::factory()->create();
    $ingredient->functions()->attach($emollient, [
        'source' => IngredientFunctionSource::CosIng->value,
        'source_reference' => '54495',
    ]);

    $this->seed(IngredientFunctionSeeder::class);

    $successor = IngredientFunction::query()->where('key', 'skin_conditioning_emollient')->sole();

    expect($ingredient->fresh()->functions->pluck('key')->all())->toBe(['skin_conditioning_emollient'])
        ->and($emollient->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseHas('ingredient_function_ingredient', [
        'ingredient_id' => $ingredient->id,
        'ingredient_function_id' => $successor->id,
        'source' => IngredientFunctionSource::CosIng->value,
        'source_reference' => '54495',
    ]);
});

it('keeps the existing successor assignment when an ingredient already carries both functions', function (): void {
    $masking = IngredientFunction::factory()->create(['key' => 'masking', 'name' => 'Masking']);
    $fragrance = IngredientFunction::factory()->create(['key' => 'fragrance', 'name' => 'Fragrance']);
    $ingredient = Ingredient::factory()->create();
    $ingredient->functions()->attach($masking, ['source' => IngredientFunctionSource::Manual->value]);
    $ingredient->functions()->attach($fragrance, [
        'source' => IngredientFunctionSource::CosIng->value,
        'source_reference' => '78931',
    ]);

    $this->seed(IngredientFunctionSeeder::class);

    $this->assertDatabaseCount('ingredient_function_ingredient', 1);
    $this->assertDatabaseHas('ingredient_function_ingredient', [
        'ingredient_id' => $ingredient->id,
        'ingredient_function_id' => $fragrance->id,
        'source' => IngredientFunctionSource::CosIng->value,
        'source_reference' => '78931',
    ]);
});

it('deactivates a retired function without a successor and leaves its assignments for review', function (): void {
    $biocide = IngredientFunction::factory()->create(['key' => 'cosmetic_biocide', 'name' => 'Cosmetic biocide']);
    $ingredient = Ingredient::factory()->create();
    $ingredient->functions()->attach($biocide, ['source' => IngredientFunctionSource::Manual->value]);

    $this->seed(IngredientFunctionSeeder::class);

    expect($biocide->fresh()->is_active)->toBeFalse()
        ->and($ingredient->fresh()->functions->pluck('key')->all())->toBe(['cosmetic_biocide']);
});

it('activates only CosIng-named functions and leaves out NOT REPORTED', function (): void {
    $this->seed(IngredientFunctionSeeder::class);

    $activeNames = IngredientFunction::query()->where('is_active', true)->pluck('name');

    expect($activeNames)->toContain('Fragrance', 'Perfuming', 'Skin conditioning - occlusive', 'pH adjusters')
        ->not->toContain('Not reported', 'Emollient', 'Masking');
});
