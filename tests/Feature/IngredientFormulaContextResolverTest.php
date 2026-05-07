<?php

use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Services\IngredientFormulaContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('expands composite ingredients into weighted formula contexts', function () {
    $lavenderOil = Ingredient::factory()->create([
        'display_name' => 'Lavender Essential Oil',
    ]);
    $orangeOil = Ingredient::factory()->create([
        'display_name' => 'Orange Essential Oil',
    ]);
    $fragranceBlend = Ingredient::factory()->create([
        'display_name' => 'Lavender Orange Blend',
    ]);

    IngredientComponent::factory()->create([
        'ingredient_id' => $fragranceBlend->id,
        'component_ingredient_id' => $lavenderOil->id,
        'percentage_in_parent' => 60,
        'sort_order' => 1,
    ]);
    IngredientComponent::factory()->create([
        'ingredient_id' => $fragranceBlend->id,
        'component_ingredient_id' => $orangeOil->id,
        'percentage_in_parent' => 40,
        'sort_order' => 2,
    ]);

    $contexts = app(IngredientFormulaContextResolver::class)->resolve([
        'oil_weight' => 100,
        'phase_items' => [
            'aromatics' => [
                [
                    'ingredient_id' => $fragranceBlend->id,
                    'weight' => 10,
                ],
            ],
        ],
    ], ['components']);

    expect($contexts)->toHaveCount(2)
        ->and($contexts[0]['ingredient_name'])->toBe('Lavender Essential Oil')
        ->and($contexts[0]['weight'])->toBe(6.0)
        ->and($contexts[1]['ingredient_name'])->toBe('Orange Essential Oil')
        ->and($contexts[1]['weight'])->toBe(4.0);
});

it('builds raw contexts from explicit weights and percentage rows', function () {
    $contexts = app(IngredientFormulaContextResolver::class)->raw([
        'oil_weight' => 200,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'name' => 'Olive Oil',
                    'weight' => 80,
                ],
                [
                    'name' => 'Coconut Oil',
                    'percentage' => 25,
                ],
            ],
        ],
    ]);

    expect($contexts)->toHaveCount(2)
        ->and($contexts[0]['ingredient_name'])->toBe('Olive Oil')
        ->and($contexts[0]['weight'])->toBe(80.0)
        ->and($contexts[1]['ingredient_name'])->toBe('Coconut Oil')
        ->and($contexts[1]['weight'])->toBe(50.0);
});
