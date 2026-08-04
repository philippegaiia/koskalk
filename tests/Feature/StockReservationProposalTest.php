<?php

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionRequirementKind;
use App\ProductionRunStatus;
use App\Services\Production\StockReservationProposalService;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockReservationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('proposes FEFO split allocations for ingredient and packaging requirements without writing reservations', function (): void {
    $fixture = stockReservationProposalFixture();
    $production = stockReservationProduction($fixture, ProductionRunStatus::Scheduled, '2026-08-20');
    $ingredientRequirement = stockReservationIngredientRequirement($production, $fixture['ingredient'], '130.000000000');
    $packagingRequirement = stockReservationPackagingRequirement($production, $fixture['packaging'], 12);
    $ingredientFirst = stockReservationLot($fixture, $fixture['ingredient'], '60.000000000', '2026-08-25', '2026-08-01');
    $ingredientSecond = stockReservationLot($fixture, $fixture['ingredient'], '100.000000000', '2026-09-01', '2026-08-02');
    stockReservationLot($fixture, $fixture['ingredient'], '500.000000000', null, '2026-08-03');
    $packagingFirst = stockReservationPackagingLot($fixture, $fixture['packaging'], 10, '2026-08-25', '2026-08-01');
    $packagingSecond = stockReservationPackagingLot($fixture, $fixture['packaging'], 5, null, '2026-08-02');

    $service = app(StockReservationProposalService::class);
    $ingredientProposal = $service->forRequirement($ingredientRequirement);
    $packagingProposal = $service->forRequirement($packagingRequirement);

    expect($ingredientProposal['proposed'])->toBe('130.000000000')
        ->and($ingredientProposal['missing'])->toBe('0.000000000')
        ->and($ingredientProposal['allocations'])->toHaveCount(2)
        ->and($ingredientProposal['allocations'][0]['lot']->is($ingredientFirst))->toBeTrue()
        ->and($ingredientProposal['allocations'][0]['quantity'])->toBe('60.000000000')
        ->and($ingredientProposal['allocations'][1]['lot']->is($ingredientSecond))->toBeTrue()
        ->and($ingredientProposal['allocations'][1]['quantity'])->toBe('70.000000000')
        ->and($packagingProposal['proposed'])->toBe('12.000000000')
        ->and($packagingProposal['missing'])->toBe('0.000000000')
        ->and($packagingProposal['allocations'])->toHaveCount(2)
        ->and($packagingProposal['allocations'][0]['lot']->is($packagingFirst))->toBeTrue()
        ->and($packagingProposal['allocations'][0]['quantity'])->toBe('10.000000000')
        ->and($packagingProposal['allocations'][1]['lot']->is($packagingSecond))->toBeTrue()
        ->and($packagingProposal['allocations'][1]['quantity'])->toBe('2.000000000')
        ->and(StockReservation::query()->count())->toBe(0);
});

it('reports shortages and rejects manual lots that are not eligible', function (): void {
    $fixture = stockReservationProposalFixture();
    $production = stockReservationProduction($fixture, ProductionRunStatus::Scheduled, '2026-08-20');
    $requirement = stockReservationIngredientRequirement($production, $fixture['ingredient'], '10.000000000');
    $eligible = stockReservationLot($fixture, $fixture['ingredient'], '5.000000000', '2026-08-25', '2026-08-01');
    $expired = stockReservationLot($fixture, $fixture['ingredient'], '50.000000000', '2026-08-19', '2026-08-01');
    $notAvailable = stockReservationLot($fixture, $fixture['ingredient'], '50.000000000', '2026-08-25', '2026-08-01', '2026-08-21');
    $quarantined = stockReservationLot($fixture, $fixture['ingredient'], '50.000000000', '2026-08-25', '2026-08-01', null, StockLotStatus::Quarantined);

    $proposal = app(StockReservationProposalService::class)->forRequirement($requirement);

    expect($proposal['proposed'])->toBe('5.000000000')
        ->and($proposal['missing'])->toBe('5.000000000')
        ->and($proposal['eligible_lots'])->toHaveCount(1)
        ->and($proposal['eligible_lots'][0]['lot']->is($eligible))->toBeTrue();

    expect(fn (): array => app(StockReservationProposalService::class)->forRequirement($requirement, [$expired->id]))
        ->toThrow(ValidationException::class);

    $foreignWorkspace = Workspace::factory()->for(User::factory()->create(), 'owner')->create();
    $foreignLot = StockLot::factory()->for($foreignWorkspace)->create([
        'ingredient_id' => $fixture['ingredient']->id,
        'packaging_item_id' => null,
        'status' => StockLotStatus::Released,
        'released_at' => now(),
    ]);

    expect(fn (): array => app(StockReservationProposalService::class)->forRequirement($requirement, [$foreignLot->id]))
        ->toThrow(ValidationException::class);

    expect($notAvailable->status)->toBe(StockLotStatus::Released)
        ->and($quarantined->status)->toBe(StockLotStatus::Quarantined);
});

