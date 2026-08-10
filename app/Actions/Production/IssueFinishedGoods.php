<?php

namespace App\Actions\Production;

use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueFinishedGoods
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Issue finished units (or intermediate mass) from a released output lot.
     * Only shipment, sample, damaged, and internal-use reasons are allowed.
     */
    public function handle(
        User $actor,
        StockLot $outputLot,
        StockMovementType $kind,
        string $quantity,
        ?string $note = null,
    ): StockLot {
        if (! in_array($kind, [
            StockMovementType::Shipment,
            StockMovementType::Sample,
            StockMovementType::Damaged,
            StockMovementType::InternalUse,
        ], true)) {
            throw ValidationException::withMessages([
                'kind' => __('production_bench.production.validation.issue_kind_invalid'),
            ]);
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', trim($quantity)) !== 1 || bccomp(trim($quantity), '0', 18) <= 0) {
            throw ValidationException::withMessages([
                'quantity' => __('production_bench.production.validation.issue_quantity_positive'),
            ]);
        }

        $this->access->assertWritable($actor, $outputLot->workspace);

        return DB::transaction(function () use ($actor, $kind, $note, $outputLot, $quantity): StockLot {
            $lockedLot = StockLot::query()
                ->withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($outputLot->id);

            if ($lockedLot->origin !== StockLotOrigin::ProductionOutput) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.issue_output_lot_required'),
                ]);
            }

            if ($lockedLot->status !== StockLotStatus::Released) {
                throw ValidationException::withMessages([
                    'lot' => __('production_bench.production.validation.issue_output_released_required'),
                ]);
            }

            if ($lockedLot->unit_kind === StockUnitKind::Count && preg_match('/^\d+$/', trim($quantity)) !== 1) {
                throw ValidationException::withMessages([
                    'quantity' => __('production_bench.production.validation.issue_finished_whole'),
                ]);
            }

            $available = (string) $lockedLot->movements()->sum('quantity_delta');

            // Intermediate output lots are ingredients: active reservations of
            // later productions reduce what can be issued.
            if ($lockedLot->ingredient_id !== null) {
                $reserved = (string) StockReservation::query()
                    ->where('stock_lot_id', $lockedLot->id)
                    ->where('status', StockReservationStatus::Active)
                    ->sum('quantity');
                $available = bcsub($available, $reserved, 9);
            }

            if (bccomp($quantity, $available, 9) > 0) {
                throw ValidationException::withMessages([
                    'quantity' => __('production_bench.production.validation.issue_quantity_unavailable', ['available' => $available]),
                ]);
            }

            $unit = $lockedLot->unit_kind === StockUnitKind::Mass ? 'g' : 'unit';

            $lockedLot->movements()->create([
                'workspace_id' => $lockedLot->workspace_id,
                'type' => $kind,
                'quantity_delta' => '-'.$quantity,
                'original_quantity' => $quantity,
                'original_unit' => $unit,
                'occurred_at' => now(),
                'actor_user_id' => $actor->id,
                'source_type' => (new StockLot)->getMorphClass(),
                'source_id' => $lockedLot->id,
                'idempotency_key' => (string) Str::uuid(),
                'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            return $lockedLot->refresh();
        }, attempts: 5);
    }
}
