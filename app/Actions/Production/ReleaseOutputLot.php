<?php

namespace App\Actions\Production;

use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Models\StockLot;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReleaseOutputLot
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    /**
     * Release a quarantined output lot. Release is blocked until the lot's
     * availability date has passed, so curing delays are respected.
     */
    public function handle(User $actor, StockLot $lot, ?string $note = null): StockLot
    {
        $this->access->assertWritable($actor, $lot->workspace);

        return DB::transaction(function () use ($actor, $lot, $note): StockLot {
            $lockedLot = StockLot::query()
                ->withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($lot->id);

            if ($lockedLot->origin !== StockLotOrigin::ProductionOutput) {
                throw ValidationException::withMessages([
                    'lot' => 'Only production output lots can be released.',
                ]);
            }

            if ($lockedLot->status !== StockLotStatus::Quarantined) {
                throw ValidationException::withMessages([
                    'lot' => 'Only a quarantined output lot can be released.',
                ]);
            }

            if ($lockedLot->available_from !== null && $lockedLot->available_from->isFuture()) {
                throw ValidationException::withMessages([
                    'lot' => 'This output lot is not available yet (available from '.$lockedLot->available_from->toDateString().').',
                ]);
            }

            $lockedLot->update([
                'status' => StockLotStatus::Released,
                'released_at' => now(),
                'released_by_user_id' => $actor->id,
                'release_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            return $lockedLot->refresh();
        }, attempts: 5);
    }
}
