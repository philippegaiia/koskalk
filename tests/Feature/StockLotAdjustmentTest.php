<?php

use App\Actions\Inventory\AdjustStockLot;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\WorkspaceMemberRole;
use App\Models\CurrentMaterialPrice;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('posts set counted add and remove adjustments as immutable ledger movements', function (string $mode, string $quantity, string $unit, string $expectedDelta, string $expectedAfter): void {
    [$actor, $workspace, $lot] = adjustmentLot('12000.000000000');
    $action = app(AdjustStockLot::class);

    $movement = $action->handle(
        actor: $actor,
        workspace: $workspace,
        lotIdentifier: $lot->id,
        mode: $mode,
        enteredQuantity: $quantity,
        enteredUnit: $unit,
        reason: 'measurement_difference',
        note: 'Cycle count',
        snapshot: $action->snapshot($actor, $workspace, $lot->id),
        shortageAcknowledged: false,
        idempotencyKey: (string) Str::uuid(),
    );

    expect($movement->type)->toBe(StockMovementType::StockCountAdjustment)
        ->and($movement->quantity_delta)->toBe($expectedDelta)
        ->and($movement->adjustment_details)->toMatchArray([
            'version' => 1,
            'mode' => $mode,
            'reason' => 'measurement_difference',
            'physical_before' => '12000.000000000',
            'physical_after' => $expectedAfter,
            'reserved_at_posting' => '0.000000000',
            'shortage_acknowledged' => false,
        ])
        ->and($movement->note)->toBe('Cycle count');

    expect(fn () => $movement->update(['note' => 'changed']))->toThrow(LogicException::class);
})->with([
    'set counted in kilograms' => ['set_counted', '11.7', 'kg', '-300.000000000', '11700.000000000'],
    'add in ounces' => ['add', '1', 'oz', '28.349523125', '12028.349523125'],
    'remove in pounds' => ['remove', '1', 'lb', '-453.592370000', '11546.407630000'],
]);

it('supports zero counts and partial repair of a negative balance', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot('1000.000000000');
    $action = app(AdjustStockLot::class);

    $zero = postAdjustment($action, $actor, $workspace, $lot, 'set_counted', '0', 'g');
    [$negativeActor, $negativeWorkspace, $negativeLot] = adjustmentLot('-1000.000000000');
    $partialRepair = postAdjustment($action, $negativeActor, $negativeWorkspace, $negativeLot, 'add', '500', 'g');

    expect($zero->quantity_delta)->toBe('-1000.000000000')
        ->and($partialRepair->quantity_delta)->toBe('500.000000000')
        ->and($partialRepair->adjustment_details['physical_after'])->toBe('-500.000000000');
});

it('requires whole packaging counts and preserves acquisition costs', function (): void {
    [$actor, $workspace] = adjustmentWorkspace();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $lot = StockLot::factory()->for($workspace)->forPackaging()->create([
        'packaging_item_id' => $packaging->id,
        'historical_unit_cost' => '2.500000000',
        'costing_unit_cost' => '2.750000000',
    ]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '10.000000000',
        'original_quantity' => '10.000000000',
        'original_unit' => 'count',
    ]);
    $action = app(AdjustStockLot::class);

    expect(fn () => postAdjustment($action, $actor, $workspace, $lot, 'add', '0.5', 'count'))
        ->toThrow(ValidationException::class);

    $movement = postAdjustment($action, $actor, $workspace, $lot, 'remove', '2', 'count');

    expect($movement->original_quantity)->toBe('-2.000000000')
        ->and($movement->original_unit)->toBe('count')
        ->and($lot->fresh()->historical_unit_cost)->toBe('2.500000000')
        ->and($lot->fresh()->costing_unit_cost)->toBe('2.750000000');
});

