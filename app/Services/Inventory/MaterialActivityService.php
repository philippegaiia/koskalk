<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\ProductionRun;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Workspace;
use App\Services\StockPositionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaterialActivityService
{
    private const string Zero = '0.000000000';

    public function __construct(private readonly StockPositionService $positions) {}

    /**
     * @return array{
     *     opening_physical: string,
     *     closing_physical: string,
     *     received: string,
     *     production_consumed: string,
     *     other_inbound: string,
     *     other_outbound: string,
     *     adjustments: string,
     *     net_change: string,
     *     reconciliation_delta: string,
     *     movements: Collection<int, array{movement: StockMovement, group: string, quantity_delta: string}>
     * }
     */
    public function forPeriod(
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $lotIds = $this->lotIds($workspace, $subject);
        $openingPhysical = $this->physicalAt($workspace, $lotIds, '<', $from);
        $closingPhysical = $this->physicalAt($workspace, $lotIds, '<=', $to);
        $movements = $this->movements($workspace, $lotIds, $from, $to);

        $totals = [
            'received' => self::Zero,
            'production_consumed' => self::Zero,
            'other_inbound' => self::Zero,
            'other_outbound' => self::Zero,
            'adjustments' => self::Zero,
        ];
        $rows = collect();

        foreach ($movements as $movement) {
            $delta = bcadd((string) $movement->quantity_delta, '0', 9);
            $group = $this->groupFor($movement->type, $delta);

            if ($group === 'production_consumed') {
                $totals[$group] = bcadd($totals[$group], bccomp($delta, '0', 9) < 0 ? bcmul($delta, '-1', 9) : '0', 9);
            } elseif ($group === 'other_outbound') {
                $totals[$group] = bcadd($totals[$group], bccomp($delta, '0', 9) < 0 ? bcmul($delta, '-1', 9) : '0', 9);
            } else {
                $totals[$group] = bcadd($totals[$group], $delta, 9);
            }

            $rows->push([
                'movement' => $movement,
                'group' => $group,
                'quantity_delta' => $delta,
            ]);
        }

        $netChange = bcsub(
            bcadd(bcadd($totals['received'], $totals['other_inbound'], 9), $totals['adjustments'], 9),
            bcadd($totals['production_consumed'], $totals['other_outbound'], 9),
            9,
        );
        $expectedClosing = bcadd($openingPhysical, $netChange, 9);

        return [
            'opening_physical' => $openingPhysical,
            'closing_physical' => $closingPhysical,
            ...$totals,
            'net_change' => $netChange,
            'reconciliation_delta' => bcsub($closingPhysical, $expectedClosing, 9),
            'movements' => $rows,
        ];
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function currentPosition(Workspace $workspace, Ingredient|PackagingItem $subject): array
    {
        return $this->positions->forWorkspaceSubject($workspace, $subject);
    }

    /** @return Collection<int, StockLot> */
    public function openLots(Workspace $workspace, Ingredient|PackagingItem $subject): Collection
    {
        $lotIds = $this->lotIds($workspace, $subject);

        return StockLot::query()
            ->whereIn('id', $lotIds)
            ->with([
                'ingredient.translations',
                'packagingItem',
                'supplierListing.supplier',
                'goodsReceiptLine.goodsReceipt.supplier',
            ])
            ->withSum('movements', 'quantity_delta')
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', 'active'),
            ], 'quantity')
            ->whereRaw('(SELECT COALESCE(SUM(movements.quantity_delta), 0) FROM stock_movements AS movements WHERE movements.stock_lot_id = stock_lots.id) <> 0')
            ->orderByDesc('stocked_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @param  list<int>  $lotIds */
    private function physicalAt(Workspace $workspace, array $lotIds, string $operator, CarbonImmutable $at): string
    {
        if ($lotIds === []) {
            return self::Zero;
        }

        $value = DB::table('stock_movements')
            ->where('workspace_id', $workspace->id)
            ->whereIn('stock_lot_id', $lotIds)
            ->where('occurred_at', $operator, $at)
            ->sum('quantity_delta');

        return bcadd((string) $value, '0', 9);
    }

    /** @param  list<int>  $lotIds  @return Collection<int, StockMovement> */
    private function movements(Workspace $workspace, array $lotIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($lotIds === []) {
            return collect();
        }

        $movements = StockMovement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('stock_lot_id', $lotIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->with([
                'stockLot.ingredient.translations',
                'stockLot.packagingItem',
                'source',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $movements->loadMorph('source', [
            GoodsReceiptLine::class => ['goodsReceipt'],
            GoodsReceipt::class => [],
            ProductionRun::class => [],
        ]);

        return $movements;
    }

    /** @param  list<int>  $lotIds */
    private function lotIds(Workspace $workspace, Ingredient|PackagingItem $subject): array
    {
        return StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            )
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function groupFor(StockMovementType|string|null $type, string $delta): string
    {
        $type = $type instanceof StockMovementType ? $type : StockMovementType::tryFrom((string) $type);

        if ($type === StockMovementType::ProductionConsumption && bccomp($delta, '0', 9) < 0) {
            return 'production_consumed';
        }

        if (in_array($type, [StockMovementType::PurchaseReceipt, StockMovementType::ReceiptReversal], true)) {
            return 'received';
        }

        if (in_array($type, [StockMovementType::StockCountAdjustment, StockMovementType::ProductionCorrection], true)) {
            return 'adjustments';
        }

        return bccomp($delta, '0', 9) >= 0 ? 'other_inbound' : 'other_outbound';
    }
}
