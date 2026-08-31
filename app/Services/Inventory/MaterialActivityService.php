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

    /**
     * Splits a movement type into its inbound and outbound halves in SQL.
     *
     * A bare CASE rather than SIGN(), which SQLite only gained in 3.36 and the
     * deployment cannot be assumed to have.
     */
    private const string SignBucket = 'CASE WHEN quantity_delta < 0 THEN 1 ELSE 0 END';

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
        $lotIds = $this->lotIdQuery($workspace, $subject);
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
        $page = $this->movementQuery($workspace, $this->lotIdQuery($workspace, $subject), $from, $to)
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
        return StockLot::query()
            ->whereIn('id', $this->lotIdQuery($workspace, $subject))
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

    /** @param  Builder<StockLot>  $lotIds */
    private function physicalAt(Workspace $workspace, Builder $lotIds, string $operator, CarbonImmutable $at): string
    {
        $value = DB::table('stock_movements')
            ->where('workspace_id', $workspace->id)
            ->whereIn('stock_lot_id', $lotIds)
            ->where('occurred_at', $operator, $at)
            ->sum('quantity_delta');

        return $this->decimalFromSql($value);
    }

    /**
     * Sums a period's movements into their display groups inside the database.
     *
     * Grouped by type and by sign rather than by the display group, so the
     * statement does not restate groupFor()'s classification and cannot drift
     * away from it. The sign bucket matters because a correction type can carry
     * both directions: a stock count of -5 and +5 lands in `adjustments` twice
     * and must net to zero, while a production consumption reversal is a
     * positive movement that belongs to `other_inbound`, not to
     * `production_consumed`. Grouping by type alone would merge those.
     *
     * The result set is therefore bounded by twice the movement-type count no
     * matter how long the material's history is.
     *
     * @param  Builder<StockLot>  $lotIds
     * @return array{received: string, production_consumed: string, other_inbound: string, other_outbound: string, adjustments: string}
     */
    private function groupTotals(Workspace $workspace, Builder $lotIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [
            'received' => self::Zero,
            'production_consumed' => self::Zero,
            'other_inbound' => self::Zero,
            'other_outbound' => self::Zero,
            'adjustments' => self::Zero,
        ];

        DB::table('stock_movements')
            ->where('workspace_id', $workspace->id)
            ->whereIn('stock_lot_id', $lotIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('type, '.self::SignBucket.' AS is_negative, SUM(quantity_delta) AS total')
            // The bucket expression is repeated rather than grouped by the
            // alias: alias resolution in GROUP BY is not portable.
            ->groupByRaw('type, '.self::SignBucket)
            ->get()
            ->each(function (object $row) use (&$totals): void {
                $group = $this->groupForSign($row->type, (int) $row->is_negative === 1);
                $total = $this->decimalFromSql($row->total);

                // Each row is sign-homogeneous, so the outbound groups hold a
                // negative total and are reported as a positive magnitude.
                $totals[$group] = in_array($group, ['production_consumed', 'other_outbound'], true)
                    ? bcadd($totals[$group], bcmul($total, '-1', 9), 9)
                    : bcadd($totals[$group], $total, 9);
            });

        return $totals;
    }

    /**
     * @param  Builder<StockLot>  $lotIds
     * @return Builder<StockMovement>
     */
    private function movementQuery(Workspace $workspace, Builder $lotIds, CarbonImmutable $from, CarbonImmutable $to): Builder
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

    /**
     * The subject's lots as a subquery rather than as an array of IDs.
     *
     * Materialising the IDs meant reading every lot of a long-lived material
     * into PHP before a single movement could be aggregated, and each caller
     * then shipped the whole list back as bindings. Handing the query straight
     * to `whereIn()` keeps the lot resolution inside the database.
     *
     * @return Builder<StockLot>
     */
    private function lotIdQuery(Workspace $workspace, Ingredient|PackagingItem $subject): Builder
    {
        return StockLot::query()
            ->select('stock_lots.id')
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            );
    }

    private function groupFor(StockMovementType|string|null $type, string $delta): string
    {
        return $this->groupForSign($type, bccomp($delta, '0', 9) < 0);
    }

    /**
     * The single definition of the display grouping, shared by the paginated
     * rows (which know an exact delta) and by the SQL aggregate (which only
     * knows the row's sign).
     */
    private function groupForSign(StockMovementType|string|null $type, bool $isNegative): string
    {
        $type = $type instanceof StockMovementType ? $type : StockMovementType::tryFrom((string) $type);

        if ($type === StockMovementType::ProductionConsumption && $isNegative) {
            return 'production_consumed';
        }

        if (in_array($type, [StockMovementType::PurchaseReceipt, StockMovementType::ReceiptReversal], true)) {
            return 'received';
        }

        if (in_array($type, [StockMovementType::StockCountAdjustment, StockMovementType::ProductionCorrection], true)) {
            return 'adjustments';
        }

        return $isNegative ? 'other_outbound' : 'other_inbound';
    }

    /**
     * Brings a SQL aggregate back to the column's fixed scale.
     *
     * PostgreSQL returns `numeric` as an exact decimal string and is used
     * as-is. SQLite has no decimal type — the column is NUMERIC-affine, so
     * SUM() yields a float whose string cast is scientific notation ("3.0E-9")
     * that BCMath rejects with a ValueError, and which drops the fraction
     * entirely once the total grows ("90000000"). Formatting at the stored
     * scale before BCMath sees it keeps the value well-formed.
     */
    private function decimalFromSql(mixed $value): string
    {
        // bcadd also folds a negative zero, which sprintf would keep, back to
        // a plain zero.
        return is_string($value)
            ? bcadd($value, '0', 9)
            : bcadd(sprintf('%.9F', (float) $value), '0', 9);
    }
}
