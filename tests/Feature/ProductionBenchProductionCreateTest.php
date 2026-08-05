<?php

use App\Livewire\ProductionBench\Production\ProductionCreate;
use App\MassUnit;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductFamily;
use App\Models\ProductionBatchPreset;
use App\Models\ProductionRunNumberSetting;
use App\Models\ProductionTaskSet;
use App\Models\ProductionTaskSetItem;
use App\Models\ProductionTaskType;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionPackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use App\OwnerType;
use App\ProductionRunStatus;
use App\StockMovementType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows only published products and loads an optional default preset and task set', function (): void {
    $fixture = productionCreateFixture();
    $unpublished = productionCreateRecipe($fixture['workspace'], $fixture['family'], 'Draft product', true);

    Livewire::actingAs($fixture['owner'])->test(ProductionCreate::class)
        ->assertSee('Workshop soap')
        ->assertDontSee('Draft product')
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->assertSet('basisInputValue', '12')
        ->assertSet('basisInputUnit', 'kg')
        ->assertSet('expectedUnits', '100')
        ->assertSet('presetId', (string) $fixture['preset']->id)
        ->assertSet('taskSetId', (string) $fixture['taskSet']->id);
});

it('previews scaled requirements, stock positions, shortages, and task dates without writing a production', function (): void {
    $fixture = productionCreateFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->set('basisInputValue', '6')
        ->set('basisInputUnit', 'kg')
        ->set('expectedUnits', '20')
        ->set('plannedFor', '2026-08-10');

    $page->assertSee('Olive oil')
        ->assertSee('Soap box')
        ->assertSee('6.00 kg')
        ->assertSee('5.00 kg')
        ->assertSee('1.00 kg')
        ->assertSee('2026-08-11');

    expect($fixture['recipe']->productionRuns()->count())->toBe(0);
});

it('allows the user to edit a loaded preset and schedule one planned production', function (): void {
    $fixture = productionCreateFixture();

    $page = Livewire::actingAs($fixture['owner'])->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->set('basisInputValue', '6')
        ->set('expectedUnits', '20')
        ->set('plannedFor', '2026-08-10')
        ->call('plan')
        ->assertHasNoErrors()
        ->assertSee(__('production_bench.production.planned_success'));

    $production = $fixture['recipe']->productionRuns()->firstOrFail();

    expect($production->status)->toBe(ProductionRunStatus::Scheduled)
        ->and($production->planning_batch_number)->toBe('T00001')
        ->and($production->basis_quantity_grams)->toBe('6000.000000000')
        ->and($production->expected_units)->toBe(20)
        ->and($production->tasks()->count())->toBe(2)
        ->and($page->get('savedPublicId'))->toBe($production->public_id);
});

it('warns about a non-working production date but keeps the date explicit', function (): void {
    $fixture = productionCreateFixture();
    $fixture['workspace']->update(['production_works_on_weekends' => false]);

    Livewire::actingAs($fixture['owner'])->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->set('plannedFor', '2026-08-09')
        ->assertSee(__('production_bench.production.non_working_date'));
});

it('keeps the planning form read-only after the bench is cancelled', function (): void {
    $fixture = productionCreateFixture();
    $fixture['workspace']->productionEntitlement()->update([
        'status' => 'cancelled',
        'cancelled_at' => now(),
    ]);

    Livewire::actingAs($fixture['owner'])->test(ProductionCreate::class)
        ->set('recipeId', (string) $fixture['recipe']->id)
        ->call('plan')
        ->assertHasErrors('production_bench');

    expect($fixture['recipe']->productionRuns()->count())->toBe(0);
});

/** @return array{owner: User, workspace: Workspace, family: ProductFamily, recipe: Recipe, version: RecipeVersion, ingredient: Ingredient, packaging: PackagingItem, preset: ProductionBatchPreset, taskSet: ProductionTaskSet} */
function productionCreateFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    WorkspaceProductionEntitlement::factory()->for($workspace)->create();
    $family = ProductFamily::factory()->create([
        'slug' => 'create-soap-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = productionCreateRecipe($workspace, $family, 'Workshop soap');
    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_current', false)
        ->firstOrFail();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $phase = RecipePhase::factory()->for($version)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Oils',
        'slug' => 'oils',
        'sort_order' => 1,
    ]);
    RecipeItem::factory()->for($version)->for($phase, 'recipePhase')->for($ingredient)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'percentage' => '100.0000',
        'position' => 1,
    ]);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);
    RecipeVersionPackagingItem::query()->create([
        'recipe_version_id' => $version->id,
        'packaging_item_id' => $packaging->id,
        'name' => 'Soap box',
        'components_per_unit' => '1.000',
        'position' => 1,
    ]);
    $lot = StockLot::factory()->released()->for($workspace)->for($ingredient)->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '5000.000000000',
        'original_quantity' => '5',
        'original_unit' => 'kg',
    ]);
    $preset = ProductionBatchPreset::factory()->for($workspace)->for($recipe)->create([
        'name' => 'Default soap batch',
        'basis_quantity_grams' => '12000.000000000',
        'basis_input_value' => '12.000000000',
        'basis_input_unit' => MassUnit::Kilogram,
        'expected_units' => 100,
        'is_default' => true,
        'is_active' => true,
    ]);
    $taskType = ProductionTaskType::factory()->for($workspace)->create([
        'name' => 'Cure',
        'default_duration_minutes' => 30,
    ]);
    $taskSet = ProductionTaskSet::factory()->for($workspace)->create([
        'name' => 'Soap workflow',
    ]);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($taskType, 'taskType')->create([
        'position' => 1,
        'days_after_production' => 0,
    ]);
    $secondType = ProductionTaskType::factory()->for($workspace)->create(['name' => 'Release']);
    ProductionTaskSetItem::factory()->for($taskSet, 'taskSet')->for($secondType, 'taskType')->create([
        'position' => 2,
        'days_after_production' => 1,
    ]);
    $recipe->update([
        'default_production_task_set_id' => $taskSet->id,
    ]);

    return compact('owner', 'workspace', 'family', 'recipe', 'version', 'ingredient', 'packaging', 'preset', 'taskSet');
}

function productionCreateRecipe(Workspace $workspace, ProductFamily $family, string $name, bool $unpublished = false): Recipe
{
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => $name,
    ]);
    RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => $unpublished,
        'batch_mass_grams' => '1000.000000000',
    ]);

    return $recipe;
}
