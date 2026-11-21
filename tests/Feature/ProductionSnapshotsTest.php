<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\OwnerType;
use App\Enums\Visibility;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\InterfaceTranslation;
use App\Models\PackagingItem;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use App\Models\ProductionBatchPackagingItem;
use App\Models\ProductSize;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\PackagingItemAuthoringService;
use App\Services\ProductionSnapshotService;
use App\Services\RecipeVersionCostPreviewBuilder;
use App\Services\RecipeVersionViewDataBuilder;
use App\Services\RecipeWorkbenchService;
use Dom\HTMLDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach ([
        'CH1' => IngredientSubcategory::SodiumHydroxide,
        'CH3' => IngredientSubcategory::PotassiumHydroxide,
    ] as $catalogKey => $subcategory) {
        Ingredient::factory()->create([
            'catalog_key' => $catalogKey,
            'category' => IngredientCategory::SoapmakingAlkalis,
            'subcategory' => $subcategory,
            'display_name' => $subcategory === IngredientSubcategory::SodiumHydroxide
                ? 'Sodium hydroxide'
                : 'Potassium hydroxide',
            'is_active' => true,
        ]);
    }
});

it('stores production snapshot headers with frozen ingredient and packaging rows', function (): void {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_current' => false,
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
        'is_current' => false,
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
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'currency' => 'EUR',
    ]);

    $version->packagingItems()->create([
        'packaging_item_id' => $packagingItem->id,
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
    productionSnapshotPriceAlkali($costing);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'quantity' => 1,
    ]);

    Workspace::withoutGlobalScopes()->findOrFail($version->workspace_id)->update(['default_currency' => 'USD']);

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
        ->and($preview['packaging_rows'][0]['packaging_item_id'])->toBe($packagingItem->id)
        ->and($preview['packaging_rows'][0]['components_per_unit'])->toBe(1.0)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBe(3.0);
});

it('prices production ingredient quantities using the saved batch unit', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->update([
        'batch_size' => 2,
        'batch_unit' => 'lb',
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 2,
        'oil_unit_for_costing' => 'lb',
        'units_produced' => 4,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 10,
    ]);
    productionSnapshotPriceAlkali($costing);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version->fresh(), $user, [
        'production_batch_number' => 'B-2026-LB',
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 2,
        'units_produced' => 4,
    ]);

    $oilRow = $batch->ingredients->firstWhere('phase_key', 'saponified_oils');

    expect($batch->batch_basis_unit)->toBe('lb')
        ->and($oilRow->quantity)->toBe('2.0000')
        ->and($oilRow->unit)->toBe('lb')
        ->and($batch->ingredient_total)->toBe('9.0718')
        ->and($batch->total_cost)->toBe('9.0718')
        ->and($batch->cost_per_unit)->toBe('2.2680');
});

it('scales saved lye liquid quantities in non gram batches without presenting their share as oil basis', function (): void {
    [$user, $recipe, $version, $oil] = productionSnapshotSoapRecipe();
    $hydrosol = Ingredient::factory()->create([
        'display_name' => 'Rose hydrosol',
        'is_active' => true,
    ]);

    $version->forceFill([
        'batch_size' => 2,
        'batch_unit' => 'kg',
        'batch_mass_grams' => '2000.000000000',
    ])->save();
    $oilPhase = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $version->id)
        ->where('slug', 'saponified_oils')
        ->firstOrFail();
    RecipeItem::withoutGlobalScopes()
        ->where('recipe_phase_id', $oilPhase->id)
        ->firstOrFail()
        ->update(['weight' => 2]);
    $lyeLiquidPhase = RecipePhase::withoutGlobalScopes()
        ->where('recipe_version_id', $version->id)
        ->where('slug', 'lye_water')
        ->firstOrFail();
    RecipeItem::factory()->create([
        'recipe_version_id' => $version->id,
        'recipe_phase_id' => $lyeLiquidPhase->id,
        'ingredient_id' => $hydrosol->id,
        'owner_type' => $version->owner_type,
        'owner_id' => $version->owner_id,
        'workspace_id' => $version->workspace_id,
        'visibility' => $version->visibility,
        'position' => 1,
        'percentage' => 70,
        'weight' => 0.532,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 3,
        'oil_unit_for_costing' => 'kg',
        'units_produced' => 6,
        'currency' => 'EUR',
    ]);

    foreach ([[$oil, 'saponified_oils'], [$hydrosol, 'lye_water']] as [$ingredient, $phaseKey]) {
        RecipeVersionCostingItem::query()->create([
            'recipe_version_costing_id' => $costing->id,
            'ingredient_id' => $ingredient->id,
            'phase_key' => $phaseKey,
            'position' => 1,
            'price_per_kg' => 10,
        ]);
    }

    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromCosting(
        recipe: $recipe,
        version: $version->fresh(),
        costing: $costing,
        batchBasisValue: 3,
        unitsProduced: 6,
    );
    $rows = collect($preview['ingredient_rows'])->keyBy('phase_key');

    expect($rows['saponified_oils']['quantity'])->toBe(3.0)
        ->and($rows['saponified_oils']['percentage_basis'])->toBe('oils')
        ->and($rows['saponified_oils']['percentage_label'])->toBe('%')
        ->and($rows['lye_water']['quantity'])->toBe(0.798)
        ->and($rows['lye_water']['unit'])->toBe('kg')
        ->and($rows['lye_water']['percentage'])->toBe(26.6)
        ->and($rows['lye_water']['percentage_basis'])->toBe('oils')
        ->and($rows['lye_water']['percentage_label'])->toBe('%')
        ->and($rows['lye_water']['phase_name'])->toBe('Dilution liquids');
});

