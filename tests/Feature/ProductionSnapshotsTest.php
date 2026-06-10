<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use App\Models\ProductionBatchPackagingItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Services\RecipeVersionCostPreviewBuilder;
use App\Services\RecipeWorkbenchService;
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

it('builds numeric production cost preview rows from live costing data', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'currency' => 'EUR',
    ]);

    $version->packagingItems()->create([
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'quantity' => 1,
    ]);

    $preview = app(RecipeVersionCostPreviewBuilder::class)->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1500,
        unitsProduced: 12,
    );

    expect($preview['currency'])->toBe('EUR')
        ->and($preview['ingredient_total'])->toBe(12.75)
        ->and($preview['packaging_total'])->toBe(3.0)
        ->and($preview['total_cost'])->toBe(15.75)
        ->and($preview['cost_per_unit'])->toBe(1.3125)
        ->and($preview['has_unpriced_rows'])->toBeFalse()
        ->and($preview['ingredient_rows'][0]['ingredient_id'])->toBe($ingredient->id)
        ->and($preview['ingredient_rows'][0]['phase_key'])->toBe('saponified_oils')
        ->and($preview['ingredient_rows'][0]['phase_name'])->toBe('Saponified oils')
        ->and($preview['ingredient_rows'][0]['percentage'])->toBe(100.0)
        ->and($preview['ingredient_rows'][0]['quantity'])->toBe(1500.0)
        ->and($preview['ingredient_rows'][0]['unit'])->toBe('g')
        ->and($preview['ingredient_rows'][0]['line_cost'])->toBe(12.75)
        ->and($preview['packaging_rows'][0]['user_packaging_item_id'])->toBe($packagingItem->id)
        ->and($preview['packaging_rows'][0]['components_per_unit'])->toBe(1.0)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBe(3.0);
});

it('marks packaging plan rows without source prices as unpriced in production cost previews', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'user_packaging_item_id' => null,
        'name' => 'Unpriced wrap',
        'components_per_unit' => 2,
        'position' => 1,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    $preview = app(RecipeVersionCostPreviewBuilder::class)->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1000,
        unitsProduced: 10,
    );

    expect($preview['packaging_total'])->toBe(0.0)
        ->and($preview['total_cost'])->toBe(8.5)
        ->and($preview['has_unpriced_rows'])->toBeTrue()
        ->and($preview['packaging_rows'][0]['name'])->toBe('Unpriced wrap')
        ->and($preview['packaging_rows'][0]['components_per_unit'])->toBe(2.0)
        ->and($preview['packaging_rows'][0]['unit_cost'])->toBeNull()
        ->and($preview['packaging_rows'][0]['cost_per_finished_unit'])->toBe(0.0)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBe(0.0)
        ->and($preview['packaging_rows'][0]['is_unpriced'])->toBeTrue();
});

/** @return array{0: User, 1: Recipe, 2: RecipeVersion, 3: Ingredient} */
function productionSnapshotSoapRecipe(): array
{
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
        'calculation_basis' => 'oil_weight',
    ]);
    $ingredient = productionSnapshotIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        productionSnapshotDraftPayload($ingredient, 'Snapshot Soap'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        productionSnapshotDraftPayload($ingredient, 'Snapshot Soap'),
        $recipe,
    );

    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $version, $ingredient];
}

function productionSnapshotIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/** @return array<string, mixed> */
function productionSnapshotDraftPayload(Ingredient $ingredient, string $name): array
{
    return [
        'name' => $name,
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
