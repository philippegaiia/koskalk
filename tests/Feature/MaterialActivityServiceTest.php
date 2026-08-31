<?php

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\MaterialActivityService;
use App\Services\ProductionBenchAccess;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reconciles receipts production consumption and other movement groups', function (): void {
    $workspace = productionBenchWorkspaceForActivity();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    $periodStart = CarbonImmutable::parse('2026-08-01 00:00:00');
    $periodEnd = CarbonImmutable::parse('2026-08-31 23:59:59');

    createActivityMovement($workspace, $lot, StockMovementType::OpeningBalance, '1000', '2026-07-01 08:00:00');
    createActivityMovement($workspace, $lot, StockMovementType::PurchaseReceipt, '500', '2026-08-05 08:00:00');
    createActivityMovement($workspace, $lot, StockMovementType::ProductionConsumption, '-125', '2026-08-10 08:00:00');
    createActivityMovement($workspace, $lot, StockMovementType::Damaged, '-25', '2026-08-11 08:00:00');
    createActivityMovement($workspace, $lot, StockMovementType::StockCountAdjustment, '10', '2026-08-12 08:00:00');

    $activity = app(MaterialActivityService::class)->forPeriod($workspace, $ingredient, $periodStart, $periodEnd);

    expect($activity['opening_physical'])->toBe('1000.000000000')
        ->and($activity['closing_physical'])->toBe('1360.000000000')
        ->and($activity['received'])->toBe('500.000000000')
        ->and($activity['production_consumed'])->toBe('125.000000000')
        ->and($activity['other_outbound'])->toBe('25.000000000')
        ->and($activity['adjustments'])->toBe('10.000000000')
        ->and($activity['net_change'])->toBe('360.000000000')
        ->and($activity['reconciliation_delta'])->toBe('0.000000000');

    $movements = app(MaterialActivityService::class)->paginateMovements($workspace, $ingredient, $periodStart, $periodEnd);

    expect($movements->total())->toBe(4)
        ->and($movements->pluck('group')->all())->toBe(['adjustments', 'other_outbound', 'production_consumed', 'received']);
});

it('paginates period movement rows while reconciliation still covers every movement', function (): void {
    $workspace = productionBenchWorkspaceForActivity();
    $ingredient = Ingredient::factory()->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();

    foreach (range(1, 30) as $n) {
        createActivityMovement(
            $workspace,
            $lot,
            StockMovementType::PurchaseReceipt,
            '10',
            CarbonImmutable::parse('2026-08-01 08:00:00')->addHours($n)->toDateTimeString(),
        );
    }

    $periodStart = CarbonImmutable::parse('2026-08-01 00:00:00');
    $periodEnd = CarbonImmutable::parse('2026-08-31 23:59:59');
    $service = app(MaterialActivityService::class);

    request()->merge(['activity' => 2]);
    $secondPage = $service->paginateMovements($workspace, $ingredient, $periodStart, $periodEnd, 25, 'activity');

    expect($secondPage->total())->toBe(30)
        ->and($secondPage)->toHaveCount(5)
        ->and($secondPage->currentPage())->toBe(2)
        ->and($secondPage->first()['movement']->occurred_at->format('Y-m-d H:i'))->toBe('2026-08-01 13:00')
        ->and($secondPage->last()['movement']->occurred_at->format('Y-m-d H:i'))->toBe('2026-08-01 09:00')
        ->and($secondPage->first()['quantity_delta'])->toBe('10.000000000')
        ->and($secondPage->first()['group'])->toBe('received');

    // Totals are summed over all 30 movements, not just the current page.
    $activity = $service->forPeriod($workspace, $ingredient, $periodStart, $periodEnd);
    expect($activity['received'])->toBe('300.000000000')
        ->and($activity['reconciliation_delta'])->toBe('0.000000000');
});

it('includes consumption posted by an aborted production as production consumption', function (): void {
    $workspace = productionBenchWorkspaceForActivity();
    $ingredient = Ingredient::factory()->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    createActivityMovement($workspace, $lot, StockMovementType::ProductionConsumption, '-50', '2026-08-15 08:00:00');

    $activity = app(MaterialActivityService::class)->forPeriod(
        $workspace,
        $ingredient,
        CarbonImmutable::parse('2026-08-01'),
        CarbonImmutable::parse('2026-08-31 23:59:59'),
    );

    expect($activity['production_consumed'])->toBe('50.000000000');
});

