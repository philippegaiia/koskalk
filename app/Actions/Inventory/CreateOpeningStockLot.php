<?php

namespace App\Actions\Inventory;

use App\Models\Ingredient;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\InternalLotCodeGenerator;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\StockLotOrigin;
use App\StockLotStatus;
use App\StockMovementType;
use App\StockUnitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateOpeningStockLot
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly MassConverter $massConverter,
        private readonly InternalLotCodeGenerator $lotCodeGenerator,
    ) {}

    public function handle(
        User $actor,
        Workspace $workspace,
        Ingredient|UserPackagingItem $subject,
        string $quantity,
        string $unit,
        StockLotStatus $status,
        string $idempotencyKey,
        ?string $supplierBatchNumber = null,
        ?string $stockedAt = null,
        ?string $expiresAt = null,
        bool $provenanceComplete = false,
        ?string $notes = null,
    ): StockLot {
        $this->access->assertWritable($actor, $workspace);

        Validator::make([
            'quantity' => $quantity,
            'idempotency_key' => $idempotencyKey,
            'stocked_at' => $stockedAt,
            'expires_at' => $expiresAt,
        ], [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'stocked_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ])->validate();

        $isMass = $subject instanceof Ingredient;
        $canonicalQuantity = $isMass
            ? $this->massConverter->toGrams($quantity, $unit)
            : $this->validateCount($quantity, $unit);

        return DB::transaction(function () use (
            $actor,
            $workspace,
            $subject,
            $quantity,
            $unit,
            $status,
            $idempotencyKey,
            $supplierBatchNumber,
            $stockedAt,
            $expiresAt,
            $provenanceComplete,
            $notes,
            $isMass,
            $canonicalQuantity,
        ): StockLot {
            $existing = StockMovement::query()
                ->where('workspace_id', $workspace->id)
                ->where('idempotency_key', $idempotencyKey)
                ->with('stockLot')
                ->first();

            if ($existing instanceof StockMovement) {
                return $existing->stockLot;
            }

            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);

            $lot = StockLot::query()->create([
                'workspace_id' => $workspace->id,
                'ingredient_id' => $isMass ? $subject->id : null,
                'user_packaging_item_id' => $isMass ? null : $subject->id,
                'internal_lot_code' => $this->lotCodeGenerator->next($workspace),
                'supplier_batch_number' => $supplierBatchNumber,
                'origin' => StockLotOrigin::OpeningBalance,
                'unit_kind' => $isMass ? StockUnitKind::Mass : StockUnitKind::Count,
                'status' => $status,
                'stocked_at' => $stockedAt ?? now()->toDateString(),
                'expires_at' => $expiresAt,
                'available_from' => $status === StockLotStatus::Released ? ($stockedAt ?? now()->toDateString()) : null,
                'released_at' => $status === StockLotStatus::Released ? now() : null,
                'released_by_user_id' => $status === StockLotStatus::Released ? $actor->id : null,
                'provenance_complete' => $provenanceComplete,
                'notes' => $notes,
            ]);

            $lot->movements()->create([
                'workspace_id' => $workspace->id,
                'type' => StockMovementType::OpeningBalance,
                'quantity_delta' => $canonicalQuantity,
                'original_quantity' => $quantity,
                'original_unit' => $unit,
                'occurred_at' => now(),
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            return $lot->load('movements');
        }, attempts: 5);
    }

    private function validateCount(string $quantity, string $unit): string
    {
        if ($unit !== 'count' || preg_match('/^[1-9]\d*$/', $quantity) !== 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Packaging stock must be entered as a positive whole count.',
            ]);
        }

        return bcadd($quantity, '0', 9);
    }
}
