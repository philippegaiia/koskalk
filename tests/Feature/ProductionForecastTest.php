<?php

use App\Livewire\ProductionBench\InventoryIndex;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRequirementKind;
use App\ProductionRunStatus;
use App\Services\Production\ProductionDemandService;
use App\Services\StockPositionService;
use App\StockMovementType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('projects planned ingredient and packaging demand without changing physical or available stock', function (): void {
    $fixture = productionForecastFixture();
    $ingredientLot = StockLot::factory()->released()->for($fixture['workspace'])->create([
        'ingredient_id' => $fixture['ingredient']->id,
        'packaging_item_id' => null,
    ]);
    StockMovement::factory()->for($ingredientLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000.000000000',
    ]);
    $packagingLot = StockLot::factory()->released()->for($fixture['workspace'])->forPackaging()->create([
        'ingredient_id' => null,
        'packaging_item_id' => $fixture['packaging']->id,
    ]);
    StockMovement::factory()->for($packagingLot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '20.000000000',
    ]);

    $production = productionForecastRun($fixture, ProductionRunStatus::Scheduled);
    ProductionRequirement::factory()->for($production, 'productionRun')->for($fixture['ingredient'])->create([
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => '600.000000000',
        'required_units' => null,
    ]);
    ProductionRequirement::factory()->for($production, 'productionRun')->forPackaging($fixture['packaging'])->create([
        'required_units' => 7,
    ]);

    $positions = app(StockPositionService::class);

    expect($positions->forWorkspaceSubject($fixture['workspace'], $fixture['ingredient']))->toMatchArray([
        'physical' => '1000.000000000',
        'available' => '1000.000000000',
        'reserved' => '0.000000000',
        'incoming' => '0.000000000',
        'forecast' => '400.000000000',
    ])->and($positions->forWorkspaceSubject($fixture['workspace'], $fixture['packaging']))->toMatchArray([
        'physical' => '20.000000000',
        'available' => '20.000000000',
        'reserved' => '0.000000000',
        'incoming' => '0.000000000',
        'forecast' => '13.000000000',
    ]);
});

it('counts only scheduled and reserved requirements once', function (): void {
    $fixture = productionForecastFixture();
    $scheduled = productionForecastRun($fixture, ProductionRunStatus::Scheduled);
    $reserved = productionForecastRun($fixture, ProductionRunStatus::Reserved);
    $draft = productionForecastRun($fixture, ProductionRunStatus::Draft);
    $cancelled = productionForecastRun($fixture, ProductionRunStatus::Cancelled);

    foreach ([[$scheduled, '125.000000000'], [$reserved, '75.000000000'], [$draft, '900.000000000'], [$cancelled, '800.000000000']] as [$production, $quantity]) {
        ProductionRequirement::factory()->for($production, 'productionRun')->for($fixture['ingredient'])->create([
            'kind' => ProductionRequirementKind::Ingredient,
            'required_mass_grams' => $quantity,
            'required_units' => null,
        ]);
    }

    expect(app(ProductionDemandService::class)->forWorkspaceSubject($fixture['workspace'], $fixture['ingredient']))
        ->toBe('200.000000000');
});

it('does not include another workspace in planned demand', function (): void {
    $fixture = productionForecastFixture();
    $other = productionForecastFixture();
    $production = productionForecastRun($other, ProductionRunStatus::Scheduled);

    ProductionRequirement::factory()->for($production, 'productionRun')->for($other['ingredient'])->create([
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => '999.000000000',
        'required_units' => null,
    ]);

    expect(app(ProductionDemandService::class)->forWorkspaceSubject($fixture['workspace'], $fixture['ingredient']))
        ->toBe('0.000000000');
});

it('shows subject-level production forecast in the inventory view', function (): void {
    $fixture = productionForecastFixture();
    $lot = StockLot::factory()->released()->for($fixture['workspace'])->create([
        'ingredient_id' => $fixture['ingredient']->id,
        'packaging_item_id' => null,
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000.000000000',
    ]);
    $production = productionForecastRun($fixture, ProductionRunStatus::Scheduled);
    ProductionRequirement::factory()->for($production, 'productionRun')->for($fixture['ingredient'])->create([
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => '600.000000000',
        'required_units' => null,
    ]);

    Livewire::actingAs($fixture['owner'])->test(InventoryIndex::class)
        ->assertSee('Production forecast')
        ->assertSee('Olive oil')
        ->assertSee('0.40');

    Livewire::actingAs($fixture['owner'])->test(InventoryIndex::class)
        ->set('mode', 'requirements')
        ->assertSee('Material requirements')
        ->assertSee('Required')
        ->assertSee('Reserved')
        ->assertSee('0.60');
});

it('renders negative ingredient forecasts when planned demand exceeds available stock', function (): void {
    $fixture = productionForecastFixture();
    $lot = StockLot::factory()->released()->for($fixture['workspace'])->create([
        'ingredient_id' => $fixture['ingredient']->id,
        'packaging_item_id' => null,
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000.000000000',
    ]);
    $production = productionForecastRun($fixture, ProductionRunStatus::Scheduled);
    ProductionRequirement::factory()->for($production, 'productionRun')->for($fixture['ingredient'])->create([
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => '1500.000000000',
        'required_units' => null,
    ]);

    Livewire::actingAs($fixture['owner'])->test(InventoryIndex::class)
        ->assertSee('Production forecast')
        ->assertSee('-0.50');
});

/**
 * @return array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}
 */
function productionForecastFixture(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $workspace->productionEntitlement()->create([
        'status' => 'active',
        'activated_at' => now(),
    ]);
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Soap box']);

    return compact('owner', 'workspace', 'ingredient', 'packaging');
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}  $fixture
 */
function productionForecastRun(array $fixture, ProductionRunStatus $status): ProductionRun
{
    return ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => $status,
        'created_by_user_id' => $fixture['owner']->id,
        'planned_for' => '2026-08-15',
    ]);
}
