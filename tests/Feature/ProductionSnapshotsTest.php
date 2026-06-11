<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use App\Models\ProductionBatchPackagingItem;
use App\Models\ProductType;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use App\Services\ProductionSnapshotService;
use App\Services\RecipeVersionCostPreviewBuilder;
use App\Services\RecipeVersionViewDataBuilder;
use App\Services\RecipeWorkbenchService;
use App\Services\UserIngredientPriceMemory;
use App\Services\UserPackagingItemAuthoringService;
use App\Visibility;
use App\WorkspaceMemberRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

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

it('records a soap production snapshot and freezes current prices', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $costingRows = productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

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
        ->and($batch->ingredient_total)->toBe('12.7500')
        ->and($batch->packaging_total)->toBe('3.0000')
        ->and($batch->total_cost)->toBe('15.7500')
        ->and($batch->cost_per_unit)->toBe('1.3125')
        ->and($batch->ingredients)->toHaveCount(1)
        ->and($batch->ingredients->first()->ingredient_name)->toBe('Olive Oil')
        ->and($batch->ingredients->first()->quantity)->toBe('1500.0000')
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-10')
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

    expect($recordedBatch->ingredient_total)->toBe('12.7500')
        ->and($recordedBatch->packaging_total)->toBe('3.0000')
        ->and($recordedBatch->total_cost)->toBe('15.7500')
        ->and($recordedBatch->cost_per_unit)->toBe('1.3125')
        ->and($recordedBatch->ingredients->first()->price_per_kg)->toBe('8.5000')
        ->and($recordedBatch->ingredients->first()->line_cost)->toBe('12.7500')
        ->and($recordedBatch->packagingItems->first()->unit_cost)->toBe('0.2500')
        ->and($recordedBatch->packagingItems->first()->line_cost)->toBe('3.0000');
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

    app(UserIngredientPriceMemory::class)->remember($user, $ingredient->id, 12.75);
    app(UserPackagingItemAuthoringService::class)->updateUnitCost($costingRows['packaging_item'], $user, 0.89);

    $recordedBatch = $batch->fresh(['ingredients', 'packagingItems']);
    $liveCosting = RecipeVersionCosting::query()
        ->where('recipe_version_id', $version->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($recordedBatch->ingredient_total)->toBe('12.7500')
        ->and($recordedBatch->packaging_total)->toBe('3.0000')
        ->and($recordedBatch->total_cost)->toBe('15.7500')
        ->and($recordedBatch->cost_per_unit)->toBe('1.3125')
        ->and($recordedBatch->ingredients->first()->price_per_kg)->toBe('8.5000')
        ->and($recordedBatch->ingredients->first()->line_cost)->toBe('12.7500')
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
            ->where('user_packaging_item_id', $costingRows['packaging_item']->id)
            ->value('unit_cost'))->toBe('0.8900');
});

it('stores a production snapshot from the saved formula route', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
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
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-20');
});

it('shows record production controls on the saved formula page', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Record production')
        ->assertSee('Production batch number')
        ->assertSee('Manufacture date')
        ->assertSee('Oil quantity')
        ->assertSee('Units produced')
        ->assertSee('Ingredient lot numbers')
        ->assertSee('Production notes')
        ->assertSee('Ingredient cost')
        ->assertSee('Packaging cost')
        ->assertSee('Cost per finished unit')
        ->assertSee('Record production')
        ->assertDontSee('Prepare batch');
});

it('shows record production controls on the legacy saved version page', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->get(route('recipes.version', ['recipe' => $recipe->id, 'version' => $version->id]))
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
        ->assertDontSee('Recalculate');
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
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-60');
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
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-35');
});

it('allows workspace editors authorized to update the recipe to store production snapshots', function (): void {
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
        'is_private' => false,
        'created_by' => $owner->id,
    ]);

    productionSnapshotAttachCosting($editor, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($editor)
        ->post(route('recipes.production-batches.store', $recipe), [
            'production_batch_number' => 'B-2026-036',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1000,
            'units_produced' => 10,
        ])
        ->assertRedirect();

    expect(ProductionBatch::query()->where('user_id', $editor->id)->count())->toBe(1);
});

it('validates required production recording fields', function (): void {
    [$user, $recipe] = productionSnapshotSoapRecipe();

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'manufacture_date' => '',
            'batch_basis' => 0,
            'units_produced' => 0,
        ])
        ->assertSessionHasErrors(['manufacture_date', 'batch_basis', 'units_produced']);
});

it('redirects back with validation errors when recording unpriced production rows', function (): void {
    [$user, $recipe] = productionSnapshotSoapRecipe();

    $this->actingAs($user)
        ->from(route('recipes.saved', $recipe))
        ->post(route('recipes.production-batches.store', $recipe), [
            'production_batch_number' => 'B-2026-040',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1000,
            'units_produced' => 10,
        ])
        ->assertRedirect(route('recipes.saved', $recipe))
        ->assertSessionHasErrors(['costing']);

    expect(ProductionBatch::query()->count())->toBe(0);
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

it('does not record production snapshots with unpriced packaging rows', function (): void {
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

    expect(fn () => app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]))->toThrow(ValidationException::class, 'Production snapshots require prices');

    expect(ProductionBatch::query()->count())->toBe(0)
        ->and(ProductionBatchIngredient::query()->count())->toBe(0)
        ->and(ProductionBatchPackagingItem::query()->count())->toBe(0)
        ->and(RecipeVersionCostingPackagingItem::query()->count())->toBe(0);
});

it('does not record production snapshots with unpriced ingredient rows', function (): void {
    [$user, $recipe, $version] = productionSnapshotSoapRecipe();

    expect(fn () => app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]))->toThrow(ValidationException::class, 'Production snapshots require prices');

    expect(ProductionBatch::query()->count())->toBe(0)
        ->and(ProductionBatchIngredient::query()->count())->toBe(0)
        ->and(ProductionBatchPackagingItem::query()->count())->toBe(0)
        ->and(RecipeVersionCosting::query()
            ->where('recipe_version_id', $version->id)
            ->where('user_id', $user->id)
            ->exists())->toBeFalse();
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

it('keeps unpriced packaging rows unpriced across repeated production cost preview builds', function (): void {
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
        ->and($secondPreview['has_unpriced_rows'])->toBeTrue()
        ->and($secondPreview['packaging_rows'][0]['unit_cost'])->toBeNull()
        ->and($secondPreview['packaging_rows'][0]['cost_per_finished_unit'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['line_cost'])->toBe(0.0)
        ->and($secondPreview['packaging_rows'][0]['is_unpriced'])->toBeTrue();
});

it('preserves deliberately saved zero cost unlinked packaging rows across repeated production cost preview builds', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'user_packaging_item_id' => null,
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

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => null,
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
        ->assertSee('Some rows are unpriced. Add prices before recording production.');

    expect(RecipeVersionCosting::query()
        ->where('recipe_version_id', $version->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();
});

it('does not mutate existing costing rows when building saved formula view data', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();

    $version->packagingItems()->create([
        'user_packaging_item_id' => null,
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
        'user_packaging_item_id' => null,
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

/** @return array{packaging_item: UserPackagingItem, costing_item: RecipeVersionCostingItem, packaging_costing_item: RecipeVersionCostingPackagingItem} */
function productionSnapshotAttachCosting(User $user, RecipeVersion $version, Ingredient $ingredient, float $ingredientPrice, float $packagingPrice): array
{
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => $packagingPrice,
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

    $costingItem = RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => $ingredientPrice,
    ]);

    $packagingCostingItem = RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
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
    $draftVersion = $service->saveDraft(
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
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $version, $ingredient];
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
