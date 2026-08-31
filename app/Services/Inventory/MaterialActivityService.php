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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaterialActivityService
{
    private const string Zero = '0.000000000';

    /**
     * The embedded open-lot list on the material detail shows only the most
     * urgent lots; the full register is one click away via "View all lots".
     */
    private const int OpenLotLimit = 10;

    public function __construct(private readonly StockPositionService $positions) {}

    /**
     * The reconciliation summary for a period. The totals are summed over every
     * movement in the period, independent of how the row list is paginated, so
     * they stay exact no matter which page is displayed.
     *
     * @return array{
     *     opening_physical: string,
     *     closing_physical: string,
     *     received: string,
     *     production_consumed: string,
     *     other_inbound: string,
     *     other_outbound: string,
     *     adjustments: string,
     *     net_change: string,
     *     reconciliation_delta: string
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
        $totals = $this->groupTotals($workspace, $lotIds, $from, $to);

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
        ];
    }

    /**
     * One newest-first page of the period's movements, eager-loaded for display.
     *
     * Separate from forPeriod() so only the visible page hydrates models and
     * their source/lot relations; the reconciliation totals above never depend
     * on this page.
     *
     * @return LengthAwarePaginator<int, array{movement: StockMovement, group: string, quantity_delta: string}>
     */
    public function paginateMovements(
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $perPage = 25,
        string $pageName = 'activity',
    ): LengthAwarePaginator {
        $lotIds = $this->lotIds($workspace, $subject);

        $page = $this->movementQuery($workspace, $lotIds, $from, $to)
            ->paginate(max(1, $perPage), ['*'], $pageName);

        $page->getCollection()->loadMorph('source', [
            GoodsReceiptLine::class => ['goodsReceipt'],
            GoodsReceipt::class => [],
            ProductionRun::class => [],
        ]);

        return $page->through(fn (StockMovement $movement): array => [
            'movement' => $movement,
            'group' => $this->groupFor($movement->type, bcadd((string) $movement->quantity_delta, '0', 9)),
            'quantity_delta' => bcadd((string) $movement->quantity_delta, '0', 9),
        ]);
    }

    /**
     * @return array{physical: string, quarantined: string, reserved: string, available: string, incoming: string, forecast: string}
     */
    public function currentPosition(Workspace $workspace, Ingredient|PackagingItem $subject): array
    {
        return $this->positions->forWorkspaceSubject($workspace, $subject);
    }

    /**
     * FEFO order: the lots that expire soonest surface first, lots without an
     * expiry last. The CASE guard makes NULL placement explicit because SQLite
     * and PostgreSQL order NULLs differently on a bare ASC. Stocked date, lot
     * code, and id break ties so the order is stable across requests.
     *
     * @return Collection<int, StockLot>
     */
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
            // Open keeps physically empty lots that still carry an active
            // reservation, so an over-reserved lot is not hidden. Correlated
            // subqueries rather than the withSum aliases, because PostgreSQL and
            // SQLite differ on alias visibility in WHERE.
            ->whereRaw('((SELECT COALESCE(SUM(movements.quantity_delta), 0) FROM stock_movements AS movements WHERE movements.stock_lot_id = stock_lots.id) <> 0 OR (SELECT COALESCE(SUM(reservations.quantity), 0) FROM stock_reservations AS reservations WHERE reservations.stock_lot_id = stock_lots.id AND reservations.status = \'active\') <> 0)')
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('stocked_at')
            ->orderBy('internal_lot_code')
            ->orderBy('id')
            ->limit(self::OpenLotLimit)
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

    /**
     * Sums every period movement into its display group. Only the type and the
     * delta are selected, so the reconciliation stays exact without hydrating a
     * model per movement.
     *
     * @param  list<int>  $lotIds
     * @return array{received: string, production_consumed: string, other_inbound: string, other_outbound: string, adjustments: string}
     */
    private function groupTotals(Workspace $workspace, array $lotIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [
            'received' => self::Zero,
            'production_consumed' => self::Zero,
            'other_inbound' => self::Zero,
            'other_outbound' => self::Zero,
            'adjustments' => self::Zero,
        ];

        if ($lotIds === []) {
            return $totals;
        }

        DB::table('stock_movements')
            ->where('workspace_id', $workspace->id)
            ->whereIn('stock_lot_id', $lotIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['type', 'quantity_delta'])
            ->each(function (object $row) use (&$totals): void {
                $delta = bcadd((string) $row->quantity_delta, '0', 9);
                $group = $this->groupFor($row->type, $delta);

                $totals[$group] = in_array($group, ['production_consumed', 'other_outbound'], true)
                    ? bcadd($totals[$group], bccomp($delta, '0', 9) < 0 ? bcmul($delta, '-1', 9) : '0', 9)
                    : bcadd($totals[$group], $delta, 9);
            });

        return $totals;
    }

    /** @param  list<int>  $lotIds  @return Builder<StockMovement> */
    private function movementQuery(Workspace $workspace, array $lotIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return StockMovement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('stock_lot_id', $lotIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->with([
                'stockLot.ingredient.translations',
                'stockLot.packagingItem',
                'source',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
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
