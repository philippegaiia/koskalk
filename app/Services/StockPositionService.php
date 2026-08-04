<?php

namespace App\Services;

use App\GoodsReceiptStatus;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\Production\ProductionDemandService;
use App\StockLotStatus;
use App\StockReservationStatus;
use Illuminate\Database\Eloquent\Builder;

class StockPositionService
{
    public function __construct(private readonly ProductionDemandService $productionDemand) {}

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forLot(StockLot $lot): array
    {
        $physical = $this->decimal((string) $lot->movements()->sum('quantity_delta'));
        $reserved = $this->decimal((string) $lot->reservations()->where('status', StockReservationStatus::Active)->sum('quantity'));

        return $this->forLotWithPhysicalQuantity($lot, $physical, $reserved);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forLotWithLoadedMovementSum(StockLot $lot): array
    {
        $physical = $this->decimal((string) ($lot->movements_sum_quantity_delta ?? '0'));
        $reserved = $this->decimal((string) ($lot->active_reserved_quantity ?? '0'));

        return $this->forLotWithPhysicalQuantity($lot, $physical, $reserved);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    private function forLotWithPhysicalQuantity(StockLot $lot, string $physical, string $reserved): array
    {
        $quarantined = $lot->status === StockLotStatus::Quarantined ? $physical : $this->zero();
        $reserved = $lot->status === StockLotStatus::Released ? $reserved : $this->zero();
        $available = $lot->status === StockLotStatus::Released
            ? bcsub($physical, $reserved, 9)
            : $this->zero();

        return $this->positions($physical, $quarantined, $reserved, $available);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function forWorkspaceSubject(Workspace $workspace, Ingredient|PackagingItem $subject): array
    {
        $lots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            )
            ->withSum('movements', 'quantity_delta')
            ->get();

        $physical = $this->zero();
        $quarantined = $this->zero();
        $available = $this->zero();
        $reserved = $this->zero();

        foreach ($lots as $lot) {
            $quantity = $this->decimal((string) ($lot->movements_sum_quantity_delta ?? '0'));
            $physical = bcadd($physical, $quantity, 9);

            if ($lot->status === StockLotStatus::Quarantined) {
                $quarantined = bcadd($quarantined, $quantity, 9);
            } else {
                $lotReserved = $this->decimal((string) $lot->reservations()->where('status', StockReservationStatus::Active)->sum('quantity'));
                $reserved = bcadd($reserved, $lotReserved, 9);
                $available = bcadd($available, bcsub($quantity, $lotReserved, 9), 9);
            }
        }

        $incoming = $this->incomingForSubject($workspace, $subject);
        $demand = $this->productionDemand->forWorkspaceSubject($workspace, $subject);

        return $this->positions($physical, $quarantined, $reserved, $available, $incoming, $demand);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    private function positions(
        string $physical,
        string $quarantined,
        string $reserved,
        string $available,
        string $incoming = '0.000000000',
        string $productionDemand = '0.000000000',
    ): array {
        return [
            'physical' => $physical,
            'quarantined' => $quarantined,
            'reserved' => $reserved,
            'available' => $available,
            'incoming' => $incoming,
            'forecast' => bcsub(bcadd($available, $incoming, 9), $productionDemand, 9),
        ];
    }

    private function incomingForSubject(Workspace $workspace, Ingredient|PackagingItem $subject): string
    {
        $lines = PurchaseOrderLine::query()
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived]))
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
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
