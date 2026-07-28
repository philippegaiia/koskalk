<?php

namespace App\Services;

use App\GoodsReceiptStatus;
use App\Models\Ingredient;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\StockLotStatus;
use Illuminate\Database\Eloquent\Builder;

class StockPositionService
{
    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forLot(StockLot $lot): array
    {
        $physical = $this->decimal((string) $lot->movements()->sum('quantity_delta'));
        $quarantined = $lot->status === StockLotStatus::Quarantined ? $physical : $this->zero();
        $available = $lot->status === StockLotStatus::Released ? $physical : $this->zero();

        return $this->positions($physical, $quarantined, $available);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forWorkspaceSubject(Workspace $workspace, Ingredient|UserPackagingItem $subject): array
    {
        $lots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('user_packaging_item_id', $subject->id),
            )
            ->withSum('movements', 'quantity_delta')
            ->get();

        $physical = $this->zero();
        $quarantined = $this->zero();
        $available = $this->zero();

        foreach ($lots as $lot) {
            $quantity = $this->decimal((string) ($lot->movements_sum_quantity_delta ?? '0'));
            $physical = bcadd($physical, $quantity, 9);

            if ($lot->status === StockLotStatus::Quarantined) {
                $quarantined = bcadd($quarantined, $quantity, 9);
            } else {
                $available = bcadd($available, $quantity, 9);
            }
        }

        $incoming = $this->incomingForSubject($workspace, $subject);

        return $this->positions($physical, $quarantined, $available, $incoming);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    private function positions(
        string $physical,
        string $quarantined,
        string $available,
        string $incoming = '0.000000000',
    ): array {
        return [
            'physical' => $physical,
            'quarantined' => $quarantined,
            'reserved' => $this->zero(),
            'available' => $available,
            'incoming' => $incoming,
            'forecast' => bcadd($available, $incoming, 9),
        ];
    }

    private function incomingForSubject(Workspace $workspace, Ingredient|UserPackagingItem $subject): string
    {
        $lines = PurchaseOrderLine::query()
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived]))
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('user_packaging_item_id', $subject->id),
            )
            ->withSum([
                'receiptLines as posted_packs_received' => fn (Builder $query): Builder => $query
                    ->whereHas('goodsReceipt', fn (Builder $receiptQuery): Builder => $receiptQuery
                        ->where('status', GoodsReceiptStatus::Posted)),
            ], 'packs_received')
            ->get();

        $incoming = $this->zero();

        foreach ($lines as $line) {
            $remainingPacks = $line->ordered_packs - (int) ($line->posted_packs_received ?? 0);
            $incoming = bcadd(
                $incoming,
                bcmul($line->canonical_quantity_per_pack, (string) max(0, $remainingPacks), 9),
                9,
            );
        }

        return $incoming;
    }

    private function decimal(string $quantity): string
    {
        return bcadd($quantity, '0', 9);
    }

    private function zero(): string
    {
        return '0.000000000';
    }
}