it('defaults a production preview from canonical formula mass', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();

    $version->forceFill([
        'batch_size' => 2.205,
        'batch_unit' => 'lb',
        'batch_mass_grams' => 1000,
    ])->save();

    $preview = app(ProductionSnapshotService::class)->preview(
        $recipe,
        $version->fresh(),
        $user,
        [],
    );

    expect($preview['batch_basis_unit'])->toBe('lb')
        ->and($preview['batch_basis_value'])->toBe(2.204622622);
});

it('costs duplicate planned packaging rows independently', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'currency' => 'EUR',
    ]);

    $version->packagingItems()->createMany([
        [
            'packaging_item_id' => $packagingItem->id,
            'name' => 'Soap box',
            'components_per_unit' => 1,
            'position' => 1,
        ],
        [
            'packaging_item_id' => $packagingItem->id,
            'name' => 'Soap box',
            'components_per_unit' => 2,
            'position' => 2,
        ],
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
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => 0.31,
        'quantity' => 1,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => 0.72,
        'quantity' => 2,
    ]);

    $preview = app(RecipeVersionCostPreviewBuilder::class)->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1000,
        unitsProduced: 10,
    );

    expect($preview['packaging_rows'])->toHaveCount(2)
        ->and($preview['packaging_rows'][0]['components_per_unit'])->toBe(1.0)
        ->and($preview['packaging_rows'][0]['unit_cost'])->toBe(0.31)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBe(3.1)
        ->and($preview['packaging_rows'][1]['components_per_unit'])->toBe(2.0)
        ->and($preview['packaging_rows'][1]['unit_cost'])->toBe(0.72)
        ->and($preview['packaging_rows'][1]['line_cost'])->toBe(14.4)
        ->and($preview['packaging_total'])->toBe(17.5);
});

it('records a soap production snapshot and freezes current prices', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $costingRows = productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25, alkaliPrice: 4.0);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-010',
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1500,
        'units_produced' => 12,
        'production_notes' => 'Reached medium trace.',
        'ingredient_lot_numbers' => [
            "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-10',
        ],
    ]);

    expect($batch)->toBeInstanceOf(ProductionBatch::class)
        ->and($batch->recipe_name)->toBe('Snapshot Soap')
        ->and($batch->product_family_slug)->toBe('soap')
        ->and($batch->production_batch_number)->toBe('B-2026-010')
        ->and($batch->batch_basis_label)->toBe('Oil quantity')
        ->and($batch->batch_basis_value)->toBe('1500.000')
        ->and($batch->batch_basis_unit)->toBe('g')
        ->and($batch->units_produced)->toBe(12)
        ->and($batch->packaging_total)->toBe('3.0000')
        ->and($batch->has_unpriced_rows)->toBeFalse()
        ->and($batch->ingredients)->toHaveCount(2);

    $oilRow = $batch->ingredients->firstWhere('phase_key', 'saponified_oils');
    $alkaliRow = $batch->ingredients->firstWhere('phase_key', 'lye_alkali');
    $alkaliCost = round((float) $alkaliRow->quantity / 1000 * 4, 4);

    expect($oilRow->ingredient_name)->toBe('Olive Oil')
        ->and($oilRow->quantity)->toBe('1500.0000')
        ->and($oilRow->ingredient_lot_number)->toBe('OO-LOT-10')
        ->and($alkaliRow->ingredient_name)->toBe('Sodium hydroxide')
        ->and((float) $alkaliRow->quantity)->toBeGreaterThan(0)
        ->and($alkaliRow->price_per_kg)->toBe('4.0000')
        ->and((float) $alkaliRow->line_cost)->toEqualWithDelta($alkaliCost, 0.0001)
        ->and((float) $batch->ingredient_total)->toEqualWithDelta(12.75 + $alkaliCost, 0.0001)
        ->and((float) $batch->total_cost)->toEqualWithDelta(15.75 + $alkaliCost, 0.0001)
        ->and($batch->packagingItems)->toHaveCount(1)
        ->and($batch->packagingItems->first()->name)->toBe('Soap box')
        ->and($batch->production_notes)->toBe('Reached medium trace.');

    $costingRows['costing_item']->update([
        'price_per_kg' => 99,
    ]);
    $costingRows['packaging_item']->update([
        'unit_cost' => 9,
    ]);
    $costingRows['packaging_costing_item']->update([
        'unit_cost' => 7,
    ]);

    $recordedBatch = $batch->fresh(['ingredients', 'packagingItems']);

    expect($recordedBatch->ingredient_total)->toBe($batch->ingredient_total)
        ->and($recordedBatch->packaging_total)->toBe('3.0000')
        ->and($recordedBatch->total_cost)->toBe($batch->total_cost)
        ->and($recordedBatch->ingredients->firstWhere('phase_key', 'saponified_oils')->price_per_kg)->toBe('8.5000')
        ->and($recordedBatch->ingredients->firstWhere('phase_key', 'saponified_oils')->line_cost)->toBe('12.7500')
        ->and($recordedBatch->packagingItems->first()->unit_cost)->toBe('0.2500')
        ->and($recordedBatch->packagingItems->first()->line_cost)->toBe('3.0000');
});

it('records the calculated alkali and plain water in a soap batch', function (): void {
    $water = Ingredient::factory()->create([
        'catalog_key' => 'CH2',
        'category' => IngredientCategory::WaterSolventsCarriers,
        'subcategory' => IngredientSubcategory::Water,
        'display_name' => 'Water',
        'is_active' => true,
    ]);
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $costingRows = productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costingRows['costing_item']->recipe_version_costing_id,
        'ingredient_id' => $water->id,
        'phase_key' => 'implicit_water',
        'position' => 1,
        'price_per_kg' => 0.5,
    ]);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1000,
        'units_produced' => 10,
        'ingredient_lot_numbers' => [
            "{$water->id}:implicit_water:1" => 'RO-TANK-3',
        ],
    ]);

    $waterRow = $batch->ingredients->firstWhere('phase_key', 'implicit_water');

    // 38% water of 1000 g oils is the plain water the batch uses.
    expect($batch->has_unpriced_rows)->toBeFalse()
        ->and($batch->ingredients->pluck('phase_key')->sort()->values()->all())->toBe(['implicit_water', 'lye_alkali', 'saponified_oils'])
        ->and($waterRow->ingredient_id)->toBe($water->id)
        ->and($waterRow->quantity)->toBe('380.0000')
        ->and($waterRow->price_per_kg)->toBe('0.5000')
        ->and($waterRow->line_cost)->toBe('0.1900')
        ->and($waterRow->ingredient_lot_number)->toBe('RO-TANK-3')
        ->and($batch->ingredient_total)->toBe('8.6900');
});