it('bounds open lots to the ten soonest to expire', function (): void {
    $workspace = productionBenchWorkspaceForActivity();
    $ingredient = Ingredient::factory()->create();

    $lots = collect(range(1, 12))
        ->map(fn (int $n): StockLot => StockLot::factory()
            ->for($workspace)
            ->for($ingredient)
            ->released()
            ->create([
                'stocked_at' => '2026-01-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
                'expires_at' => '2026-09-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            ]))
        ->all();

    foreach ($lots as $lot) {
        createActivityMovement($workspace, $lot, StockMovementType::OpeningBalance, '100', '2026-01-15 08:00:00');
    }

    $openLots = app(MaterialActivityService::class)->openLots($workspace, $ingredient);

    expect($openLots)->toHaveCount(10)
        ->and($openLots->pluck('id')->all())->toBe(collect($lots)->take(10)->pluck('id')->all());
});

it('orders open lots first-expiring first with unexpiring lots last', function (): void {
    $workspace = productionBenchWorkspaceForActivity();
    $ingredient = Ingredient::factory()->create();

    // Deliberately ordered so the previous stocked_at-descending order disagrees
    // with the FEFO order in every position.
    $noExpiryNewest = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-05',
        'expires_at' => null,
    ]);
    $expiringLater = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-04',
        'expires_at' => '2026-09-10',
    ]);
    $expiringSooner = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-03',
        'expires_at' => '2026-09-05',
    ]);
    // Same expiry as $expiringSooner: the older-stocked lot surfaces first.
    $sameExpiryOlder = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-01',
        'expires_at' => '2026-09-05',
    ]);

    foreach ([$noExpiryNewest, $expiringLater, $expiringSooner, $sameExpiryOlder] as $lot) {
        createActivityMovement($workspace, $lot, StockMovementType::OpeningBalance, '100', '2026-01-15 08:00:00');
    }

    $openLots = app(MaterialActivityService::class)->openLots($workspace, $ingredient);

    expect($openLots->pluck('id')->all())->toBe([
        $sameExpiryOlder->id,
        $expiringSooner->id,
        $expiringLater->id,
        $noExpiryNewest->id,
    ]);
});

it('keeps zero-balance lots that still carry an active reservation open', function (): void {
    $workspace = productionBenchWorkspaceForActivity();
    $ingredient = Ingredient::factory()->create();

    // Net zero, but reserved: hidden by the old "physical <> 0" rule even though
    // it is over-reserved and needs attention.
    $reserved = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-01',
        'expires_at' => '2026-09-01',
    ]);
    createActivityMovement($workspace, $reserved, StockMovementType::OpeningBalance, '5', '2026-01-15 08:00:00');
    createActivityMovement($workspace, $reserved, StockMovementType::ProductionConsumption, '-5', '2026-02-15 08:00:00');
    StockReservation::factory()->for($workspace)->for($reserved, 'stockLot')->create(['quantity' => '2.000000000']);

    // Net zero with only a released reservation: genuinely finished, must drop out.
    $releasedReservation = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-01',
        'expires_at' => '2026-09-02',
    ]);
    createActivityMovement($workspace, $releasedReservation, StockMovementType::OpeningBalance, '4', '2026-01-15 08:00:00');
    createActivityMovement($workspace, $releasedReservation, StockMovementType::ProductionConsumption, '-4', '2026-02-15 08:00:00');
    StockReservation::factory()
        ->for($workspace)
        ->for($releasedReservation, 'stockLot')
        ->released()
        ->create(['quantity' => '3.000000000']);

    // Untouched stock: open on the physical balance alone.
    $inStock = StockLot::factory()->for($workspace)->for($ingredient)->released()->create([
        'stocked_at' => '2026-01-01',
        'expires_at' => '2026-09-03',
    ]);
    createActivityMovement($workspace, $inStock, StockMovementType::OpeningBalance, '7', '2026-01-15 08:00:00');

    expect(app(MaterialActivityService::class)->openLots($workspace, $ingredient)->pluck('id')->all())
        ->toBe([$reserved->id, $inStock->id]);
});

function productionBenchWorkspaceForActivity(): Workspace
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);

    return $workspace;
}

function createActivityMovement(Workspace $workspace, StockLot $lot, StockMovementType $type, string $delta, string $occurredAt): StockMovement
{
    return StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => $type,
        'quantity_delta' => $delta,
        'occurred_at' => $occurredAt,
    ]);
}