it('leaves receipt quantities and current material prices unchanged', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot('5000.000000000');
    $receiptLine = GoodsReceiptLine::factory()->direct()->for($lot, 'stockLot')->create([
        'actual_quantity' => '5000.000000000',
        'historical_total_cost' => '75.000000000',
        'costing_total_cost' => '75.000000000',
    ]);
    $price = CurrentMaterialPrice::factory()->for($workspace)->for($lot->ingredient)->create([
        'price_per_canonical_unit' => '0.015000000000',
    ]);

    postAdjustment(app(AdjustStockLot::class), $actor, $workspace, $lot, 'remove', '250', 'g');

    expect($receiptLine->fresh()->actual_quantity)->toBe('5000.000000000')
        ->and($receiptLine->fresh()->historical_total_cost)->toBe('75.000000000')
        ->and($receiptLine->fresh()->costing_total_cost)->toBe('75.000000000')
        ->and($price->fresh()->price_per_canonical_unit)->toBe('0.015000000000');
});

it('rejects invalid quantities no changes and removal beyond physical stock', function (string $mode, string $quantity, string $unit): void {
    [$actor, $workspace, $lot] = adjustmentLot('1000.000000000');
    $action = app(AdjustStockLot::class);

    expect(fn () => postAdjustment($action, $actor, $workspace, $lot, $mode, $quantity, $unit))
        ->toThrow(ValidationException::class);

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->count())->toBe(0);
})->with([
    'scientific notation' => ['add', '1e2', 'g'],
    'too much precision' => ['add', '0.0000000001', 'g'],
    'overflow' => ['add', '100000000000', 'g'],
    'zero add' => ['add', '0', 'g'],
    'matching count' => ['set_counted', '1000', 'g'],
    'excessive removal' => ['remove', '1001', 'g'],
    'unsupported unit' => ['add', '1', 'ml'],
]);

it('validates reasons notes and removal-only reasons', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot('1000.000000000');
    $action = app(AdjustStockLot::class);
    $snapshot = $action->snapshot($actor, $workspace, $lot->id);

    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1', 'g', 'spillage', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1', 'g', 'other', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1', 'g', 'unknown', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

it('requires an acknowledgement below raw active reservations including quarantine', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot('1000.000000000', StockLotStatus::Quarantined);
    StockReservation::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity' => '800.000000000',
        'created_by_user_id' => $actor->id,
    ]);
    $action = app(AdjustStockLot::class);
    $snapshot = $action->snapshot($actor, $workspace, $lot->id);

    expect($snapshot['reserved'])->toBe('800.000000000');
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'set_counted', '700', 'g', 'measurement_difference', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $movement = $action->handle($actor, $workspace, $lot->id, 'set_counted', '700', 'g', 'measurement_difference', null, $snapshot, true, (string) Str::uuid());

    expect($movement->adjustment_details['shortage_acknowledged'])->toBeTrue()
        ->and($lot->fresh()->status)->toBe(StockLotStatus::Quarantined);
});

it('rejects stale snapshots while preserving exactly-once replay semantics', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot('1000.000000000');
    $action = app(AdjustStockLot::class);
    $stale = $action->snapshot($actor, $workspace, $lot->id);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => '10.000000000',
    ]);

    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1', 'g', 'entry_error', null, $stale, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $fresh = $action->snapshot($actor, $workspace, $lot->id);
    $key = (string) Str::uuid();
    $first = $action->handle($actor, $workspace, $lot->id, 'add', '1', 'g', 'entry_error', null, $fresh, false, $key);
    $replay = $action->handle($actor, $workspace, $lot->id, 'add', '1.000', 'g', 'entry_error', null, $fresh, false, $key);

    expect($replay->is($first))->toBeTrue();
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '2', 'g', 'entry_error', null, $fresh, false, $key))
        ->toThrow(ValidationException::class);
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1e0', 'g', 'entry_error', null, $fresh, false, $key))
        ->toThrow(ValidationException::class);
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1.0000000001', 'g', 'entry_error', null, $fresh, false, $key))
        ->toThrow(ValidationException::class);
});