it('recalculates production costs on the formula sheet before recording', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    rememberIngredientPriceForWorkspace($user, $ingredient, '8.5');
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'currency' => 'EUR',
    ]);
    $version->packagingItems()->create([
        'packaging_item_id' => $packagingItem->id,
        'name' => $packagingItem->name,
        'components_per_unit' => 2,
        'position' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe, 'oil_weight' => 1500, 'units_produced' => 12]))
        ->assertOk()
        ->assertSee('12.75 EUR')
        ->assertSee('6 EUR')
        ->assertSee('18.75 EUR')
        ->assertSee('1.56 EUR')
        ->assertSee('name="batch_basis" value="1500"', false);

    $this->assertDatabaseCount('production_batches', 0);
    $this->assertDatabaseCount('recipe_version_costings', 0);

    $this->post(route('recipes.production-batches.store', $recipe), [
        'recipe_version_id' => $version->id,
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1500,
        'units_produced' => 12,
    ])->assertSessionHas('status', __('batch_log.status.recorded'));

    $batch = ProductionBatch::query()->sole();
    expect($batch->ingredient_total)->toBe('12.7500')
        ->and($batch->packaging_total)->toBe('6.0000')
        ->and($batch->total_cost)->toBe('18.7500')
        ->and($batch->cost_per_unit)->toBe('1.5625');
});

it('blocks recording a production snapshot when the plan production batch limit is reached', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $plan = Plan::factory()
        ->hasLimit('production_batches', 0)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'recipe_version_id' => $version->id,
            'production_batch_number' => 'B-2026-010',
            'manufacture_date' => '2026-06-10',
            'batch_basis' => 1500,
            'units_produced' => 12,
        ])
        ->assertSessionHasErrors(['plan' => 'Your current plan allows 0 saved production batches.']);

    expect(ProductionBatch::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('hides production recording controls when the plan production batch limit is reached', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $plan = Plan::factory()
        ->hasLimit('production_batches', 0)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertDontSee('Record production')
        ->assertDontSee('Production batch number');
});

it('tells the owner their plan does not include production batches instead of blaming ownership', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $plan = Plan::factory()
        ->hasLimit('production_batches', 0)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Your current plan does not include production batch records.')
        ->assertDontSee('only the owner can record production batches');
});

it('renders the saved formula batch log and production snapshot pages in the interface locale', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-FR',
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1500,
        'units_produced' => 12,
        'production_notes' => null,
        'ingredient_lot_numbers' => [],
    ]);

    collect(File::json(database_path('seeders/data/interface-translations.json'))['translations'])
        ->whereIn('group', ['batch_log', 'formula_documents', 'workbench'])
        ->each(fn (array $row): InterfaceTranslation => InterfaceTranslation::query()->create($row));
    app()->setLocale('fr');

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee(__('batch_log.record.title'))
        ->assertSee(__('batch_log.history.title'))
        ->assertSee(__('formula_documents.packaging_plan.title'))
        ->assertDontSee('Record production')
        ->assertDontSee('Production history')
        ->assertDontSee('Packaging plan');

    $this->actingAs($user)
        ->get(route('production-batches.show', $batch))
        ->assertSuccessful()
        ->assertSee(__('batch_log.snapshot.ingredients_title'))
        ->assertSee(__('formula_documents.scale.oil_quantity'))
        ->assertDontSee('Frozen ingredient rows')
        ->assertDontSee('Oil quantity');

    $this->actingAs($user)
        ->get(route('production-batches.print', $batch))
        ->assertSuccessful()
        ->assertSee(__('batch_log.costs.summary'))
        ->assertDontSee('Cost summary');

    expect(__('batch_log.record.title'))->not->toBe('Record production');
});

it('keeps existing production history visible when the plan production batch limit is reached', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-DOWNGRADE',
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1500,
        'units_produced' => 12,
        'production_notes' => null,
        'ingredient_lot_numbers' => [],
    ]);

    $plan = Plan::factory()
        ->hasLimit('production_batches', 0)
        ->create(['is_default' => true]);

    $user->entitlements()->create([
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Production history')
        ->assertSee('B-2026-DOWNGRADE')
        ->assertSee('View')
        ->assertSee('Print')
        ->assertDontSee('Record production');
});

it('keeps production snapshots frozen after live price propagation', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $costingRows = productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-011',
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1500,
        'units_produced' => 12,
        'production_notes' => null,
        'ingredient_lot_numbers' => [],
    ]);

    rememberIngredientPriceForWorkspace($user, $ingredient, 12.75);
    app(PackagingItemAuthoringService::class)->updateUnitCost($costingRows['packaging_item'], $user, 0.89);

    $recordedBatch = $batch->fresh(['ingredients', 'packagingItems']);
    $liveCosting = RecipeVersionCosting::query()
        ->where('recipe_version_id', $version->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($recordedBatch->ingredient_total)->toBe('12.7500')
        ->and($recordedBatch->packaging_total)->toBe('3.0000')
        ->and($recordedBatch->total_cost)->toBe('15.7500')
        ->and($recordedBatch->cost_per_unit)->toBe('1.3125')
        ->and($recordedBatch->ingredients->firstWhere('phase_key', 'saponified_oils')->price_per_kg)->toBe('8.5000')
        ->and($recordedBatch->ingredients->firstWhere('phase_key', 'saponified_oils')->line_cost)->toBe('12.7500')
        ->and($recordedBatch->packagingItems->first()->unit_cost)->toBe('0.2500')
        ->and($recordedBatch->packagingItems->first()->line_cost)->toBe('3.0000')
        ->and(RecipeVersionCostingItem::query()
            ->where('recipe_version_costing_id', $liveCosting->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('phase_key', 'saponified_oils')
            ->where('position', 1)
            ->value('price_per_kg'))->toBe('12.7500')
        ->and(RecipeVersionCostingPackagingItem::query()
            ->where('recipe_version_costing_id', $liveCosting->id)
            ->where('packaging_item_id', $costingRows['packaging_item']->id)
            ->value('unit_cost'))->toBe('0.8900');
});

