<?php

namespace App\Actions\Purchasing;

use App\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use App\StockMovementType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReverseGoodsReceipt
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly RebuildCurrentMaterialPriceAfterReceiptReversal $rebuildCurrentPrice,
    ) {}

    public function handle(User $actor, GoodsReceipt $receipt, ?string $reason = null): GoodsReceipt
    {
        $reason = trim((string) $reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'A reversal reason of at most 1000 characters is required.',
            ]);
        }

        $workspace = $receipt->workspace;
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $receipt, $reason, $workspace): GoodsReceipt {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $lockedWorkspace);
            $lockedReceipt = GoodsReceipt::query()
                ->lockForUpdate()
                ->findOrFail($receipt->id);

            if ($lockedReceipt->workspace_id !== $lockedWorkspace->id) {
                throw ValidationException::withMessages(['receipt' => 'The receipt belongs to another workspace.']);
            }

            if ($lockedReceipt->status !== GoodsReceiptStatus::Posted) {
                throw ValidationException::withMessages(['receipt' => 'Only a posted receipt can be reversed.']);
            }

            foreach ($lockedReceipt->lines()->with('stockLot')->get() as $line) {
                $original = $line->stockLot->movements()
                    ->where('type', StockMovementType::PurchaseReceipt)
                    ->where('source_type', $line->getMorphClass())
                    ->where('source_id', $line->id)
                    ->lockForUpdate()
                    ->first();

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
                'reversal_reason' => $reason,
            ]);

            if ($lockedReceipt->purchase_order_id !== null) {
                $order = $lockedReceipt->purchaseOrder()->lockForUpdate()->firstOrFail();
                $receivedAny = false;
                $outstanding = false;

                foreach ($order->lines()->get() as $line) {
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
            }

            $this->rebuildCurrentPrice->handle($actor, $lockedReceipt);

            return $lockedReceipt->refresh();
        }, attempts: 5);
    }
}
