<?php

use App\Enums\ProductionRunStatus;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Workspace;
use App\Services\Inventory\WorkspaceStockLotQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies lot filters and preserves the requested stock date and code orders', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = $workspace->owner()->firstOrFail();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Filtered oil']);
    $otherIngredient = Ingredient::factory()->create(['display_name' => 'Other oil']);

    $firstLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'MATCH-A',
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today()->subDays(3)->toDateString(),
        'expires_at' => today()->addDays(2)->toDateString(),
    ]);
    $secondLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'MATCH-C',
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today()->subDay()->toDateString(),
        'expires_at' => today()->addDays(2)->toDateString(),
    ]);
    StockLot::factory()->for($workspace)->for($ingredient)->create([
        'internal_lot_code' => 'MATCH-B',
        'origin' => StockLotOrigin::PurchaseReceipt,
        'stocked_at' => today()->toDateString(),
        'expires_at' => today()->addDays(2)->toDateString(),
    ]);
    StockLot::factory()->for($workspace)->for($otherIngredient)->released()->create([
        'internal_lot_code' => 'MATCH-D',
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today()->toDateString(),
        'expires_at' => today()->addDays(2)->toDateString(),
    ]);
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'MATCH-E',
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today()->subDays(10)->toDateString(),
        'expires_at' => today()->addDays(2)->toDateString(),
    ]);
    StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'internal_lot_code' => 'MATCH-F',
        'origin' => StockLotOrigin::OpeningBalance,
        'stocked_at' => today()->toDateString(),
        'expires_at' => today()->subDay()->toDateString(),
    ]);

    $filters = [
        'search' => 'match-',
        'lotMaterial' => $ingredient->public_id,
        'lotMaterialType' => 'ingredient',
        'lotScope' => 'all',
        'lotStatus' => StockLotStatus::Released->value,
        'lotOrigin' => StockLotOrigin::OpeningBalance->value,
        'lotDateBasis' => 'stocked',
        'lotDateFrom' => today()->subDays(7)->toDateString(),
        'lotDateUntil' => today()->toDateString(),
        'lotExpiry' => 'active',
        'lotStorageLocation' => 'all',
        'lotSort' => 'code',
    ];
    $query = app(WorkspaceStockLotQuery::class);

    $byCode = $query->query($actor, $workspace, $filters)->get();
    $newest = $query->query($actor, $workspace, [...$filters, 'lotSort' => 'newest'])->get();
    $oldest = $query->query($actor, $workspace, [...$filters, 'lotSort' => 'oldest'])->get();

    expect($byCode->modelKeys())->toBe([$firstLot->id, $secondLot->id])
        ->and($newest->modelKeys())->toBe([$secondLot->id, $firstLot->id])
        ->and($oldest->modelKeys())->toBe([$firstLot->id, $secondLot->id]);
});

it('keeps zero-physical lots with active reservations and negative lots open', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = $workspace->owner()->firstOrFail();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Reserved oil']);
    $exhaustedLot = StockLot::factory()->for($workspace)->for($ingredient)->create([
        'status' => StockLotStatus::Quarantined,
    ]);
    $reservedLot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    $negativeLot = StockLot::factory()->for($workspace)->for($ingredient)->create();

    $productionRun = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Draft,
    ]);
    $requirement = ProductionRequirement::factory()
        ->for($productionRun, 'productionRun')
        ->for($ingredient)
        ->create();
    StockReservation::factory()->create([
        'workspace_id' => $workspace->id,
        'production_run_id' => $productionRun->id,
        'production_requirement_id' => $requirement->id,
        'stock_lot_id' => $reservedLot->id,
        'quantity' => '1.000000000',
        'created_by_user_id' => $actor->id,
    ]);
    StockMovement::factory()->for($negativeLot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::StockCountAdjustment,
        'quantity_delta' => '-0.500000000',
    ]);

    $query = app(WorkspaceStockLotQuery::class);
    $exhaustedIds = $query->query($actor, $workspace, ['lotScope' => 'exhausted'])->get()->modelKeys();
    $openIds = $query->query($actor, $workspace, ['lotScope' => 'open'])->get()->modelKeys();

    expect($exhaustedIds)->toBe([$exhaustedLot->id])
        ->and($openIds)->toContain($reservedLot->id, $negativeLot->id)
        ->and($openIds)->not->toContain($exhaustedLot->id);
});