it('stores a production snapshot from the saved formula route', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'recipe_version_id' => $version->id,
            'production_batch_number' => 'B-2026-020',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1500,
            'units_produced' => 12,
            'production_notes' => 'QC: pH checked.',
            'ingredient_lot_numbers' => [
                "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-20',
            ],
        ])
        ->assertRedirect();

    $batch = ProductionBatch::query()->firstOrFail();

    expect($batch->production_batch_number)->toBe('B-2026-020')
        ->and($batch->production_notes)->toBe('QC: pH checked.')
        ->and($batch->ingredients->firstWhere('phase_key', 'saponified_oils')->ingredient_lot_number)->toBe('OO-LOT-20');
});

it('records the posted production version instead of a newer saved version', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_current' => false,
        'version_number' => 99,
        'batch_size' => 2000,
        'batch_unit' => 'g',
    ]);

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'recipe_version_id' => $version->id,
            'production_batch_number' => 'B-2026-021',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1500,
            'units_produced' => 12,
        ])
        ->assertRedirect();

    expect(ProductionBatch::query()->firstOrFail())
        ->recipe_version_id->toBe($version->id)
        ->recipe_version_number->toBe($version->version_number);
});

it('shows record production controls on the saved formula page', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe]))
        ->assertSuccessful()
        ->assertSee('Record production')
        ->assertSee('Production batch number')
        ->assertSee('Manufacture date')
        ->assertSee('Oil quantity')
        ->assertSee('Units produced')
        ->assertSee('Ingredient lot numbers')
        ->assertSee('Production notes')
        ->assertSee('name="recipe_version_id"', false)
        ->assertSee('value="'.$version->id.'"', false)
        ->assertSee('Ingredient cost')
        ->assertSee('Packaging cost')
        ->assertSee('Cost per finished unit')
        ->assertSee('Record production')
        ->assertDontSee('Prepare batch');
});

it('shows record production controls on the legacy saved version page', function (): void {
    [$user, $recipe, , $ingredient] = productionSnapshotSoapRecipe();
    $savedVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->orderByDesc('version_number')
        ->firstOrFail();
    productionSnapshotAttachCosting($user, $savedVersion, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe, 'version' => $savedVersion]))
        ->assertSuccessful()
        ->assertSee('Record production')
        ->assertSee('Production batch number')
        ->assertSee('Oil quantity')
        ->assertSee('Ingredient cost')
        ->assertSee('Cost per finished unit');
});

it('shows production history only for the production batch owner', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-040',
        'manufacture_date' => '2026-06-13',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Production history')
        ->assertSee('B-2026-040')
        ->assertSee(route('production-batches.show', $batch), false);

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.saved', $recipe))
        ->assertNotFound();
});

it('keeps newly recorded production batches visible in the saved formula history', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $yesterday = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-yesterday',
        'manufacture_date' => '2026-06-11',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);
    $today = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-today',
        'manufacture_date' => '2026-06-12',
        'batch_basis' => 1000,
        'units_produced' => 12,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Production history')
        ->assertSee('B-2026-yesterday')
        ->assertSee('B-2026-today')
        ->assertSee(route('production-batches.show', $yesterday), false)
        ->assertSee(route('production-batches.show', $today), false);
});

it('does not allow another user to view a production snapshot', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-030',
        'manufacture_date' => '2026-06-12',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('production-batches.show', $batch))
        ->assertForbidden();
});

it('searches older production history by its recorded product name or batch number', function (string $search): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();
    $batches = ProductionBatch::factory()->for($user)->for($recipe)->for($version, 'recipeVersion');
    $olderBatch = $batches->create([
        'workspace_id' => $recipe->workspace_id,
        'recipe_name' => 'Original Castile',
        'production_batch_number' => 'TRIAL-101',
        'manufacture_date' => '2026-06-01',
    ]);
    $batches->count(8)->create([
        'workspace_id' => $recipe->workspace_id,
        'recipe_name' => 'Renamed Soap',
        'manufacture_date' => '2026-06-12',
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe, 'batch_search' => $search]))
        ->assertOk()
        ->assertSee('Original Castile')
        ->assertSee('TRIAL-101')
        ->assertSee(route('production-batches.show', $olderBatch), false)
        ->assertDontSee('Renamed Soap');
})->with(['product name' => 'castile', 'batch number' => 'trial-101']);