it('subtracts active reservations from each lot and the requirement remainder', function (): void {
    $fixture = stockReservationProposalFixture();
    $production = stockReservationProduction($fixture, ProductionRunStatus::Scheduled, '2026-08-20');
    $otherProduction = stockReservationProduction($fixture, ProductionRunStatus::Scheduled, '2026-08-21');
    $requirement = stockReservationIngredientRequirement($production, $fixture['ingredient'], '100.000000000');
    $otherRequirement = stockReservationIngredientRequirement($otherProduction, $fixture['ingredient'], '80.000000000');
    $lot = stockReservationLot($fixture, $fixture['ingredient'], '100.000000000', '2026-08-25', '2026-08-01');

    StockReservation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $otherProduction->id,
        'production_requirement_id' => $otherRequirement->id,
        'stock_lot_id' => $lot->id,
        'quantity' => '30.000000000',
        'created_by_user_id' => $fixture['owner']->id,
        'status' => StockReservationStatus::Active,
        'idempotency_key' => 'other-active-reservation',
    ]);
    StockReservation::factory()->create([
        'workspace_id' => $fixture['workspace']->id,
        'production_run_id' => $production->id,
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $lot->id,
        'quantity' => '20.000000000',
        'created_by_user_id' => $fixture['owner']->id,
        'status' => StockReservationStatus::Active,
        'idempotency_key' => 'same-active-reservation',
    ]);

    $proposal = app(StockReservationProposalService::class)->forRequirement($requirement);

    expect($proposal['already_reserved'])->toBe('20.000000000')
        ->and($proposal['remaining'])->toBe('80.000000000')
        ->and($proposal['proposed'])->toBe('50.000000000')
        ->and($proposal['missing'])->toBe('30.000000000');
});

it('orders bulk proposals by production date and rejects draft productions', function (): void {
    $fixture = stockReservationProposalFixture();
    $late = stockReservationProduction($fixture, ProductionRunStatus::Scheduled, '2026-09-01');
    $early = stockReservationProduction($fixture, ProductionRunStatus::Scheduled, '2026-08-01');

    $results = app(StockReservationProposalService::class)->forProductions([$late, $early]);

    expect($results[0]['production']->is($early))->toBeTrue()
        ->and($results[1]['production']->is($late))->toBeTrue();

    $draft = stockReservationProduction($fixture, ProductionRunStatus::Draft, '2026-08-02');

    expect(fn (): array => app(StockReservationProposalService::class)->forProduction($draft))
        ->toThrow(ValidationException::class);
});

/**
 * @return array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}
 */
function stockReservationProposalFixture(): array
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
function stockReservationProduction(array $fixture, ProductionRunStatus $status, string $plannedFor): ProductionRun
{
    return ProductionRun::factory()->for($fixture['workspace'])->create([
        'status' => $status,
        'planned_for' => $plannedFor,
        'created_by_user_id' => $fixture['owner']->id,
    ]);
}

function stockReservationIngredientRequirement(ProductionRun $production, Ingredient $ingredient, string $quantity): ProductionRequirement
{
    return ProductionRequirement::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => null,
        'kind' => ProductionRequirementKind::Ingredient,
        'required_mass_grams' => $quantity,
        'required_units' => null,
        'subject_name_snapshot' => $ingredient->display_name,
    ]);
}

function stockReservationPackagingRequirement(ProductionRun $production, PackagingItem $packaging, int $quantity): ProductionRequirement
{
    return ProductionRequirement::factory()->for($production, 'productionRun')->forPackaging($packaging)->create([
        'required_units' => $quantity,
        'subject_name_snapshot' => $packaging->name,
    ]);
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}  $fixture
 */
function stockReservationLot(
    array $fixture,
    Ingredient $ingredient,
    string $quantity,
    ?string $expiresAt,
    string $stockedAt,
    ?string $availableFrom = null,
    StockLotStatus $status = StockLotStatus::Released,
): StockLot {
    $lot = StockLot::factory()->for($fixture['workspace'])->create([
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => null,
        'status' => $status,
        'stocked_at' => $stockedAt,
        'expires_at' => $expiresAt,
        'available_from' => $availableFrom,
        'released_at' => $status === StockLotStatus::Released ? now() : null,
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => $quantity,
    ]);

    return $lot;
}

/**
 * @param  array{owner: User, workspace: Workspace, ingredient: Ingredient, packaging: PackagingItem}  $fixture
 */
function stockReservationPackagingLot(
    array $fixture,
    PackagingItem $packaging,
    int $quantity,
    ?string $expiresAt,
    string $stockedAt,
): StockLot {
    $lot = StockLot::factory()->for($fixture['workspace'])->forPackaging()->create([
        'ingredient_id' => null,
        'packaging_item_id' => $packaging->id,
        'status' => StockLotStatus::Released,
        'stocked_at' => $stockedAt,
        'expires_at' => $expiresAt,
        'released_at' => now(),
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $fixture['workspace']->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => (string) $quantity,
    ]);

    return $lot;
}
