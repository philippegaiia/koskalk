<?php

namespace App\Services;

use App\GoodsReceiptStatus;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\PurchaseOrderLine;
use App\Models\StockLot;
use App\Models\StockReservation;
use App\Models\Workspace;
use App\PurchaseOrderStatus;
use App\Services\Production\ProductionDemandService;
use App\StockLotStatus;
use App\StockReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        $key = $this->subjectKey($subject);

        return $this->forWorkspaceSubjects($workspace, [$key])[$key];
    }

    /**
     * @param  list<string>  $subjectKeys  Keys in the form of "ingredient:{id}" or "packaging:{id}".
     * @return array<string, array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}>
     */
    /**
     * @param  list<string>  $subjectKeys  Keys in the form of "ingredient:{id}" or "packaging:{id}".
     * @param  Collection<int, StockLot>|null  $loadedLots  Optional stock rows already loaded with movement and reservation sums.
     * @return array<string, array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}>
     */
    public function forWorkspaceSubjects(Workspace $workspace, array $subjectKeys, ?Collection $loadedLots = null): array
    {
        if ($subjectKeys === []) {
            return [];
        }

        $ingredientIds = [];
        $packagingItemIds = [];

        foreach ($subjectKeys as $key) {
            if (str_starts_with($key, 'ingredient:')) {
                $ingredientIds[] = (int) substr($key, strlen('ingredient:'));
            } elseif (str_starts_with($key, 'packaging:')) {
                $packagingItemIds[] = (int) substr($key, strlen('packaging:'));
            }
        }

        $totals = array_fill_keys($subjectKeys, [
            'physical' => $this->zero(),
            'quarantined' => $this->zero(),
            'reserved' => $this->zero(),
            'available' => $this->zero(),
        ]);

        $lots = $loadedLots ?? StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->where(function (Builder $query) use ($ingredientIds, $packagingItemIds): void {
                if ($ingredientIds !== []) {
                    $query->orWhereIn('ingredient_id', $ingredientIds);
                }

                if ($packagingItemIds !== []) {
                    $query->orWhereIn('packaging_item_id', $packagingItemIds);
                }
            })
            ->withSum('movements', 'quantity_delta')
            ->get();

        $reservedByLot = $loadedLots === null
            ? StockReservation::query()
                ->whereIn('stock_lot_id', $lots->pluck('id'))
                ->where('status', StockReservationStatus::Active)
                ->groupBy('stock_lot_id')
                ->selectRaw('stock_lot_id, sum(quantity) as total')
                ->pluck('total', 'stock_lot_id')
            : collect();

        foreach ($lots as $lot) {
            $key = $lot->ingredient_id !== null
                ? 'ingredient:'.$lot->ingredient_id
                : 'packaging:'.$lot->packaging_item_id;

            if (! array_key_exists($key, $totals)) {
                continue;
            }

            $quantity = $this->decimal((string) ($lot->movements_sum_quantity_delta ?? '0'));
            $totals[$key]['physical'] = bcadd($totals[$key]['physical'], $quantity, 9);

            if ($lot->status === StockLotStatus::Quarantined) {
                $totals[$key]['quarantined'] = bcadd($totals[$key]['quarantined'], $quantity, 9);
            } else {
                $lotReserved = $loadedLots !== null
                    ? $this->decimal((string) ($lot->active_reserved_quantity ?? '0'))
                    : $this->decimal((string) ($reservedByLot->get($lot->id) ?? '0'));
                $totals[$key]['reserved'] = bcadd($totals[$key]['reserved'], $lotReserved, 9);
                $totals[$key]['available'] = bcadd($totals[$key]['available'], bcsub($quantity, $lotReserved, 9), 9);
            }
        }

        $incomingByKey = $this->incomingForSubjects($workspace, $ingredientIds, $packagingItemIds);
        $demandByKey = $this->productionDemand->forWorkspaceSubjects($workspace, $subjectKeys);

        $positions = [];

        foreach ($subjectKeys as $key) {
            $positions[$key] = $this->positions(
                $totals[$key]['physical'],
                $totals[$key]['quarantined'],
                $totals[$key]['reserved'],
                $totals[$key]['available'],
                $incomingByKey[$key] ?? $this->zero(),
                $demandByKey[$key] ?? $this->zero(),
            );
        }

        return $positions;
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

    /**
     * @param  list<int>  $ingredientIds
     * @param  list<int>  $packagingItemIds
     * @return array<string, string> Incoming quantities keyed by subject key.
     */
    private function incomingForSubjects(Workspace $workspace, array $ingredientIds, array $packagingItemIds): array
    {
        if ($ingredientIds === [] && $packagingItemIds === []) {
            return [];
        }

        $lines = PurchaseOrderLine::query()
            ->whereHas('purchaseOrder', fn (Builder $query): Builder => $query
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived]))
            ->where(function (Builder $query) use ($ingredientIds, $packagingItemIds): void {
                if ($ingredientIds !== []) {
                    $query->orWhereIn('ingredient_id', $ingredientIds);
                }

                if ($packagingItemIds !== []) {
                    $query->orWhereIn('packaging_item_id', $packagingItemIds);
                }
            })
            ->withSum([
                'receiptLines as posted_packs_received' => fn (Builder $query): Builder => $query
                    ->whereHas('goodsReceipt', fn (Builder $receiptQuery): Builder => $receiptQuery
                        ->where('status', GoodsReceiptStatus::Posted)),
            ], 'packs_received')
            ->get(['ingredient_id', 'packaging_item_id', 'ordered_packs', 'canonical_quantity_per_pack', 'posted_packs_received']);

        $incoming = [];

        foreach ($lines as $line) {
            $key = $line->ingredient_id !== null
                ? 'ingredient:'.$line->ingredient_id
                : 'packaging:'.$line->packaging_item_id;

            $remainingPacks = $line->ordered_packs - (int) ($line->posted_packs_received ?? 0);
            $quantity = bcmul($line->canonical_quantity_per_pack, (string) max(0, $remainingPacks), 9);
            $incoming[$key] = bcadd($incoming[$key] ?? $this->zero(), $quantity, 9);
        }

        return $incoming;
    }

    private function subjectKey(Ingredient|PackagingItem $subject): string
    {
        return $subject instanceof Ingredient
            ? 'ingredient:'.$subject->id
            : 'packaging:'.$subject->id;
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
