<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Enums\StockReservationStatus;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inventory\StockAdjustmentCalculator;
use App\Services\ProductionBenchAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdjustStockLot
{
    private const array Reasons = [
        'measurement_difference',
        'spillage',
        'damaged_discarded',
        'entry_error',
        'other',
    ];

    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly StockAdjustmentCalculator $calculator,
    ) {}

    /**
     * @param  array{latest_movement_id: int|null, physical: string, reserved: string, status: string}  $snapshot
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        int|string $lotIdentifier,
        string $mode,
        mixed $enteredQuantity,
        string $enteredUnit,
        string $reason,
        ?string $note,
        array $snapshot,
        bool $shortageAcknowledged,
        string $idempotencyKey,
    ): StockMovement {
        $note = filled($note) ? trim((string) $note) : null;
        $this->validateRequest($reason, $note, $idempotencyKey);

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $lotIdentifier,
            $mode,
            $enteredQuantity,
            $enteredUnit,
            $reason,
            $note,
            $snapshot,
            $shortageAcknowledged,
            $idempotencyKey,
        ): StockMovement {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lot = $this->resolveLot($lockedWorkspace, $lotIdentifier, lock: true);

            $existing = StockMovement::query()
                ->where('workspace_id', $lockedWorkspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof StockMovement) {
                $this->assertReplayMatches(
                    $existing,
                    $lot,
                    $mode,
                    $enteredQuantity,
                    $enteredUnit,
                    $reason,
                    $note,
                    $shortageAcknowledged,
                );

                return $existing;
            }

            $freshSnapshot = $this->snapshotForLockedLot($lot, lockReservations: true);

            if (! $this->snapshotsMatch($snapshot, $freshSnapshot)) {
                throw ValidationException::withMessages([
                    'adjustment_snapshot' => __('production_bench.inventory.validation.adjustment_stale'),
                ]);
            }

            $calculation = $this->calculator->calculate(
                $lot->unit_kind,
                $freshSnapshot['physical'],
                $mode,
                $enteredQuantity,
                $enteredUnit,
            );

            if (in_array($reason, ['spillage', 'damaged_discarded'], true)
                && bccomp($calculation['delta'], '0', 9) > 0) {
                throw ValidationException::withMessages([
                    'reason' => __('production_bench.inventory.validation.adjustment_reason_direction'),
                ]);
            }

            $hasReservationShortage = bccomp($freshSnapshot['reserved'], '0', 9) > 0
                && bccomp($calculation['physical_after'], $freshSnapshot['reserved'], 9) < 0;

            if ($hasReservationShortage && ! $shortageAcknowledged) {
                throw ValidationException::withMessages([
                    'shortage_acknowledged' => __('production_bench.inventory.validation.adjustment_shortage_ack_required'),
                ]);
            }

            return $lot->movements()->create([
                'workspace_id' => $lockedWorkspace->id,
                'type' => StockMovementType::StockCountAdjustment,
                'quantity_delta' => $calculation['delta'],
                'original_quantity' => $calculation['original_quantity'],
                'original_unit' => $calculation['entered_unit'],
                'occurred_at' => now(),
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'note' => $note,
                'adjustment_details' => [
                    'version' => 1,
                    'mode' => $mode,
                    'reason' => $reason,
                    'entered_quantity' => $calculation['entered_quantity'],
                    'entered_unit' => $calculation['entered_unit'],
                    'physical_before' => $calculation['physical_before'],
                    'physical_after' => $calculation['physical_after'],
                    'reserved_at_posting' => $freshSnapshot['reserved'],
                    'shortage_acknowledged' => $shortageAcknowledged,
                ],
            ]);
        }, attempts: 5);
    }

    /**
     * @return array{latest_movement_id: int|null, physical: string, reserved: string, status: string}
     */
    public function snapshot(User $actor, Workspace $workspace, int|string $lotIdentifier): array
    {
        $this->access->assertReadable($actor, $workspace);
        $lot = $this->resolveLot($workspace, $lotIdentifier);

        return $this->snapshotForLockedLot($lot);
    }

    private function validateRequest(string $reason, ?string $note, string $idempotencyKey): void
    {
        Validator::make([
            'reason' => $reason,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
        ], [
            'reason' => ['required', 'string', 'in:'.implode(',', self::Reasons)],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'uuid', 'max:120'],
        ], [
            'reason.in' => __('production_bench.inventory.validation.adjustment_invalid_reason'),
            'note.max' => __('production_bench.inventory.validation.adjustment_note_max'),
            'idempotency_key.uuid' => __('production_bench.inventory.validation.adjustment_idempotency_key'),
        ])->validate();

        if ($reason === 'other' && blank($note)) {
            throw ValidationException::withMessages([
                'note' => __('production_bench.inventory.validation.adjustment_other_note_required'),
            ]);
        }
    }

    private function resolveLot(Workspace $workspace, int|string $identifier, bool $lock = false): StockLot
    {
        $query = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($identifier): void {
                if (is_int($identifier) || ctype_digit((string) $identifier)) {
                    $query->whereKey((int) $identifier);
                } else {
                    $query->where('public_id', (string) $identifier);
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $lot = $query->first();

        if (! $lot instanceof StockLot
            || (($lot->ingredient_id === null) === ($lot->packaging_item_id === null))
            || $lot->recipe_id !== null) {
            throw (new ModelNotFoundException)->setModel(StockLot::class, [$identifier]);
        }

        return $lot;
    }

    /**
     * @return array{latest_movement_id: int|null, physical: string, reserved: string, status: string}
     */
    private function snapshotForLockedLot(StockLot $lot, bool $lockReservations = false): array
    {
        $movementQuery = $lot->movements()->orderBy('id');
        $reservationQuery = $lot->reservations()->where('status', StockReservationStatus::Active)->orderBy('id');

        if ($lockReservations) {
            $movementQuery->lockForUpdate();
            $reservationQuery->lockForUpdate();
        }

        $movements = $movementQuery->get(['id', 'quantity_delta']);
        $reservations = $reservationQuery->get(['id', 'quantity']);

        return [
            'latest_movement_id' => $movements->last()?->id,
            'physical' => $movements->reduce(
                fn (string $total, StockMovement $movement): string => bcadd($total, (string) $movement->quantity_delta, 9),
                '0.000000000',
            ),
            'reserved' => $reservations->reduce(
                fn (string $total, $reservation): string => bcadd($total, (string) $reservation->quantity, 9),
                '0.000000000',
            ),
            'status' => $lot->status->value,
        ];
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $actual */
    private function snapshotsMatch(array $expected, array $actual): bool
    {
        return ($expected['latest_movement_id'] ?? null) === $actual['latest_movement_id']
            && isset($expected['physical'], $expected['reserved'], $expected['status'])
            && bccomp((string) $expected['physical'], $actual['physical'], 9) === 0
            && bccomp((string) $expected['reserved'], $actual['reserved'], 9) === 0
            && (string) $expected['status'] === $actual['status'];
    }

    private function assertReplayMatches(
        StockMovement $movement,
        StockLot $lot,
        string $mode,
        mixed $enteredQuantity,
        string $enteredUnit,
        string $reason,
        ?string $note,
        bool $shortageAcknowledged,
    ): void {
        $details = $movement->adjustment_details;
        $calculation = is_array($details) && isset($details['physical_before'])
            ? $this->calculator->calculate(
                $lot->unit_kind,
                (string) $details['physical_before'],
                $mode,
                $enteredQuantity,
                $enteredUnit,
            )
            : null;
        $matches = (int) $movement->stock_lot_id === (int) $lot->id
            && $movement->type === StockMovementType::StockCountAdjustment
            && is_array($details)
            && ($details['mode'] ?? null) === $mode
            && ($details['reason'] ?? null) === $reason
            && ($details['entered_quantity'] ?? null) === ($calculation['entered_quantity'] ?? null)
            && ($details['entered_unit'] ?? null) === $enteredUnit
            && (bool) ($details['shortage_acknowledged'] ?? false) === $shortageAcknowledged
            && $movement->note === $note;

        if (! $matches) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('production_bench.inventory.validation.adjustment_idempotency_conflict'),
            ]);
        }
    }
}
