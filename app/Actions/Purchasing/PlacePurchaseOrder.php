<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlacePurchaseOrder
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        $this->access->assertWritable($actor, $order->workspace);

        return DB::transaction(function () use ($order): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status !== PurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages(['order' => 'Only a draft order can be placed.']);
            }

            $lockedOrder->update([
                'status' => PurchaseOrderStatus::Ordered,
                'ordered_at' => now()->toDateString(),
            ]);

            return $lockedOrder->refresh();
        }, attempts: 5);
    }
}
