<?php

use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use App\Models\ProductionBatchPackagingItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores production snapshot headers with frozen ingredient and packaging rows', function (): void {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_draft' => false,
        'version_number' => 3,
        'batch_size' => 1000,
        'batch_unit' => 'g',
    ]);

    $batch = ProductionBatch::factory()
        ->for($user)
        ->for($recipe)
        ->for($version, 'recipeVersion')
        ->create([
            'recipe_name' => 'Castile Soap',
            'recipe_version_number' => 3,
            'product_family_slug' => 'soap',
            'production_batch_number' => 'B-2026-001',
            'manufacture_date' => '2026-06-10',
            'batch_basis_label' => 'Oil quantity',
            'batch_basis_value' => 1000,
            'batch_basis_unit' => 'g',
            'units_produced' => 12,
            'currency' => 'EUR',
            'ingredient_total' => 8.5,
            'packaging_total' => 3,
            'total_cost' => 11.5,
            'cost_per_unit' => 0.9583,
            'production_notes' => 'Trace accelerated slightly.',
        ]);

    ProductionBatchIngredient::factory()
        ->for($batch)
        ->create([
            'phase_key' => 'saponified_oils',
            'phase_name' => 'Saponified oils',
            'position' => 1,
            'ingredient_name' => 'Olive Oil',
            'percentage' => 100,
            'quantity' => 1000,
            'unit' => 'g',
            'price_per_kg' => 8.5,
            'line_cost' => 8.5,
            'ingredient_lot_number' => 'OO-LOT-1',
        ]);

    ProductionBatchPackagingItem::factory()
        ->for($batch)
        ->create([
            'position' => 1,
            'name' => 'Soap box',
            'components_per_unit' => 1,
            'unit_cost' => 0.25,
            'cost_per_finished_unit' => 0.25,
            'line_cost' => 3,
        ]);

    expect($batch->ingredients)->toHaveCount(1)
        ->and($batch->packagingItems)->toHaveCount(1)
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-1')
        ->and($batch->packagingItems->first()->line_cost)->toBe('3.0000')
        ->and($recipe->productionBatches()->first()->is($batch))->toBeTrue()
        ->and($version->productionBatches()->first()->is($batch))->toBeTrue()
        ->and($user->productionBatches()->first()->is($batch))->toBeTrue();
});

it('preserves production snapshots when the source recipe is deleted', function (): void {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_draft' => false,
        'version_number' => 3,
    ]);

    $batch = ProductionBatch::factory()
        ->for($user)
        ->for($recipe)
        ->for($version, 'recipeVersion')
        ->create([
            'recipe_name' => 'Castile Soap',
            'recipe_version_number' => 3,
            'production_batch_number' => 'B-2026-001',
            'production_notes' => 'Trace accelerated slightly.',
        ]);

    ProductionBatchIngredient::factory()
        ->for($batch)
        ->create([
            'ingredient_name' => 'Olive Oil',
            'ingredient_lot_number' => 'OO-LOT-1',
            'line_cost' => 8.5,
        ]);

    ProductionBatchPackagingItem::factory()
        ->for($batch)
        ->create([
            'name' => 'Soap box',
            'line_cost' => 3,
        ]);

    $recipe->delete();

    $preservedBatch = ProductionBatch::query()
        ->with(['ingredients', 'packagingItems'])
        ->findOrFail($batch->id);

    expect($preservedBatch->recipe_id)->toBeNull()
        ->and($preservedBatch->recipe_version_id)->toBeNull()
        ->and($preservedBatch->recipe_name)->toBe('Castile Soap')
        ->and($preservedBatch->recipe_version_number)->toBe(3)
        ->and($preservedBatch->production_batch_number)->toBe('B-2026-001')
        ->and($preservedBatch->production_notes)->toBe('Trace accelerated slightly.')
        ->and($preservedBatch->ingredients)->toHaveCount(1)
        ->and($preservedBatch->ingredients->first()->ingredient_name)->toBe('Olive Oil')
        ->and($preservedBatch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-1')
        ->and($preservedBatch->packagingItems)->toHaveCount(1)
        ->and($preservedBatch->packagingItems->first()->name)->toBe('Soap box')
        ->and($preservedBatch->packagingItems->first()->line_cost)->toBe('3.0000');
});