it('lets a workspace editor delete their own mistaken batch and retry', function (): void {
    [$owner, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($owner, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->create(['user_id' => $owner->id, 'plan_id' => $plan->id]);
    $editor = User::factory()->create(['active_workspace_id' => $recipe->workspace_id]);
    WorkspaceMember::factory()->create(['user_id' => $editor->id, 'workspace_id' => $recipe->workspace_id, 'role' => WorkspaceMemberRole::Editor]);
    $input = ['manufacture_date' => '2026-09-27', 'batch_basis' => 1000, 'units_produced' => 10];
    $service = app(ProductionSnapshotService::class);
    $ownBatch = $service->record($recipe, $version, $editor, $input);
    $otherBatch = $service->record($recipe, $version, $owner, $input);

    $this->actingAs($editor)
        ->get(route('recipes.saved', $recipe))
        ->assertOk()
        ->assertSee('action="'.route('production-batches.destroy', $ownBatch).'"', false)
        ->assertDontSee('action="'.route('production-batches.destroy', $otherBatch).'"', false);

    $this->delete(route('production-batches.destroy', $otherBatch))->assertForbidden();
    $this->delete(route('production-batches.destroy', $ownBatch))->assertRedirect(route('recipes.saved', $recipe));
    $this->assertModelMissing($ownBatch);
    $this->assertModelExists($otherBatch);

    $this->post(route('recipes.production-batches.store', $recipe), [...$input, 'recipe_version_id' => $version->id])
        ->assertSessionHas('status', __('batch_log.status.recorded'));
});

it('allows the owner to delete a production snapshot and its rows', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-031',
        'manufacture_date' => '2026-06-12',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    expect(ProductionBatch::query()->count())->toBe(1)
        ->and($batch->ingredients()->count())->toBe(2)
        ->and($batch->packagingItems()->count())->toBe(1);

    $this->actingAs($user)
        ->delete(route('production-batches.destroy', $batch))
        ->assertRedirect(route('recipes.saved', $recipe));

    expect(ProductionBatch::query()->count())->toBe(0)
        ->and(ProductionBatchIngredient::query()->count())->toBe(0)
        ->and(ProductionBatchPackagingItem::query()->count())->toBe(0);
});

it('does not allow another user to delete a production snapshot', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-032',
        'manufacture_date' => '2026-06-12',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs(User::factory()->create())
        ->delete(route('production-batches.destroy', $batch))
        ->assertForbidden();

    expect(ProductionBatch::query()->count())->toBe(1);
});

it('shows a read-only production snapshot with editable annotations', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-050',
        'manufacture_date' => '2026-06-14',
        'batch_basis' => 1000,
        'units_produced' => 10,
        'production_notes' => 'Initial notes',
    ]);

    $this->actingAs($user)
        ->get(route('production-batches.show', $batch))
        ->assertSuccessful()
        ->assertSee('Production snapshot')
        ->assertSee('B-2026-050')
        ->assertSee('Oil quantity')
        ->assertSee('1000')
        ->assertSee('Olive Oil')
        ->assertSee('Soap box')
        ->assertSee('Initial notes')
        ->assertSee('Production notes')
        ->assertSee('Save notes')
        ->assertSee('Delete')
        ->assertSee('action="'.route('production-batches.destroy', $batch).'"', false)
        ->assertDontSee('Recalculate');
});

it('retains company owner access when the batch author is another user', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-051',
        'manufacture_date' => '2026-06-14',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $otherUser = User::factory()->create();
    $batch->user_id = $otherUser->id;
    $batch->save();

    $this->actingAs($user)
        ->get(route('production-batches.show', $batch))
        ->assertOk()->assertSee('Delete');
});

it('edits production annotations without recalculating frozen totals', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-060',
        'manufacture_date' => '2026-06-15',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs($user)
        ->patch(route('production-batches.update', $batch), [
            'production_batch_number' => 'B-2026-060-A',
            'production_notes' => 'Updated QC notes.',
            'ingredient_lot_numbers' => [
                "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-60',
            ],
            'batch_basis' => 9000,
            'units_produced' => 99,
        ])
        ->assertRedirect(route('production-batches.show', $batch));

    $batch->refresh();

    expect($batch->production_batch_number)->toBe('B-2026-060-A')
        ->and($batch->production_notes)->toBe('Updated QC notes.')
        ->and($batch->batch_basis_value)->toBe('1000.000')
        ->and($batch->units_produced)->toBe(10)
        ->and($batch->total_cost)->toBe('11.0000')
        ->and($batch->ingredients->firstWhere('phase_key', 'saponified_oils')->ingredient_lot_number)->toBe('OO-LOT-60');
});

it('prints production notes without helper text and leaves writing space', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-070',
        'manufacture_date' => '2026-06-16',
        'batch_basis' => 1000,
        'units_produced' => 10,
        'production_notes' => 'Cured on rack A.',
    ]);

    $this->actingAs($user)
        ->get(route('production-batches.print', $batch))
        ->assertSuccessful()
        ->assertSee('Production notes')
        ->assertSee('Cured on rack A.')
        ->assertSee('min-h-[8rem]', false)
        ->assertDontSee('Use this space')
        ->assertDontSee('helper');
});

it('updates production lot numbers without clearing omitted annotations', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-035',
        'manufacture_date' => '2026-06-12',
        'batch_basis' => 1000,
        'units_produced' => 10,
        'production_notes' => 'Keep these notes.',
    ]);

    $this->actingAs($user)
        ->patch(route('production-batches.update', $batch), [
            'ingredient_lot_numbers' => [
                "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-35',
            ],
        ])
        ->assertRedirect(route('production-batches.show', $batch));

    $batch = $batch->fresh(['ingredients']);

    expect($batch->production_batch_number)->toBe('B-2026-035')
        ->and($batch->production_notes)->toBe('Keep these notes.')
        ->and($batch->ingredients->firstWhere('phase_key', 'saponified_oils')->ingredient_lot_number)->toBe('OO-LOT-35');
});

