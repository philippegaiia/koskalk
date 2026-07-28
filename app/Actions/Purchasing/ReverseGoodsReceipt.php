<?php

namespace App\Actions\Purchasing;

use App\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\StockMovement;
use App\Models\User;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use App\StockMovementType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseGoodsReceipt
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, GoodsReceipt $receipt, ?string $reason = null): GoodsReceipt
    {
        $this->access->assertWritable($actor, $receipt->purchaseOrder->workspace);

        return DB::transaction(function () use ($actor, $receipt, $reason): GoodsReceipt {
            $lockedReceipt = GoodsReceipt::query()
                ->with(['lines.stockLot.movements', 'purchaseOrder.lines'])
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($lockedReceipt->status !== GoodsReceiptStatus::Posted) {
                throw ValidationException::withMessages(['receipt' => 'Only a posted receipt can be reversed.']);
            }

            foreach ($lockedReceipt->lines as $line) {
                $original = $line->stockLot->movements
                    ->first(fn (StockMovement $movement): bool => $movement->type === StockMovementType::PurchaseReceipt);

                if (! $original instanceof StockMovement) {
                    throw ValidationException::withMessages(['receipt' => 'The original stock movement is missing.']);
                }

                $line->stockLot->movements()->create([
                    'workspace_id' => $lockedReceipt->workspace_id,
                    'type' => StockMovementType::ReceiptReversal,
                    'quantity_delta' => bcmul($original->quantity_delta, '-1', 9),
                    'original_quantity' => $original->original_quantity,
                    'original_unit' => $original->original_unit,
                    'occurred_at' => now(),
                    'actor_user_id' => $actor->id,
                    'source_type' => $lockedReceipt->getMorphClass(),
                    'source_id' => $lockedReceipt->id,
                    'reversal_of_stock_movement_id' => $original->id,
                    'idempotency_key' => 'reverse-receipt:'.$lockedReceipt->id.':'.$line->id,
                    'note' => $reason,
                ]);
            }

            $lockedReceipt->update([
                'status' => GoodsReceiptStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor->id,
            ]);

            $order = $lockedReceipt->purchaseOrder()->lockForUpdate()->firstOrFail();
            $receivedAny = false;
            $outstanding = false;

            foreach ($order->lines as $line) {
                $receivedPacks = (int) $line->receiptLines()
                    ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted))
                    ->sum('packs_received');
                $receivedAny = $receivedAny || $receivedPacks > 0;
                $outstanding = $outstanding || $receivedPacks < $line->ordered_packs;
            }

            $order->update([
                'status' => ! $receivedAny
                    ? PurchaseOrderStatus::Ordered
                    : ($outstanding ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Received),
            ]);

            return $lockedReceipt->refresh();
        }, attempts: 5);
    }
}
