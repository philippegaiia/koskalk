<?php

use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Support\CosIngFunctionDataset;
use Database\Seeders\IngredientFunctionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('ships no placeholder CosIng assignments as reviewed provenance', function () {
    $this->seed(IngredientFunctionSeeder::class);

    $assignments = app(CosIngFunctionDataset::class)->all();

    expect($assignments)->toBeEmpty()
        ->and(file_get_contents(base_path(CosIngFunctionDataset::DEFAULT_PATH)))
        ->not->toContain('COSING-REVIEW', 'placeholder');
});

it('rejects inactive functions and duplicate CosIng references', function (): void {
    IngredientFunction::factory()->create(['key' => 'inactive_function', 'is_active' => false]);
    IngredientFunction::factory()->create(['key' => 'emollient', 'is_active' => true]);
    $path = storage_path('framework/testing/'.Str::uuid().'.json');

    File::put($path, json_encode([
        'format' => 'soapkraft-cosing-ingredient-functions',
        'version' => 1,
        'assignments' => [[
            'catalog_key' => 'ONE',
            'inci_name' => 'ONE INCI',
            'cosing_reference' => '12345',
            'source_url' => 'https://single-market-economy.ec.europa.eu/example/12345',
            'verified_at' => '2026-08-08',
            'function_keys' => ['inactive_function'],
        ]],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => app(CosIngFunctionDataset::class)->all($path))
        ->toThrow(RuntimeException::class, 'unknown or inactive function keys');

    File::put($path, json_encode([
        'format' => 'soapkraft-cosing-ingredient-functions',
        'version' => 1,
        'assignments' => [
            [
                'catalog_key' => 'ONE',
                'inci_name' => 'ONE INCI',
                'cosing_reference' => '12345',
                'source_url' => 'https://single-market-economy.ec.europa.eu/example/12345',
                'verified_at' => '2026-08-08',
                'function_keys' => ['emollient'],
            ],
            [
                'catalog_key' => 'TWO',
                'inci_name' => 'TWO INCI',
                'cosing_reference' => '12345',
                'source_url' => 'https://single-market-economy.ec.europa.eu/example/12345',
                'verified_at' => '2026-08-08',
                'function_keys' => ['emollient'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    expect(fn () => app(CosIngFunctionDataset::class)->all($path))
        ->toThrow(RuntimeException::class, 'duplicate CosIng references');

    File::delete($path);
});

it('requires exact normalized INCI agreement with an existing platform ingredient', function (): void {
    $ingredient = Ingredient::factory()->create([
        'catalog_key' => 'OB1',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'owner_type' => null,
    ]);
    $assignments = [[
        'catalog_key' => 'OB1',
        'inci_name' => 'DIFFERENT INCI',
        'cosing_reference' => '12345',
        'source_url' => 'https://single-market-economy.ec.europa.eu/example/12345',
        'verified_at' => '2026-08-08',
        'function_keys' => ['emollient'],
    ]];

    expect(fn () => app(CosIngFunctionDataset::class)->validateAgainstCatalog($assignments))
        ->toThrow(RuntimeException::class, 'does not exactly match catalog INCI');

    $ingredient->update(['inci_name' => '  Olea   Europaea Fruit Oil ']);
    $assignments[0]['inci_name'] = 'OLEA EUROPAEA FRUIT OIL';

    expect(app(CosIngFunctionDataset::class)->validateAgainstCatalog($assignments))->toBe($assignments);
});