it('does not allow future workspace members to store production snapshots during the owner-only MVP', function (): void {
    [$owner, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $editor = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();

    WorkspaceMember::factory()->for($workspace)->for($editor)->create([
        'role' => WorkspaceMemberRole::Editor,
    ]);

    $recipe->update([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Workspace,
        'created_by' => $owner->id,
    ]);

    productionSnapshotAttachCosting($editor, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($editor)
        ->post(route('recipes.production-batches.store', $recipe), [
            'recipe_version_id' => $version->id,
            'production_batch_number' => 'B-2026-036',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1000,
            'units_produced' => 10,
        ])
        ->assertNotFound();

    expect(ProductionBatch::query()->where('user_id', $editor->id)->count())->toBe(0);
});

it('validates required production recording fields', function (): void {
    [$user, $recipe] = productionSnapshotSoapRecipe();

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'manufacture_date' => '',
            'batch_basis' => 0,
            'units_produced' => 0,
        ])
        ->assertSessionHasErrors(['recipe_version_id', 'manufacture_date', 'batch_basis', 'units_produced']);
});

it('records an unpriced production batch as a snapshot and shows its cost as incomplete', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();

    $this->actingAs($user)
        ->from(route('recipes.saved', $recipe))
        ->post(route('recipes.production-batches.store', $recipe), [
            'recipe_version_id' => $version->id,
            'production_batch_number' => 'B-2026-040',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1000,
            'units_produced' => 10,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $batch = ProductionBatch::query()->sole();

    expect($batch->has_unpriced_rows)->toBeTrue()
        ->and($batch->ingredients->every(fn (ProductionBatchIngredient $row): bool => $row->price_per_kg === null))->toBeTrue();

    $this->actingAs($user)
        ->get(route('production-batches.show', $batch))
        ->assertSuccessful()
        ->assertSee('Cost incomplete')
        ->assertSee('Not priced');

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Cost incomplete');
});

it('records only the chosen size and freezes its label on a basic batch', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $version->update(['is_current' => false]);
    ['packaging_item' => $packagingItem] = productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $large = ProductSize::factory()->for($recipe)->create(['name' => 'Large bar']);
    $small = ProductSize::factory()->for($recipe)->create(['name' => 'Small bar', 'position' => 2]);
    $version->packagingItems()->update(['product_size_id' => $large->id]);
    $version->packagingItems()->create([
        'product_size_id' => $small->id,
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Small box',
        'components_per_unit' => 1,
        'position' => 2,
    ]);
    $service = app(ProductionSnapshotService::class);
    $input = ['manufacture_date' => '2026-06-12', 'batch_basis' => 1000, 'units_produced' => 10];
    $message = __('batch_log.validation.size_required');

    expect(fn () => $service->preview($recipe, $version, $user, $input))->toThrow(ValidationException::class, $message);

    $this->actingAs($user)->get(route('recipes.version', [
        'recipe' => $recipe,
        'version' => $version,
        'batch_product_size' => $small->public_id,
        'units_produced' => 20,
    ]))
        ->assertOk()
        ->assertSee('Apply size to batch')
        ->assertSee(__('batch_log.record.size_help'))
        ->assertSee('name="product_size_key" value="'.$small->public_id.'"', false)
        ->assertSee('name="units_produced" value="" x-model="unitsProduced"', false);
    $this->get(route('recipes.version', [
        'recipe' => $recipe,
        'version' => $version,
        'batch_product_size' => $small->public_id,
        'batch_units_produced' => 10,
        'units_produced' => 20,
    ]))
        ->assertOk()
        ->assertSee('name="batch_units_produced" value="10"', false);

    $this->from(route('recipes.saved', $recipe))
        ->post(route('recipes.production-batches.store', $recipe), [...$input, 'recipe_version_id' => $version->id])
        ->assertRedirect(route('recipes.saved', $recipe))
        ->assertSessionHasErrors(['packaging' => $message]);

    expect(ProductionBatch::query()->count())->toBe(0);

    $response = $this->post(route('recipes.production-batches.store', $recipe), [
        ...$input,
        'recipe_version_id' => $version->id,
        'product_size_key' => $small->public_id,
    ]);
    $batch = ProductionBatch::query()->sole();
    $response->assertRedirect(route('production-batches.show', $batch));
    $small->update(['name' => 'Renamed bar']);

    foreach (['show' => 'packaging_title', 'print' => 'packaging'] as $page => $headingKey) {
        $snapshot = $this->get(route('production-batches.'.$page, $batch))->assertOk()->assertDontSee('Renamed bar');
        $document = HTMLDocument::createFromString($snapshot->getContent(), LIBXML_NOERROR);
        $pageHeadings = array_map(fn ($heading): string => trim($heading->textContent), iterator_to_array($document->querySelectorAll('h1')));
        $sectionHeadings = array_map(fn ($heading): string => trim($heading->textContent), iterator_to_array($document->querySelectorAll('h2')));

        expect($pageHeadings)->toContain($batch->recipe_name.' · Small bar')
            ->and($sectionHeadings)->toContain(__("batch_log.snapshot.{$headingKey}").' · Small bar');
    }

    expect($batch->product_size_id)->toBe($small->id)
        ->and($batch->product_size_label_snapshot)->toBe('Small bar')
        ->and($batch->units_produced)->toBe(10)
        ->and($batch->packagingItems->pluck('name')->all())->toBe(['Small box']);
});

it('records a cosmetic production snapshot using total batch quantity language', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotCosmeticRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 20, packagingPrice: 0.4);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => null,
        'manufacture_date' => '2026-06-11',
        'batch_basis' => 500,
        'units_produced' => 5,
        'production_notes' => '',
        'ingredient_lot_numbers' => [],
    ]);

    expect($batch->batch_basis_label)->toBe('Total batch quantity')
        ->and($batch->batch_basis_value)->toBe('500.000')
        ->and($batch->ingredients->first()->quantity)->toBe('500.0000')
        ->and($batch->total_cost)->toBe('12.0000')
        ->and($batch->cost_per_unit)->toBe('2.4000');
});

