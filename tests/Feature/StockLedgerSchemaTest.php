<?php

use App\Models\Ingredient;
use App\Models\MediaAsset;
use App\Models\ProductionDocument;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\ProductionDocumentType;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockUnitKind;
use Database\Factories\PackagingItemFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the lot movement and production document schema', function (): void {
    expect(Schema::hasColumns('stock_lots', [
        'workspace_id',
        'ingredient_id',
        'packaging_item_id',
        'supplier_listing_id',
        'organic_status',
        'internal_lot_code',
        'supplier_batch_number',
        'origin',
        'unit_kind',
        'status',
        'stocked_at',
        'expires_at',
        'available_from',
        'provenance_complete',
        'historical_unit_cost',
        'currency',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('stock_movements', [
            'workspace_id',
            'stock_lot_id',
            'type',
            'quantity_delta',
            'original_quantity',
            'original_unit',
            'occurred_at',
            'actor_user_id',
            'source_type',
            'source_id',
            'reversal_of_stock_movement_id',
            'idempotency_key',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('production_documents', [
            'workspace_id',
            'media_asset_id',
            'documentable_type',
            'documentable_id',
            'type',
            'attached_by_user_id',
            'note',
        ]))->toBeTrue();
});

it('requires a lot to reference exactly one correctly typed stock subject', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItemFactory::new()->for($workspace)->create();

    expect(fn () => StockLot::query()->create([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => $packaging->id,
        'internal_lot_code' => 'LOT-BOTH',
        'origin' => StockLotOrigin::OpeningBalance,
        'unit_kind' => StockUnitKind::Mass,
        'status' => StockLotStatus::Released,
        'stocked_at' => now()->toDateString(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => StockLot::query()->create([
            'workspace_id' => $workspace->id,
            'internal_lot_code' => 'LOT-NONE',
            'origin' => StockLotOrigin::OpeningBalance,
            'unit_kind' => StockUnitKind::Mass,
            'status' => StockLotStatus::Released,
            'stocked_at' => now()->toDateString(),
        ]))->toThrow(QueryException::class)
        ->and(fn () => StockLot::query()->create([
            'workspace_id' => $workspace->id,
            'ingredient_id' => $ingredient->id,
            'internal_lot_code' => 'LOT-WRONG-KIND',
            'origin' => StockLotOrigin::OpeningBalance,
            'unit_kind' => StockUnitKind::Count,
            'status' => StockLotStatus::Released,
            'stocked_at' => now()->toDateString(),
        ]))->toThrow(QueryException::class);
});

it('keeps internal lot codes unique per workspace while allowing repeated supplier batches', function (): void {
    $firstWorkspace = Workspace::factory()->create();
    $secondWorkspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();

    StockLot::factory()->for($firstWorkspace)->for($ingredient)->create([
        'internal_lot_code' => 'SK-RAW-000001',
        'supplier_batch_number' => 'SUP-BATCH-44',
    ]);
    StockLot::factory()->for($firstWorkspace)->for($ingredient)->create([
        'internal_lot_code' => 'SK-RAW-000002',
        'supplier_batch_number' => 'SUP-BATCH-44',
    ]);
    StockLot::factory()->for($secondWorkspace)->for($ingredient)->create([
        'internal_lot_code' => 'SK-RAW-000001',
        'supplier_batch_number' => 'SUP-BATCH-44',
    ]);

    expect(StockLot::query()->where('supplier_batch_number', 'SUP-BATCH-44')->count())->toBe(3)
        ->and(fn () => StockLot::factory()->for($firstWorkspace)->for($ingredient)->create([
            'internal_lot_code' => 'SK-RAW-000001',
        ]))->toThrow(QueryException::class);
});

it('casts lot and movement quantities without losing canonical precision', function (): void {
    $lot = StockLot::factory()->released()->create([
        'historical_unit_cost' => '0.004535924',
    ]);
    $movement = StockMovement::factory()->for($lot)->create([
        'workspace_id' => $lot->workspace_id,
        'quantity_delta' => '453.592370000',
        'original_quantity' => '1.000000000',
        'original_unit' => 'lb',
    ]);

    expect($lot->unit_kind)->toBe(StockUnitKind::Mass)
        ->and($lot->status)->toBe(StockLotStatus::Released)
        ->and($lot->origin)->toBe(StockLotOrigin::OpeningBalance)
        ->and($lot->historical_unit_cost)->toBe('0.004535924')
        ->and($movement->type)->toBe(StockMovementType::OpeningBalance)
        ->and($movement->quantity_delta)->toBe('453.592370000')
        ->and($movement->original_quantity)->toBe('1.000000000');
});

it('rejects fractional movements for count lots', function (): void {
    $lot = StockLot::factory()->forPackaging()->released()->create();

    StockMovement::factory()->for($lot)->create([
        'workspace_id' => $lot->workspace_id,
        'quantity_delta' => '1.500000000',
    ]);
})->throws(DomainException::class, 'Count movements must use whole quantities');

it('prevents posted movements from being edited or deleted', function (): void {
    $movement = StockMovement::factory()->create();

    expect(fn () => $movement->update(['note' => 'Rewritten history']))
        ->toThrow(LogicException::class, 'Posted stock movements are immutable')
        ->and(fn () => $movement->delete())
        ->toThrow(LogicException::class, 'Posted stock movements are immutable');
});

it('associates typed private documents with production records', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $lot = StockLot::factory()->for($workspace)->create();
    $asset = MediaAsset::factory()->for($workspace)->for($owner, 'uploadedBy')->pdf()->ready()->create();

    $document = ProductionDocument::factory()
        ->for($workspace)
        ->for($asset, 'mediaAsset')
        ->for($owner, 'attachedBy')
        ->for($lot, 'documentable')
        ->create([
            'type' => ProductionDocumentType::CertificateOfAnalysis,
        ]);

    expect($document->type)->toBe(ProductionDocumentType::CertificateOfAnalysis)
        ->and($document->documentable->is($lot))->toBeTrue()
        ->and($document->mediaAsset->is($asset))->toBeTrue()
        ->and($document->workspace->is($workspace))->toBeTrue();
});
