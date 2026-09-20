<?php

use App\Actions\Inventory\AdjustStockLot;
use App\Actions\Production\AssignProductionBatchNumbers;
use App\Actions\Production\CompleteProduction;
use App\Actions\Production\PrepareProductionStock;
use App\Enums\ProductionRunStatus;
use App\Enums\StockMovementType;
use App\Models\Ingredient;
use App\Models\ProductionConsumption;
use App\Models\ProductionRequirement;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('serializes stock adjustments against another database session', function (string $competingWrite): void {
    if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires an explicitly permitted disposable PostgreSQL database and pcntl.');
    }

    $this->artisan('migrate:fresh', ['--no-interaction' => true])->assertSuccessful();

    $this->travelTo('2026-09-19 12:00:00');
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $workspace->productionEntitlement()->create(['status' => 'active', 'activated_at' => now()]);
    $ingredient = Ingredient::factory()->create();
    $lot = StockLot::factory()->for($workspace)->released()->create(['ingredient_id' => $ingredient->id]);
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);
    $production = ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => today(),
        'output_ready_delay_days' => 0,
    ]);
    $requirement = ProductionRequirement::factory()->for($production, 'productionRun')->create([
        'ingredient_id' => $ingredient->id,
        'required_mass_grams' => '100',
    ]);
    if ($competingWrite === 'consumption') {
        app(AssignProductionBatchNumbers::class)->handle($owner, $workspace, [$production->id]);
        $production->refresh()->update(['status' => ProductionRunStatus::InProduction]);
        ProductionConsumption::factory()->for($production, 'productionRun')->create([
            'production_requirement_id' => $requirement->id,
            'stock_lot_id' => $lot->id,
            'quantity' => '100',
            'recorded_by_user_id' => $owner->id,
        ]);
    }
    $snapshot = app(AdjustStockLot::class)->snapshot($owner, $workspace, $lot->id);
    $key = (string) Str::uuid();
    $adjust = fn (string $requestKey): StockMovement => app(AdjustStockLot::class)->handle(
        $owner, $workspace, $lot->id, 'set_counted', '800', 'g',
        'measurement_difference', null, $snapshot, false, $requestKey,
    );

    $result = stockAdjustmentCompetingSession(
        $workspace->id,
        fn (): int => $adjust($key)->id,
        function () use ($competingWrite, $adjust, $key, $owner, $production): void {
            match ($competingWrite) {
                'different adjustment' => $adjust((string) Str::uuid()),
                'duplicate submission' => $adjust($key),
                'reservation' => app(PrepareProductionStock::class)->handle($owner, [$production->id], 'concurrent-reservation'),
                'consumption' => app(CompleteProduction::class)->handle($owner, $production, '1', '2026-09-19'),
            };
        },
    );

    $adjustments = $lot->movements()->where('type', StockMovementType::StockCountAdjustment)->get();
    if ($competingWrite === 'duplicate submission') {
        expect($result['status'])->toBe('saved')
            ->and($adjustments)->toHaveCount(1)
            ->and($result['id'])->toBe($adjustments->sole()->id);
    } else {
        expect($result['status'])->toBe('validation')
            ->and($result['fields'])->toContain('adjustment_snapshot')
            ->and($adjustments)->toHaveCount($competingWrite === 'different adjustment' ? 1 : 0);
    }
    expect(bcadd((string) $lot->movements()->sum('quantity_delta'), '0', 9))
        ->toBe(match ($competingWrite) {
            'reservation' => '1000.000000000',
            'consumption' => '900.000000000',
            default => '800.000000000',
        });
    if ($competingWrite === 'reservation') {
        expect(bcadd((string) $lot->reservations()->sum('quantity'), '0', 9))->toBe('100.000000000');
    }
})->with(['different adjustment', 'duplicate submission', 'reservation', 'consumption']);

/**
 * Hold the production workspace lock until PostgreSQL confirms the other
 * session is waiting for a lock, then commit a real competing stock action.
 *
 * @return array<string, mixed>
 */
function stockAdjustmentCompetingSession(int $workspaceId, Closure $waitingAction, Closure $winningAction): array
{
    DB::disconnect();
    [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the competing database session.');
    }
    if ($pid === 0) {
        fclose($parentSocket);
        stream_set_timeout($childSocket, 15);
        try {
            DB::reconnect();
            DB::statement("SET statement_timeout = '12s'");
            fwrite($childSocket, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n");
            if (trim((string) fgets($childSocket)) !== 'go') {
                throw new RuntimeException('Parent did not release the competing session.');
            }
            $result = ['status' => 'saved', 'id' => $waitingAction()];
        } catch (ValidationException $exception) {
            $result = ['status' => 'validation', 'fields' => array_keys($exception->errors())];
        } catch (Throwable $exception) {
            $result = ['status' => 'error', 'message' => $exception->getMessage()];
        }
        fwrite($childSocket, json_encode($result, JSON_THROW_ON_ERROR)."\n");
        fclose($childSocket);
        DB::disconnect();
        exit(0);
    }

    fclose($childSocket);
    stream_set_timeout($parentSocket, 15);
    try {
        $backendPid = (int) fgets($parentSocket);
        DB::reconnect();
        DB::beginTransaction();
        Workspace::withoutGlobalScopes()->whereKey($workspaceId)->lockForUpdate()->firstOrFail();
        fwrite($parentSocket, "go\n");
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendPid]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($waiting?->wait_event_type !== 'Lock') {
            throw new RuntimeException('Competing action did not wait on the workspace lock.');
        }
        $winningAction();
        DB::commit();

        return json_decode((string) fgets($parentSocket), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fclose($parentSocket);
        pcntl_waitpid($pid, $status);
    }
}