it('records production snapshots with unpriced packaging rows marked unpriced', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'packaging_item_id' => null,
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
    productionSnapshotPriceAlkali($costing);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $wrap = $batch->packagingItems->sole();

    expect($batch->has_unpriced_rows)->toBeTrue()
        ->and($batch->ingredient_total)->toBe('8.5000')
        ->and($batch->packaging_total)->toBe('0.0000')
        ->and($batch->total_cost)->toBe('8.5000')
        ->and($wrap->name)->toBe('Unpriced wrap')
        ->and($wrap->is_unpriced)->toBeTrue()
        ->and($wrap->line_cost)->toBe('0.0000')
        ->and(RecipeVersionCostingPackagingItem::query()->count())->toBe(0);
});

it('records production snapshots with unpriced ingredient rows without creating costing', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    expect($batch->has_unpriced_rows)->toBeTrue()
        ->and($batch->ingredient_total)->toBe('0.0000')
        ->and($batch->ingredients->pluck('phase_key')->sort()->values()->all())->toBe(['lye_alkali', 'saponified_oils'])
        ->and($batch->ingredients->pluck('price_per_kg')->filter()->all())->toBe([])
        ->and(RecipeVersionCosting::query()
            ->where('recipe_version_id', $version->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
});

it('marks packaging plan rows without source prices as unpriced in production cost previews', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'packaging_item_id' => null,
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

it('keeps unpriced packaging rows unpriced across repeated production cost preview builds', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'packaging_item_id' => null,
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

    DB::flushQueryLog();
    DB::enableQueryLog();

    $builder = app(RecipeVersionCostPreviewBuilder::class);
    $builder->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1000,
        unitsProduced: 10,
    );

    $secondPreview = $builder->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1000,
        unitsProduced: 10,
    );

    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => preg_match('/^(insert|update|delete) /i', $query) === 1)->all();
    DB::disableQueryLog();

    expect($writes)->toBe([]);
    expect($secondPreview['packaging_total'])->toBe(0.0)
        ->and($secondPreview['has_unpriced_rows'])->toBeTrue()
        ->and($secondPreview['packaging_rows'][0]['unit_cost'])->toBeNull()
        ->and($secondPreview['packaging_rows'][0]['cost_per_finished_unit'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['line_cost'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['is_unpriced'])->toBeTrue();
});

it('preserves deliberately saved zero cost unlinked packaging rows across repeated production cost preview builds', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'packaging_item_id' => null,
        'name' => 'Free insert',
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
    productionSnapshotPriceAlkali($costing);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'packaging_item_id' => null,
        'name' => 'Free insert',
        'unit_cost' => 0,
        'quantity' => 1,
    ]);

    $builder = app(RecipeVersionCostPreviewBuilder::class);
    $builder->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1000,
        unitsProduced: 10,
    );

    $secondPreview = $builder->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1000,
        unitsProduced: 10,
    );

    expect($secondPreview['packaging_total'])->toBe(0.0)
        ->and($secondPreview['has_unpriced_rows'])->toBeFalse()
        ->and($secondPreview['packaging_rows'][0]['unit_cost'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['cost_per_finished_unit'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['line_cost'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['is_unpriced'])->toBeFalse();
});

it('does not create production cost preview data for saved formula views without existing costing', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();

    expect(RecipeVersionCosting::query()
        ->where('recipe_version_id', $version->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();

    $viewData = app(RecipeVersionViewDataBuilder::class)->build($recipe, $version);

    expect($viewData['hasCostingData'])->toBeFalse()
        ->and($viewData['hasUnpricedRows'])->toBeFalse()
        ->and($viewData['costingSummary'])->toBe([])
        ->and($viewData['costingIngredientRows'])->toBe([])
        ->and($viewData['costingPackagingRows'])->toBe([])
        ->and(RecipeVersionCosting::query()
            ->where('recipe_version_id', $version->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
});

it('does not create costing rows when viewing the saved formula route without existing costing', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();

    expect(RecipeVersionCosting::query()
        ->where('recipe_version_id', $version->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Some rows have no price. You can still record this batch; its cost will be marked incomplete.');

    expect(RecipeVersionCosting::query()
        ->where('recipe_version_id', $version->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();
});

it('does not mutate existing costing rows when building saved formula view data', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'packaging_item_id' => null,
        'name' => 'Free insert',
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

    $costingItem = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    $packagingCostingItem = RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'packaging_item_id' => null,
        'name' => 'Free insert',
        'unit_cost' => 0,
        'quantity' => 1,
    ]);

    $viewData = app(RecipeVersionViewDataBuilder::class)->build($recipe, $version);

    expect($viewData['hasCostingData'])->toBeTrue()
        ->and(RecipeVersionCostingItem::query()
            ->where('recipe_version_costing_id', $costing->id)
            ->pluck('id')
            ->all())->toBe([$costingItem->id])
        ->and(RecipeVersionCostingPackagingItem::query()
            ->where('recipe_version_costing_id', $costing->id)
            ->pluck('id')
            ->all())->toBe([$packagingCostingItem->id])
        ->and($packagingCostingItem->fresh()->unit_cost)->toBe('0.0000')
        ->and($packagingCostingItem->fresh()->quantity)->toBe('1.000');
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
    $draftVersion = $service->save(
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
        ->orderByDesc('version_number')
        ->firstOrFail();

    return [$user, $recipe, $version, $ingredient];
}

function productionSnapshotIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::Lipids,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_soap_saponification_trusted' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/** Price the calculated NaOH row of a soap costing, so a test can focus on its own rows. */
function productionSnapshotPriceAlkali(RecipeVersionCosting $costing, float $pricePerKg = 0.0): RecipeVersionCostingItem
{
    return RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => Ingredient::query()->withoutGlobalScopes()->where('catalog_key', 'CH1')->value('id'),
        'phase_key' => 'lye_alkali',
        'position' => 1,
        'price_per_kg' => $pricePerKg,
    ]);
}

/** @return array{packaging_item: PackagingItem, costing_item: RecipeVersionCostingItem, packaging_costing_item: RecipeVersionCostingPackagingItem} */
function productionSnapshotAttachCosting(User $user, RecipeVersion $version, Ingredient $ingredient, float $ingredientPrice, float $packagingPrice, float $alkaliPrice = 0.0): array
{
    $packagingItem = createPackagingItemForWorkspace([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => $packagingPrice,
        'currency' => 'EUR',
    ]);

    $currentVersion = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $version->recipe_id)
        ->orderByDesc('version_number')
        ->firstOrFail();

    $currentVersion->packagingItems()->create([
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $currentVersion->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    $costingItem = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => $ingredientPrice,
    ]);
    productionSnapshotPriceAlkali($costing, $alkaliPrice);

    $packagingCostingItem = RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => $packagingPrice,
        'quantity' => 1,
    ]);

    return [
        'packaging_item' => $packagingItem,
        'costing_item' => $costingItem,
        'packaging_costing_item' => $packagingCostingItem,
    ];
}

/** @return array{0: User, 1: Recipe, 2: RecipeVersion, 3: Ingredient} */
function productionSnapshotCosmeticRecipe(): array
{
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'name' => 'Cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $ingredient = productionSnapshotIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->save(
        $user,
        $cosmeticFamily,
        productionSnapshotCosmeticDraftPayload($ingredient, $productType, 'Snapshot Lotion'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->saveAsNewVersion(
        $user,
        $cosmeticFamily,
        productionSnapshotCosmeticDraftPayload($ingredient, $productType, 'Snapshot Lotion'),
        $recipe,
    );

    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->orderByDesc('version_number')
        ->firstOrFail();

    return [$user, $recipe, $version, $ingredient];
}

/** @return array<string, mixed> */
function productionSnapshotDraftPayload(Ingredient $ingredient, string $name): array
{
    return [
        'name' => $name,
        'product_type_id' => testProductTypeIdForFamily('soap'),
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

/** @return array<string, mixed> */
function productionSnapshotCosmeticDraftPayload(Ingredient $ingredient, ProductType $productType, string $name): array
{
    return [
        'name' => $name,
        'product_type_id' => $productType->id,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => [
            [
                'key' => 'saponified_oils',
                'name' => 'Phase A',
            ],
        ],
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 500,
                    'note' => null,
                ],
            ],
        ],
    ];
}

it('projects current prices without ambient authentication or saving costing and freezes them only in the snapshot', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    rememberIngredientPriceForWorkspace($user, $ingredient, '8.5');
    rememberIngredientPriceForWorkspace($user, Ingredient::query()->withoutGlobalScopes()->where('catalog_key', 'CH1')->firstOrFail(), '0');
    $service = app(ProductionSnapshotService::class);
    $input = ['batch_basis' => 1000, 'units_produced' => 10, 'manufacture_date' => '2026-09-25'];

    expect(auth()->user())->toBeNull();
    $version->setRelation('phases', new Collection);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $preview = $service->preview($recipe, $version, $user, $input);
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => preg_match('/^(insert|update|delete) /i', $query) === 1)->all();
    DB::disableQueryLog();

    expect($writes)->toBe([]);
    expect($preview['total_cost'])->toBe(8.5);
    expect($preview['has_unpriced_rows'])->toBeFalse();

    $batch = $service->record($recipe, $version, $user, $input);

    expect($batch->total_cost)->toBe('8.5000');
    expect($batch->ingredients->firstWhere('phase_key', 'saponified_oils')->price_per_kg)->toBe('8.5000');
    expect(RecipeVersionCosting::query()->count())->toBe(0);
});

