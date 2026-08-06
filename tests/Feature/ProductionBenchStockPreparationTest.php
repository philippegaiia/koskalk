<?php

use App\Livewire\ProductionBench\Production\ProductionIndex;
use App\Livewire\ProductionBench\Production\StockPreparation;
use App\Models\Ingredient;
use App\Models\ProductFamily;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\OwnerType;
use App\ProductionRequirementKind;
use App\ProductionRunStatus;
use App\StockMovementType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('previews lots and confirms an individual stock preparation', function (): void {
    $fixture = productionStockPreparationUiFixture();
    $production = productionStockPreparationUiProduction($fixture, '2026-08-20');
    $requirement = ProductionRequirement::factory()->for($production, 'productionRun')->for($fixture['ingredient'])->create([
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => '10.000000000',
        'required_units' => null,
        'subject_name_snapshot' => $fixture['ingredient']->display_name,
    ]);
    $lot = StockLot::factory()->for($fixture['workspace'])->released()->create([
        'ingredient_id' => $fixture['ingredient']->id,
        'packaging_item_id' => null,
        'internal_lot_code' => 'SK-UI-0001',
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '20.000000000',
    ]);

    Livewire::actingAs($fixture['owner'])->test(StockPreparation::class, ['productionRun' => $production->id])
        ->assertSee('Prepare production stock')
        ->assertSee('SK-UI-0001')
        ->set('manualMode', [(string) $requirement->id => true])
        ->set('manualQuantities', [(string) $requirement->id => [(string) $lot->id => '10']])
        ->call('confirm')
        ->assertRedirect(route('production-bench.production.show', $production));

    expect($production->refresh()->status)->toBe(ProductionRunStatus::Reserved);
});

it('opens one prepare-stock page for several selected planned productions', function (): void {
    $fixture = productionStockPreparationUiFixture();
    $first = productionStockPreparationUiProduction($fixture, '2026-08-20');
    $second = productionStockPreparationUiProduction($fixture, '2026-08-21');

    Livewire::actingAs($fixture['owner'])->test(ProductionIndex::class)
        ->set('selectedProductionIds', [$first->id, $second->id])
        ->call('prepareSelected')
        ->assertRedirect(route('production-bench.production.prepare', ['ids' => $first->id.','.$second->id]));
});

it('exposes the stock preparation route inside the production bench', function (): void {
    $fixture = productionStockPreparationUiFixture();
    $production = productionStockPreparationUiProduction($fixture, '2026-08-20');

    $this->actingAs($fixture['owner'])
        ->get(route('production-bench.production.prepare', $production))
        ->assertOk()
        ->assertSee('Prepare production stock');
});

/**
 * @return array{owner: User, workspace: Workspace, ingredient: Ingredient, recipe: Recipe, version: RecipeVersion}
 */
it('shows the snapshot product name on the stock preparation page', function (): void {
    $fixture = productionStockPreparationUiFixture();
    $production = productionStockPreparationUiProduction($fixture, '2026-08-20');
    $production->update(['recipe_name_snapshot' => 'Historical soap']);
    $fixture['recipe']->update(['name' => 'Renamed live product']);

    Livewire::actingAs($fixture['owner'])->test(StockPreparation::class, ['productionRun' => (string) $production->id])
        ->assertSee('Historical soap')
        ->assertDontSee('Renamed live product');
});

function productionStockPreparationUiFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $workspace->productionEntitlement()->create([
        'status' => 'active',
        'activated_at' => now(),
    ]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $family = ProductFamily::factory()->create([
        'slug' => 'ui-'.fake()->unique()->numberBetween(1, 999999),
        'calculation_basis' => 'initial_oils',
    ]);
    $recipe = Recipe::factory()->for($family, 'productFamily')->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'name' => 'Olive soap',
    ]);
    $version = RecipeVersion::factory()->for($recipe)->create([
        'owner_type' => OwnerType::Workspace,
        'owner_id' => $workspace->id,
        'workspace_id' => $workspace->id,
        'visibility' => Visibility::Private,
        'is_current' => true,
    ]);

    return compact('owner', 'workspace', 'ingredient', 'recipe', 'version');
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, recipe: Recipe, version: RecipeVersion}  $fixture
 */
function productionStockPreparationUiProduction(array $fixture, string $plannedFor): ProductionRun
{
    return ProductionRun::query()->create([
        'workspace_id' => $fixture['workspace']->id,
        'recipe_id' => $fixture['recipe']->id,
        'recipe_version_id' => $fixture['version']->id,
        'status' => ProductionRunStatus::Scheduled,
        'source' => 'direct',
        'planned_for' => $plannedFor,
        'basis_kind' => 'oil_mass',
        'basis_quantity_grams' => '10000.000000000',
        'basis_input_value' => '10.000000000',
        'basis_input_unit' => 'kg',
        'expected_units' => 100,
        'idempotency_key' => fake()->uuid(),
        'created_by_user_id' => $fixture['owner']->id,
        'planning_batch_number' => 'T'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
    ]);
}