it('enforces workspace access and rejects finished-product lots', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot();
    [$foreignActor, $foreignWorkspace] = adjustmentWorkspace();
    $action = app(AdjustStockLot::class);

    expect(fn () => $action->snapshot($foreignActor, $workspace, $lot->id))->toThrow(AuthorizationException::class);
    expect(fn () => $action->snapshot($foreignActor, $foreignWorkspace, $lot->id))->toThrow(ModelNotFoundException::class);

    $productLot = StockLot::factory()->for($workspace)->forRecipe()->create();
    expect(fn () => $action->snapshot($actor, $workspace, $productLot->id))->toThrow(ModelNotFoundException::class);
});

it('rechecks viewer inactive and foreign-workspace access at submission', function (): void {
    [$actor, $workspace, $lot] = adjustmentLot();
    $action = app(AdjustStockLot::class);
    $snapshot = $action->snapshot($actor, $workspace, $lot->id);
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($workspace)->for($viewer)->create(['role' => WorkspaceMemberRole::Viewer]);

    expect(fn () => $action->handle($viewer, $workspace, $lot->id, 'add', '1', 'g', 'entry_error', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    app(ProductionBenchAccess::class)->cancel($actor, $workspace);
    expect(fn () => $action->handle($actor, $workspace, $lot->id, 'add', '1', 'g', 'entry_error', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    [$foreignActor, $foreignWorkspace] = adjustmentWorkspace();
    expect(fn () => $action->handle($foreignActor, $foreignWorkspace, $lot->id, 'add', '1', 'g', 'entry_error', null, $snapshot, false, (string) Str::uuid()))
        ->toThrow(ModelNotFoundException::class);

    expect(StockMovement::query()->where('type', StockMovementType::StockCountAdjustment)->count())->toBe(0);
});

it('preserves sqlite movement triggers and indexes through migration rollback and reapply', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('SQLite-specific migration rebuild regression.');
    }

    $triggerNames = fn (): array => collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'stock_movements' ORDER BY name"))
        ->pluck('name')
        ->all();
    $indexNames = fn (): array => collect(DB::select("PRAGMA index_list('stock_movements')"))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
    $beforeTriggers = $triggerNames();
    $beforeIndexes = $indexNames();
    $migration = require database_path('migrations/2026_09_19_042142_add_adjustment_details_to_stock_movements_table.php');

    $migration->down();

    expect(Schema::hasColumn('stock_movements', 'adjustment_details'))->toBeFalse()
        ->and($triggerNames())->toBe($beforeTriggers)
        ->and($indexNames())->toBe($beforeIndexes);

    $migration->up();

    expect(Schema::hasColumn('stock_movements', 'adjustment_details'))->toBeTrue()
        ->and($triggerNames())->toBe($beforeTriggers)
        ->and($indexNames())->toBe($beforeIndexes);
});

/** @return array{User, Workspace} */
function adjustmentWorkspace(): array
{
    $actor = User::factory()->create();
    $workspace = Workspace::factory()->for($actor, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($actor, $workspace);

    return [$actor, $workspace];
}

/** @return array{User, Workspace, StockLot} */
function adjustmentLot(string $physical = '1000.000000000', StockLotStatus $status = StockLotStatus::Released): array
{
    [$actor, $workspace] = adjustmentWorkspace();
    $ingredient = Ingredient::factory()->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->create(['status' => $status]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'quantity_delta' => $physical,
    ]);

    return [$actor, $workspace, $lot];
}

function postAdjustment(
    AdjustStockLot $action,
    User $actor,
    Workspace $workspace,
    StockLot $lot,
    string $mode,
    string $quantity,
    string $unit,
): StockMovement {
    return $action->handle(
        actor: $actor,
        workspace: $workspace,
        lotIdentifier: $lot->id,
        mode: $mode,
        enteredQuantity: $quantity,
        enteredUnit: $unit,
        reason: 'measurement_difference',
        note: null,
        snapshot: $action->snapshot($actor, $workspace, $lot->id),
        shortageAcknowledged: false,
        idempotencyKey: (string) Str::uuid(),
    );
}
