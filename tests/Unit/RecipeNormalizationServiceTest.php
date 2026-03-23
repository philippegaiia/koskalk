<?php

use App\Services\RecipeNormalizationService;

it('normalizes soap phases from percentages using oil weight as the basis', function () {
    $service = new RecipeNormalizationService;

    $normalized = $service->normalizeSoapRecipe([
        [
            'key' => 'saponified_oils',
            'name' => 'Saponified Oils',
            'items' => [
                ['ingredient_id' => 1, 'ingredient_version_id' => 11, 'percentage' => 70],
                ['ingredient_id' => 2, 'ingredient_version_id' => 12, 'percentage' => 30],
            ],
        ],
        [
            'key' => 'additives',
            'name' => 'Additives',
            'items' => [
                ['ingredient_id' => 3, 'ingredient_version_id' => 13, 'percentage' => 2],
            ],
        ],
        [
            'key' => 'fragrance',
            'name' => 'Fragrance',
            'items' => [
                ['ingredient_id' => 4, 'ingredient_version_id' => 14, 'percentage' => 3],
            ],
        ],
    ], 1000, 'percent');

    expect($normalized['totals']['oil_percentage'])->toBe(100.0)
        ->and($normalized['totals']['formula_percentage_of_oils'])->toBe(105.0)
        ->and($normalized['totals']['formula_weight'])->toBe(1050.0)
        ->and($normalized['phases'][0]['items'][0]['weight'])->toBe(700.0)
        ->and($normalized['phases'][1]['items'][0]['weight'])->toBe(20.0)
        ->and($normalized['phases'][2]['items'][0]['weight'])->toBe(30.0);
});

it('normalizes soap phases from weights back to percentages', function () {
    $service = new RecipeNormalizationService;

    $normalized = $service->normalizeSoapRecipe([
        [
            'key' => 'saponified_oils',
            'items' => [
                ['weight' => 450],
                ['weight' => 550],
            ],
        ],
        [
            'key' => 'fragrance',
            'items' => [
                ['weight' => 20],
            ],
        ],
    ], 1000, 'weight');

    expect($normalized['phases'][0]['items'][0]['percentage'])->toBe(45.0)
        ->and($normalized['phases'][0]['items'][1]['percentage'])->toBe(55.0)
        ->and($normalized['phases'][1]['items'][0]['percentage'])->toBe(2.0)
        ->and($normalized['totals']['formula_percentage_of_oils'])->toBe(102.0)
        ->and($normalized['totals']['formula_weight'])->toBe(1020.0);
});

it('rejects unsupported normalization modes', function () {
    $service = new RecipeNormalizationService;

    $service->normalizeSoapRecipe([], 1000, 'unknown');
})->throws(InvalidArgumentException::class, 'editing mode');