it('denies production preview and recording for a foreign actor without writes', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();
    $foreignUser = User::factory()->create();
    $service = app(ProductionSnapshotService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(fn () => $service->preview($recipe, $version, $foreignUser, []))->toThrow(AuthorizationException::class);
    expect(fn () => $service->record($recipe, $version, $foreignUser, []))->toThrow(AuthorizationException::class);
    $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => preg_match('/^(insert|update|delete) /i', $query) === 1)->all();
    DB::disableQueryLog();

    expect($writes)->toBe([]);
});

it('rejects a production preview for a version from a different recipe', function (): void {
    [$user, $recipe] = productionSnapshotSoapRecipe();
    $foreignRecipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $foreignVersion = RecipeVersion::factory()->create(['recipe_id' => $foreignRecipe->id, 'owner_id' => $user->id]);

    expect(fn () => app(ProductionSnapshotService::class)->preview($recipe, $foreignVersion, $user, []))
        ->toThrow(NotFoundHttpException::class);
    expect(RecipeVersionCosting::query()->count())->toBe(0);
});

it('records shared batch provenance and enforces the company allowance inside the write service', function (): void {
    [$owner, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($owner, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $plan = Plan::factory()->create(['allows_collaboration' => true]);
    UserEntitlement::factory()->create(['user_id' => $owner->id, 'plan_id' => $plan->id]);
    PlanLimit::factory()->create(['plan_id' => $plan->id, 'key' => 'production_batches', 'value' => 1]);
    $editor = User::factory()->create(['active_workspace_id' => $recipe->workspace_id]);
    WorkspaceMember::factory()->create(['user_id' => $editor->id, 'workspace_id' => $recipe->workspace_id, 'role' => WorkspaceMemberRole::Editor]);
    $input = ['manufacture_date' => '2026-09-27', 'batch_basis' => 1000, 'units_produced' => 10];
    $service = app(ProductionSnapshotService::class);
    $batch = $service->record($recipe, $version, $editor, $input);
    expect($batch->workspace_id)->toBe($recipe->workspace_id)
        ->and($batch->user_id)->toBe($editor->id);
    expect(fn () => $service->record($recipe, $version, $owner, $input))->toThrow(ValidationException::class, '1 saved production batches');
    expect(ProductionBatch::query()->where('workspace_id', $recipe->workspace_id)->count())->toBe(1);
});
