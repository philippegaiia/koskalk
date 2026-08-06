<?php

namespace App\Actions\Production;

use App\Models\StockLot;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockUnitKind;
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
                'kind' => 'Finished goods can only be issued as shipment, sample, damaged, or internal use.',
            ]);
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', trim($quantity)) !== 1 || bccomp(trim($quantity), '0', 18) <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'The issued quantity must be greater than zero.',
            ]);
        }

        $this->access->assertWritable($actor, $outputLot->workspace);

        return DB::transaction(function () use ($actor, $kind, $note, $outputLot, $quantity): StockLot {
            $lockedLot = StockLot::query()
                ->withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($outputLot->id);

            if ($lockedLot->status !== StockLotStatus::Released) {
                throw ValidationException::withMessages([
                    'lot' => 'Only a released output lot can be issued.',
                ]);
            }

            $physical = (string) $lockedLot->movements()->sum('quantity_delta');

            if (bccomp($quantity, $physical, 9) > 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Not enough available output: '.$physical.' remaining.',
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
