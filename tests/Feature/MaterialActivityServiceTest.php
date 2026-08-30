<?php

use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
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
        ->and($activity['reconciliation_delta'])->toBe('0.000000000')
        ->and($activity['movements'])->toHaveCount(4)
        ->and($activity['movements']->pluck('group')->all())->toBe(['adjustments', 'other_outbound', 'production_consumed', 'received']);
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
