<?php

namespace App\Services\Inventory;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\ProductionRunStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockLotStatus;
use App\Enums\StockReservationStatus;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceMaterialInventoryQuery
{
    private const string Zero = '0.000000000';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(
        Workspace $workspace,
        array $filters = [],
        int $perPage = 25,
        string $pageName = 'materials',
    ): LengthAwarePaginator {
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $query = $this->query($workspace, $filters);

        $page = $query->paginate($perPage, ['*'], $pageName);
        $subjects = $this->loadSubjects($page->getCollection(), $workspace);

        return $page->through(
            fn (object $row): array => $this->hydrateRow($row, $workspace, $subjects) ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{materials: int, shortages: int, incoming: int, quarantined: int, unplanned: int, below_buffer: int}
     */
    public function summary(Workspace $workspace, array $filters = []): array
    {
        $summary = DB::query()
            ->fromSub($this->query($workspace, $filters), 'material_rows')
            ->selectRaw('COUNT(*) AS materials')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_shortage = 1 THEN 1 ELSE 0 END), 0) AS shortages')
            ->selectRaw('COALESCE(SUM(CASE WHEN has_incoming = 1 THEN 1 ELSE 0 END), 0) AS incoming')
            ->selectRaw('COALESCE(SUM(CASE WHEN has_quarantined = 1 THEN 1 ELSE 0 END), 0) AS quarantined')
            ->selectRaw('COALESCE(SUM(CASE WHEN has_demand = 0 THEN 1 ELSE 0 END), 0) AS unplanned')
            ->selectRaw('COALESCE(SUM(CASE WHEN is_below_buffer = 1 THEN 1 ELSE 0 END), 0) AS below_buffer')
            ->first();

        return [
            'materials' => (int) ($summary->materials ?? 0),
            'shortages' => (int) ($summary->shortages ?? 0),
            'incoming' => (int) ($summary->incoming ?? 0),
            'quarantined' => (int) ($summary->quarantined ?? 0),
            'unplanned' => (int) ($summary->unplanned ?? 0),
            'below_buffer' => (int) ($summary->below_buffer ?? 0),
        ];
    }

    public function tracks(Workspace $workspace, Ingredient|PackagingItem $subject): bool
    {
        $type = $subject instanceof Ingredient ? 'ingredient' : 'packaging';

        return $this->query($workspace, ['type' => $type])
            ->where('tracked_materials.subject_id', $subject->id)
            ->exists();
    }

    /**
     * Bounded option source for material pickers, keyed by a compound
     * `type:public_id` identifier so a caller can tell the two subject tables
     * apart without a second lookup.
     *
     * The result count is capped rather than paginated: this feeds a type-ahead
     * combobox, so a caller must never be able to pull the whole catalogue.
     *
     * @return array<string, string>
     */
    public function materialOptions(Workspace $workspace, string $search = '', int $limit = 30): array
    {
        $rows = $this->query($workspace, [
            'search' => $search,
            'sort' => 'name',
            'direction' => 'asc',
        ])->limit(max(1, min($limit, 50)))->get();

        $subjects = $this->loadSubjects($rows, $workspace);

        return $rows->mapWithKeys(function (object $row) use ($subjects): array {
            $subject = $subjects[$row->subject_type.':'.$row->subject_id] ?? null;

            // A tracked row whose subject was deleted or belongs to another
            // workspace has nothing to offer a picker.
            if (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem) {
                return [];
            }

            return [
                $row->subject_type.':'.$subject->public_id => $subject instanceof Ingredient
                    ? (string) $subject->localizedDisplayName()
                    : (string) $subject->name,
            ];
        })->all();
    }

    /**
     * Resolves a compound `type:public_id` picker selection back to a subject
     * this workspace may actually filter on.
     *
     * Ingredients are global catalogue rows, so membership is established by
     * asking whether the workspace tracks them at all; packaging items are
     * workspace-owned and are matched directly.
     */
    public function resolveMaterialOption(
        Workspace $workspace,
        string $type,
        string $publicId,
    ): Ingredient|PackagingItem|null {
        // `public_id` is a uuid column: on PostgreSQL comparing it to a
        // non-uuid string is an "invalid input syntax for type uuid" error, not
        // an empty result, so the shape is checked before the query.
        if (! Str::isUuid($publicId)) {
            return null;
        }

        if ($type === 'packaging') {
            return PackagingItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $publicId)
                ->first();
        }

        $ingredient = Ingredient::query()->where('public_id', $publicId)->first();

        return $ingredient instanceof Ingredient && $this->tracks($workspace, $ingredient)
            ? $ingredient
            : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(Workspace $workspace, array $filters): Builder
    {
        $tracked = $this->trackedSubjects($workspace);
        $demand = $this->demandTotals($workspace);
        $listings = $this->listingTotals($workspace);
        $lots = $this->lotTotals($workspace);
        $reserved = $this->reservationTotals($workspace);
        $incoming = $this->incomingTotals($workspace);
        $settings = $this->settingTotals($workspace);

        $available = '(COALESCE(material_lot_totals.physical, 0) - COALESCE(material_lot_totals.quarantined, 0) - COALESCE(material_reservation_totals.reserved, 0))';
        $forecast = "({$available} + COALESCE(material_incoming_totals.incoming, 0) - COALESCE(material_demand_totals.required, 0))";

        $query = DB::query()
            ->fromSub($tracked, 'tracked_materials')
            ->leftJoin('ingredients', function ($join): void {
                $join
                    ->on('tracked_materials.subject_id', '=', 'ingredients.id')
                    ->where('tracked_materials.subject_type', '=', 'ingredient');
            })
            ->leftJoin('packaging_items', function ($join) use ($workspace): void {
                $join
                    ->on('tracked_materials.subject_id', '=', 'packaging_items.id')
                    ->where('tracked_materials.subject_type', '=', 'packaging')
                    ->where('packaging_items.workspace_id', '=', $workspace->id);
            })
            ->leftJoin('workspace_ingredient_codes', function ($join) use ($workspace): void {
                $join
                    ->on('workspace_ingredient_codes.ingredient_id', '=', 'ingredients.id')
                    ->where('workspace_ingredient_codes.workspace_id', '=', $workspace->id);
            })
            ->leftJoinSub($demand, 'material_demand_totals', function ($join): void {
                $join
                    ->on('material_demand_totals.subject_id', '=', 'tracked_materials.subject_id')
                    ->on('material_demand_totals.subject_type', '=', 'tracked_materials.subject_type');
            })
            ->leftJoinSub($listings, 'material_listing_totals', function ($join): void {
                $join
                    ->on('material_listing_totals.subject_id', '=', 'tracked_materials.subject_id')
                    ->on('material_listing_totals.subject_type', '=', 'tracked_materials.subject_type');
            })
            ->leftJoinSub($lots, 'material_lot_totals', function ($join): void {
                $join
                    ->on('material_lot_totals.subject_id', '=', 'tracked_materials.subject_id')
                    ->on('material_lot_totals.subject_type', '=', 'tracked_materials.subject_type');
            })
            ->leftJoinSub($reserved, 'material_reservation_totals', function ($join): void {
                $join
                    ->on('material_reservation_totals.subject_id', '=', 'tracked_materials.subject_id')
                    ->on('material_reservation_totals.subject_type', '=', 'tracked_materials.subject_type');
            })
            ->leftJoinSub($incoming, 'material_incoming_totals', function ($join): void {
                $join
                    ->on('material_incoming_totals.subject_id', '=', 'tracked_materials.subject_id')
                    ->on('material_incoming_totals.subject_type', '=', 'tracked_materials.subject_type');
            })
            ->leftJoinSub($settings, 'material_settings', function ($join): void {
                $join
                    ->on('material_settings.subject_id', '=', 'tracked_materials.subject_id')
                    ->on('material_settings.subject_type', '=', 'tracked_materials.subject_type');
            })
            ->select([
                'tracked_materials.subject_type',
                'tracked_materials.subject_id',
                'ingredients.public_id AS ingredient_public_id',
                'packaging_items.public_id AS packaging_public_id',
                'ingredients.display_name AS ingredient_name',
                'packaging_items.name AS packaging_name',
                'ingredients.category AS ingredient_category',
                'ingredients.subcategory AS ingredient_subcategory',
                'packaging_items.category AS packaging_category',
                'workspace_ingredient_codes.material_code AS ingredient_material_code',
                'packaging_items.material_code AS packaging_material_code',
            ])
            ->selectRaw('COALESCE(material_lot_totals.physical, 0) AS physical')
            ->selectRaw('COALESCE(material_lot_totals.quarantined, 0) AS quarantined')
            ->selectRaw('COALESCE(material_reservation_totals.reserved, 0) AS reserved')
            ->selectRaw("{$available} AS available")
            ->selectRaw('COALESCE(material_incoming_totals.incoming, 0) AS incoming')
            ->selectRaw('COALESCE(material_demand_totals.required, 0) AS required')
            ->selectRaw("{$forecast} AS forecast")
            ->selectRaw('material_settings.buffer_quantity AS buffer_quantity')
            ->selectRaw('CASE WHEN material_demand_totals.subject_id IS NOT NULL THEN 1 ELSE 0 END AS has_demand')
            ->selectRaw('CASE WHEN material_listing_totals.subject_id IS NOT NULL THEN 1 ELSE 0 END AS has_listing')
            ->selectRaw('CASE WHEN COALESCE(material_incoming_totals.incoming, 0) > 0 THEN 1 ELSE 0 END AS has_incoming')
            ->selectRaw('CASE WHEN COALESCE(material_lot_totals.quarantined, 0) > 0 THEN 1 ELSE 0 END AS has_quarantined')
            ->selectRaw("CASE WHEN {$forecast} < 0 THEN 1 ELSE 0 END AS is_shortage")
            ->selectRaw("CASE WHEN material_settings.buffer_quantity IS NOT NULL AND {$available} < material_settings.buffer_quantity THEN 1 ELSE 0 END AS is_below_buffer")
            ->selectRaw('LOWER(COALESCE(ingredients.display_name, packaging_items.name, \'\')) AS sort_name');

        $this->applyFilters($query, $workspace, $filters, $available, $forecast);
        $this->applyOrdering($query, $filters);

        return $query;
    }

    private function trackedSubjects(Workspace $workspace): Builder
    {
        $demandedIngredients = DB::table('production_requirements AS requirements')
            ->join('production_runs AS runs', 'runs.id', '=', 'requirements.production_run_id')
            ->where('runs.workspace_id', $workspace->id)
            ->whereIn('runs.status', [ProductionRunStatus::Scheduled->value, ProductionRunStatus::Reserved->value])
            ->whereNotNull('requirements.ingredient_id')
            ->selectRaw("'ingredient' AS subject_type, requirements.ingredient_id AS subject_id");
        $demandedPackaging = DB::table('production_requirements AS requirements')
            ->join('production_runs AS runs', 'runs.id', '=', 'requirements.production_run_id')
            ->where('runs.workspace_id', $workspace->id)
            ->whereIn('runs.status', [ProductionRunStatus::Scheduled->value, ProductionRunStatus::Reserved->value])
            ->whereNotNull('requirements.packaging_item_id')
            ->selectRaw("'packaging' AS subject_type, requirements.packaging_item_id AS subject_id");
        $listedIngredients = DB::table('supplier_listings')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('ingredient_id')
            ->selectRaw("'ingredient' AS subject_type, ingredient_id AS subject_id");
        $listedPackaging = DB::table('supplier_listings')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('packaging_item_id')
            ->selectRaw("'packaging' AS subject_type, packaging_item_id AS subject_id");
        $lotIngredients = DB::table('stock_lots')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('ingredient_id')
            ->selectRaw("'ingredient' AS subject_type, ingredient_id AS subject_id");
        $lotPackaging = DB::table('stock_lots')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('packaging_item_id')
            ->selectRaw("'packaging' AS subject_type, packaging_item_id AS subject_id");
        $settingIngredients = DB::table('workspace_material_settings')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('ingredient_id')
            ->selectRaw("'ingredient' AS subject_type, ingredient_id AS subject_id");
        $settingPackaging = DB::table('workspace_material_settings')
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('packaging_item_id')
            ->selectRaw("'packaging' AS subject_type, packaging_item_id AS subject_id");

        $union = $demandedIngredients
            ->unionAll($demandedPackaging)
            ->unionAll($listedIngredients)
            ->unionAll($listedPackaging)
            ->unionAll($lotIngredients)
            ->unionAll($lotPackaging)
            ->unionAll($settingIngredients)
            ->unionAll($settingPackaging);

        return DB::query()
            ->fromSub($union, 'tracked_sources')
            ->select(['subject_type', 'subject_id'])
            ->distinct();
    }

    private function demandTotals(Workspace $workspace): Builder
    {
        $required = <<<'SQL'
            CASE
                WHEN requirements.ingredient_id IS NOT NULL THEN COALESCE(requirements.required_mass_grams, 0)
                ELSE COALESCE(requirements.required_units, 0)
            END
        SQL;
        $reserved = <<<'SQL'
            COALESCE((
                SELECT SUM(reservations.quantity)
                FROM stock_reservations AS reservations
                WHERE reservations.production_requirement_id = requirements.id
                  AND reservations.status = 'active'
            ), 0)
        SQL;
        $remaining = "({$required} - {$reserved})";

        return DB::table('production_requirements AS requirements')
            ->join('production_runs AS runs', 'runs.id', '=', 'requirements.production_run_id')
            ->where('runs.workspace_id', $workspace->id)
            ->whereIn('runs.status', [ProductionRunStatus::Scheduled->value, ProductionRunStatus::Reserved->value])
            ->selectRaw("CASE WHEN requirements.ingredient_id IS NOT NULL THEN 'ingredient' ELSE 'packaging' END AS subject_type")
            ->selectRaw('CASE WHEN requirements.ingredient_id IS NOT NULL THEN requirements.ingredient_id ELSE requirements.packaging_item_id END AS subject_id')
            ->selectRaw("SUM(CASE WHEN {$remaining} > 0 THEN {$remaining} ELSE 0 END) AS required")
            ->groupBy('requirements.ingredient_id', 'requirements.packaging_item_id');
    }

    private function listingTotals(Workspace $workspace): Builder
    {
        return DB::table('supplier_listings')
            ->where('workspace_id', $workspace->id)
            ->selectRaw("CASE WHEN ingredient_id IS NOT NULL THEN 'ingredient' ELSE 'packaging' END AS subject_type")
            ->selectRaw('CASE WHEN ingredient_id IS NOT NULL THEN ingredient_id ELSE packaging_item_id END AS subject_id')
            ->selectRaw('COUNT(*) AS listings')
            ->groupBy('ingredient_id', 'packaging_item_id');
    }

    private function lotTotals(Workspace $workspace): Builder
    {
        return DB::table('stock_lots AS lots')
            ->leftJoin('stock_movements AS movements', 'movements.stock_lot_id', '=', 'lots.id')
            ->where('lots.workspace_id', $workspace->id)
            ->selectRaw("CASE WHEN lots.ingredient_id IS NOT NULL THEN 'ingredient' ELSE 'packaging' END AS subject_type")
            ->selectRaw('CASE WHEN lots.ingredient_id IS NOT NULL THEN lots.ingredient_id ELSE lots.packaging_item_id END AS subject_id')
            ->selectRaw('COALESCE(SUM(movements.quantity_delta), 0) AS physical')
            ->selectRaw("COALESCE(SUM(CASE WHEN lots.status = '".StockLotStatus::Quarantined->value."' THEN movements.quantity_delta ELSE 0 END), 0) AS quarantined")
            ->groupBy('lots.ingredient_id', 'lots.packaging_item_id');
    }

    private function reservationTotals(Workspace $workspace): Builder
    {
        return DB::table('stock_reservations AS reservations')
            ->join('stock_lots AS lots', 'lots.id', '=', 'reservations.stock_lot_id')
            ->where('reservations.workspace_id', $workspace->id)
            ->where('lots.workspace_id', $workspace->id)
            ->where('reservations.status', StockReservationStatus::Active->value)
            ->where('lots.status', StockLotStatus::Released->value)
            ->selectRaw("CASE WHEN lots.ingredient_id IS NOT NULL THEN 'ingredient' ELSE 'packaging' END AS subject_type")
            ->selectRaw('CASE WHEN lots.ingredient_id IS NOT NULL THEN lots.ingredient_id ELSE lots.packaging_item_id END AS subject_id')
            ->selectRaw('COALESCE(SUM(reservations.quantity), 0) AS reserved')
            ->groupBy('lots.ingredient_id', 'lots.packaging_item_id');
    }

    private function incomingTotals(Workspace $workspace): Builder
    {
        $postedPacks = <<<'SQL'
            COALESCE((
                SELECT SUM(receipt_lines.packs_received)
                FROM goods_receipt_lines AS receipt_lines
                INNER JOIN goods_receipts AS receipts ON receipts.id = receipt_lines.goods_receipt_id
                WHERE receipt_lines.purchase_order_line_id = purchase_lines.id
                  AND receipts.status = 'posted'
            ), 0)
        SQL;
        $remainingPacks = "(CASE WHEN (purchase_lines.ordered_packs - {$postedPacks}) > 0 THEN (purchase_lines.ordered_packs - {$postedPacks}) ELSE 0 END)";
        $quantity = "(purchase_lines.canonical_quantity_per_pack * {$remainingPacks})";

        return DB::table('purchase_order_lines AS purchase_lines')
            ->join('purchase_orders AS purchase_orders', 'purchase_orders.id', '=', 'purchase_lines.purchase_order_id')
            ->where('purchase_orders.workspace_id', $workspace->id)
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->selectRaw("CASE WHEN purchase_lines.ingredient_id IS NOT NULL THEN 'ingredient' ELSE 'packaging' END AS subject_type")
            ->selectRaw('CASE WHEN purchase_lines.ingredient_id IS NOT NULL THEN purchase_lines.ingredient_id ELSE purchase_lines.packaging_item_id END AS subject_id')
            ->selectRaw("COALESCE(SUM({$quantity}), 0) AS incoming")
            ->groupBy('purchase_lines.ingredient_id', 'purchase_lines.packaging_item_id');
    }

    private function settingTotals(Workspace $workspace): Builder
    {
        return DB::table('workspace_material_settings AS settings')
            ->where('settings.workspace_id', $workspace->id)
            ->selectRaw("CASE WHEN settings.ingredient_id IS NOT NULL THEN 'ingredient' ELSE 'packaging' END AS subject_type")
            ->selectRaw('CASE WHEN settings.ingredient_id IS NOT NULL THEN settings.ingredient_id ELSE settings.packaging_item_id END AS subject_id')
            ->selectRaw('settings.buffer_quantity AS buffer_quantity');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(
        Builder $query,
        Workspace $workspace,
        array $filters,
        string $available,
        string $forecast,
    ): void {
        $type = in_array($filters['type'] ?? 'all', ['all', 'ingredient', 'packaging'], true)
            ? (string) ($filters['type'] ?? 'all')
            : 'all';
        $stockState = in_array($filters['stock_state'] ?? 'all', ['all', 'negative_forecast', 'below_buffer', 'quarantined', 'incoming'], true)
            ? (string) ($filters['stock_state'] ?? 'all')
            : 'all';
        $demand = in_array($filters['demand'] ?? 'all', ['all', 'planned', 'unplanned'], true)
            ? (string) ($filters['demand'] ?? 'all')
            : 'all';
        $category = IngredientCategory::tryFrom((string) ($filters['category'] ?? ''));
        $subcategory = IngredientSubcategory::tryFrom((string) ($filters['subcategory'] ?? ''));

        if ($type !== 'all') {
            $query->where('tracked_materials.subject_type', $type);
        }

        if ($demand === 'planned') {
            $query->whereNotNull('material_demand_totals.subject_id');
        } elseif ($demand === 'unplanned') {
            $query->whereNull('material_demand_totals.subject_id');
        }

        if ($category instanceof IngredientCategory) {
            $query->where('ingredients.category', $category->value);
        }

        if ($subcategory instanceof IngredientSubcategory) {
            $query->where('ingredients.subcategory', $subcategory->value);
        }

        if ($stockState === 'negative_forecast') {
            $query->whereRaw("{$forecast} < 0");
        } elseif ($stockState === 'below_buffer') {
            $query->whereNotNull('material_settings.buffer_quantity')
                ->whereRaw("{$available} < material_settings.buffer_quantity");
        } elseif ($stockState === 'quarantined') {
            $query->whereRaw('COALESCE(material_lot_totals.quarantined, 0) > 0');
        } elseif ($stockState === 'incoming') {
            $query->whereRaw('COALESCE(material_incoming_totals.incoming, 0) > 0');
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search === '') {
            return;
        }

        $term = '%'.Str::lower($search).'%';
        $translationLocales = Ingredient::translationLocaleCandidates();

        $query->where(function (Builder $searchQuery) use ($term, $translationLocales, $workspace): void {
            $searchQuery
                ->whereRaw('LOWER(COALESCE(ingredients.display_name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(ingredients.inci_name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(ingredients.soap_inci_naoh_name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(ingredients.soap_inci_koh_name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(ingredients.saponification_name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(packaging_items.name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(workspace_ingredient_codes.material_code, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(packaging_items.material_code, \'\')) LIKE ?', [$term])
                ->orWhereExists(function ($exists) use ($term): void {
                    $exists
                        ->selectRaw('1')
                        ->from('ingredient_aliases AS aliases')
                        ->whereColumn('aliases.ingredient_id', 'ingredients.id')
                        ->where(function ($aliasQuery) use ($term): void {
                            $aliasQuery
                                ->whereRaw('LOWER(aliases.name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(aliases.normalized_name) LIKE ?', [$term]);
                        });
                })
                ->orWhereExists(function ($exists) use ($term): void {
                    $exists
                        ->selectRaw('1')
                        ->from('ingredient_identifiers AS identifiers')
                        ->whereColumn('identifiers.ingredient_id', 'ingredients.id')
                        ->where(function ($identifierQuery) use ($term): void {
                            $identifierQuery
                                ->whereRaw('LOWER(identifiers.value) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(identifiers.normalized_value) LIKE ?', [$term]);
                        });
                })
                ->orWhereExists(function ($exists) use ($term, $translationLocales): void {
                    $exists
                        ->selectRaw('1')
                        ->from('ingredient_translations AS translations')
                        ->whereColumn('translations.ingredient_id', 'ingredients.id')
                        ->when($translationLocales !== [], fn ($translationQuery) => $translationQuery->whereIn('translations.locale', $translationLocales))
                        ->where(function ($translationQuery) use ($term): void {
                            $translationQuery
                                ->whereRaw('LOWER(translations.display_name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(translations.saponification_name) LIKE ?', [$term]);
                        });
                })
                ->orWhereExists(function ($exists) use ($term, $workspace): void {
                    $exists
                        ->selectRaw('1')
                        ->from('supplier_listings AS listings')
                        ->leftJoin('suppliers', 'suppliers.id', '=', 'listings.supplier_id')
                        ->where('listings.workspace_id', $workspace->id)
                        ->where(function ($listingQuery): void {
                            $listingQuery
                                ->whereColumn('listings.ingredient_id', 'ingredients.id')
                                ->orWhereColumn('listings.packaging_item_id', 'packaging_items.id');
                        })
                        ->where(function ($listingSearch) use ($term): void {
                            $listingSearch
                                ->whereRaw('LOWER(COALESCE(listings.supplier_sku, \'\')) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(COALESCE(listings.supplier_item_name, \'\')) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(COALESCE(listings.purchase_format, \'\')) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(COALESCE(suppliers.name, \'\')) LIKE ?', [$term]);
                        });
                });
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyOrdering(Builder $query, array $filters): void
    {
        $sort = (string) ($filters['sort'] ?? 'priority');
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sortColumns = [
            'name' => 'sort_name',
            'physical' => 'physical',
            'available' => 'available',
            'forecast' => 'forecast',
        ];

        if ($sort === 'priority') {
            $query
                ->orderByDesc('is_shortage')
                ->orderByDesc('is_below_buffer')
                ->orderByDesc('has_demand')
                ->orderBy('sort_name')
                ->orderBy('tracked_materials.subject_type')
                ->orderBy('tracked_materials.subject_id');

            return;
        }

        $column = $sortColumns[$sort] ?? 'sort_name';

        $query
            ->orderBy($column, $direction)
            ->orderBy('tracked_materials.subject_type')
            ->orderBy('tracked_materials.subject_id');
    }

    /**
     * @param  array<string, Ingredient|PackagingItem>  $subjects
     */
    /**
     * Loads every subject on the page in one query per subject type rather than one
     * query per row, so the count stays flat as the page size grows.
     *
     * Only translations are eager-loaded: the visible name comes from
     * localizedDisplayName(), which reads the translations relation alone.
     *
     * @param  iterable<object>  $rows
     * @return array<string, Ingredient|PackagingItem>
     */
    private function loadSubjects(iterable $rows, Workspace $workspace): array
    {
        $subjectIds = collect($rows)
            ->map(fn (object $row): array => [
                'type' => (string) $row->subject_type,
                'id' => (int) $row->subject_id,
            ]);

        $idsOfType = fn (string $type): array => $subjectIds
            ->filter(fn (array $subject): bool => $subject['type'] === $type)
            ->pluck('id')
            ->all();

        $ingredients = Ingredient::query()
            ->with('translations')
            ->whereIn('id', $idsOfType('ingredient'))
            ->get()
            ->mapWithKeys(fn (Ingredient $ingredient): array => [
                'ingredient:'.$ingredient->id => $ingredient,
            ]);

        $packaging = PackagingItem::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $idsOfType('packaging'))
            ->get()
            ->mapWithKeys(fn (PackagingItem $item): array => [
                'packaging:'.$item->id => $item,
            ]);

        return $ingredients->all() + $packaging->all();
    }

    /**
     * @param  array<string, Ingredient|PackagingItem>  $subjects
     */
    private function hydrateRow(object $row, Workspace $workspace, array $subjects = []): ?array
    {
        $subject = $subjects[$row->subject_type.':'.$row->subject_id] ?? null;

        if (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem) {
            return null;
        }

        $values = collect([
            'physical' => $row->physical ?? self::Zero,
            'available' => $row->available ?? self::Zero,
            'reserved' => $row->reserved ?? self::Zero,
            'quarantined' => $row->quarantined ?? self::Zero,
            'incoming' => $row->incoming ?? self::Zero,
            'required' => $row->required ?? self::Zero,
            'forecast' => $row->forecast ?? self::Zero,
            'buffer_quantity' => $row->buffer_quantity,
        ])->map(function (mixed $value, string $key): ?string {
            if ($value === null && $key === 'buffer_quantity') {
                return null;
            }

            return bcadd((string) ($value ?? '0'), '0', 9);
        })->all();

        return [
            'key' => $row->subject_type.':'.$row->subject_id,
            'subject' => $subject,
            'name' => $subject instanceof Ingredient
                ? (string) $subject->localizedDisplayName()
                : $subject->name,
            'material_code' => $subject instanceof Ingredient
                ? ($row->ingredient_material_code ?: null)
                : ($subject->material_code ?: null),
            'category' => $subject instanceof Ingredient
                ? $row->ingredient_category
                : $row->packaging_category,
            'subcategory' => $row->ingredient_subcategory,
            'display_unit' => $subject instanceof Ingredient ? 'mass' : 'count',
            'has_demand' => (bool) $row->has_demand,
            'has_listing' => (bool) $row->has_listing,
            'has_incoming' => (bool) $row->has_incoming,
            'has_quarantined' => (bool) $row->has_quarantined,
            'is_shortage' => (bool) $row->is_shortage,
            'is_below_buffer' => (bool) $row->is_below_buffer,
            'physical' => $values['physical'],
            'available' => $values['available'],
            'reserved' => $values['reserved'],
            'quarantined' => $values['quarantined'],
            'incoming' => $values['incoming'],
            'required' => $values['required'],
            'forecast' => $values['forecast'],
            'buffer_quantity' => $values['buffer_quantity'],
            'positions' => [
                'physical' => $values['physical'],
                'available' => $values['available'],
                'reserved' => $values['reserved'],
                'quarantined' => $values['quarantined'],
                'incoming' => $values['incoming'],
                'required' => $values['required'],
                'forecast' => $values['forecast'],
            ],
        ];
    }
}
