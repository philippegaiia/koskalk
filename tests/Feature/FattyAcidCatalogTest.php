<?php

use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds the fatty acid catalog with core and extended acids', function () {
    $this->seed(FattyAcidSeeder::class);

    expect(FattyAcid::query()->count())->toBe(20)
        ->and(FattyAcid::query()->where('key', 'lauric')->firstOrFail()->is_core)->toBeTrue()
        ->and(FattyAcid::query()->where('key', 'caprylic')->firstOrFail()->is_core)->toBeFalse()
        ->and(FattyAcid::query()->where('key', 'oleic')->firstOrFail()->default_group_key)->toBe('mu')
        ->and(FattyAcid::query()->where('key', 'ricinoleic')->firstOrFail()->iodine_factor)->toBe('0.901')
        ->and(FattyAcid::query()->where('key', 'gamma_linolenic')->firstOrFail()->default_group_key)->toBe('pu')
        ->and(FattyAcid::query()->where('key', 'nervonic')->firstOrFail()->iodine_factor)->toBe('0.662');
});

it('stores ingredient fatty acid entries in a normalized table', function () {
    $this->seed(FattyAcidSeeder::class);

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Test Oil',
    ]);

    $lauric = FattyAcid::query()->where('key', 'lauric')->firstOrFail();
    $oleic = FattyAcid::query()->where('key', 'oleic')->firstOrFail();

    IngredientFattyAcid::query()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $lauric->id,
        'percentage' => 12.5,
    ]);

    IngredientFattyAcid::query()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 58,
    ]);

    expect($ingredient->fresh()->fattyAcidEntries()->count())->toBe(2)
        ->and($ingredient->fresh()->fattyAcidEntries()->with('fattyAcid')->get()->pluck('fattyAcid.key')->all())
        ->toBe(['lauric', 'oleic'])
        ->and($ingredient->fresh()->normalizedFattyAcidProfile())
        ->toBe([
            'lauric' => 12.5,
            'oleic' => 58.0,
        ]);
});

it('reuses eager loaded fatty acid entries when normalizing the profile', function () {
    $this->seed(FattyAcidSeeder::class);

    $ingredient = Ingredient::factory()->create([
        'display_name' => 'Loaded Oil',
    ]);

    $lauric = FattyAcid::query()->where('key', 'lauric')->firstOrFail();
    $oleic = FattyAcid::query()->where('key', 'oleic')->firstOrFail();

    IngredientFattyAcid::query()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $lauric->id,
        'percentage' => 12.5,
    ]);

    IngredientFattyAcid::query()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 58,
    ]);

    $eagerLoadedIngredient = Ingredient::query()
        ->with('fattyAcidEntries.fattyAcid')
        ->findOrFail($ingredient->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($eagerLoadedIngredient->normalizedFattyAcidProfile())
        ->toBe([
            'lauric' => 12.5,
            'oleic' => 58.0,
        ])
        ->and(DB::getQueryLog())->toHaveCount(0);
});
