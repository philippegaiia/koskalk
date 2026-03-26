<?php

use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientVersion;
use App\Models\IngredientVersionFattyAcid;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the fatty acid catalog with core and extended acids', function () {
    $this->seed(FattyAcidSeeder::class);

    expect(FattyAcid::query()->count())->toBe(15)
        ->and(FattyAcid::query()->where('key', 'lauric')->firstOrFail()->is_core)->toBeTrue()
        ->and(FattyAcid::query()->where('key', 'caprylic')->firstOrFail()->is_core)->toBeFalse()
        ->and(FattyAcid::query()->where('key', 'oleic')->firstOrFail()->default_group_key)->toBe('mu')
        ->and(FattyAcid::query()->where('key', 'ricinoleic')->firstOrFail()->iodine_factor)->toBe('0.901');
});

it('stores ingredient version fatty acid entries in a normalized table', function () {
    $this->seed(FattyAcidSeeder::class);

    $ingredient = Ingredient::factory()->create();
    $ingredientVersion = IngredientVersion::factory()->create([
        'ingredient_id' => $ingredient->id,
    ]);

    $lauric = FattyAcid::query()->where('key', 'lauric')->firstOrFail();
    $oleic = FattyAcid::query()->where('key', 'oleic')->firstOrFail();

    IngredientVersionFattyAcid::query()->create([
        'ingredient_version_id' => $ingredientVersion->id,
        'fatty_acid_id' => $lauric->id,
        'percentage' => 12.5,
    ]);

    IngredientVersionFattyAcid::query()->create([
        'ingredient_version_id' => $ingredientVersion->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 58,
    ]);

    expect($ingredientVersion->fresh()->fattyAcidEntries()->count())->toBe(2)
        ->and($ingredientVersion->fresh()->fattyAcidEntries()->with('fattyAcid')->get()->pluck('fattyAcid.key')->all())
        ->toBe(['lauric', 'oleic'])
        ->and($ingredientVersion->fresh()->normalizedFattyAcidProfile())
        ->toBe([
            'lauric' => 12.5,
            'oleic' => 58.0,
        ]);
});
