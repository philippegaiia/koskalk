<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrder
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        $this->access->assertWritable($actor, $order->workspace);

        return DB::transaction(function () use ($order): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($lockedOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Only an unreceived draft or placed order can be cancelled.',
                ]);
            }

            $lockedOrder->update(['status' => PurchaseOrderStatus::Cancelled]);

            return $lockedOrder->refresh();
        }, attempts: 5);
    }
}
