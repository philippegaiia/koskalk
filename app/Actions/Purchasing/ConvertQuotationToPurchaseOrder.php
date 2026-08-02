<?php

namespace App\Actions\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\ProcurementStage;
use App\PurchaseOrderStatus;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuotationToPurchaseOrder
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        $this->access->assertWritable($actor, $order->workspace);

        return DB::transaction(function () use ($order): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()
                ->with('lines')
                ->lockForUpdate()
                ->findOrFail($order->id);

            if (
                $lockedOrder->stage !== ProcurementStage::Quotation
                || $lockedOrder->status !== PurchaseOrderStatus::Draft
                || $lockedOrder->quotation_requested_at === null
            ) {
                throw ValidationException::withMessages([
                    'quotation' => 'An issued quotation request is required.',
                ]);
            }

            $lockedOrder->update([
                'stage' => ProcurementStage::PurchaseOrder,
                'price_confirmed_at' => $lockedOrder->lines->every(fn ($line): bool => $line->pack_price !== null)
                    ? now()
                    : null,
            ]);

            return $lockedOrder->refresh()->load('lines');
        }, attempts: 5);
    }
}
