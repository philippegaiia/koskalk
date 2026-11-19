<?php

namespace App\Services\Inventory;

use App\Enums\ProductionOutputType;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Models\Ingredient;
use App\Models\ProductSize;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceStockLotQuery
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    /**
     * @param  array{search?: string, brand?: string, collection?: string}  $filters
     * @return LengthAwarePaginator<int, StockLot>|null
     */
    public function paginateFinishedBySize(User $actor, Workspace $workspace, int $perPage, bool $includePublished = false, array $filters = []): ?LengthAwarePaginator
    {
        $this->access->assertReadable($actor, $workspace);

        $finishedLots = StockLot::query()
            ->where('stock_lots.workspace_id', $workspace->id)
            ->whereIn('stock_lots.origin', [StockLotOrigin::ProductionOutput, StockLotOrigin::PackagingOutput])
            ->where('stock_lots.unit_kind', StockUnitKind::Count)
            ->whereNotNull('stock_lots.recipe_id');

        if (! $includePublished && $finishedLots->doesntExist()) {
            return null;
        }

        $balances = $finishedLots
            ->select(['stock_lots.recipe_id', 'stock_lots.product_size_id'])
            ->selectRaw('SUM(CASE WHEN stock_lots.status = ? THEN movement.quantity_delta ELSE 0 END) AS available', [StockLotStatus::Released->value])
            ->selectRaw('SUM(CASE WHEN stock_lots.status = ? THEN movement.quantity_delta ELSE 0 END) AS quarantined', [StockLotStatus::Quarantined->value])
            ->join('recipes', 'recipes.id', '=', 'stock_lots.recipe_id')
            ->join('stock_movements as movement', 'movement.stock_lot_id', '=', 'stock_lots.id')
            ->where('recipes.workspace_id', $workspace->id)
            ->whereColumn('movement.workspace_id', 'stock_lots.workspace_id')
            ->groupBy('stock_lots.recipe_id', 'stock_lots.product_size_id')
            ->havingRaw('SUM(movement.quantity_delta) <> 0');

        if ($includePublished) {
            $balances->unionAll($this->publishedFinishedSizes($workspace));
        }

        $search = Str::lower(trim($filters['search'] ?? ''));
        $brand = $filters['brand'] ?? '';
        $collection = $filters['collection'] ?? '';

        return StockLot::query()
            ->fromSub($balances, 'finished')
            ->select(['finished.recipe_id', 'finished.product_size_id'])
            ->selectRaw('SUM(finished.available) AS available, SUM(finished.quarantined) AS quarantined')
            ->join('recipes', 'recipes.id', '=', 'finished.recipe_id')
            ->when($brand !== '', fn (Builder $query): Builder => $query->where('recipes.brand_id', ctype_digit($brand) ? (int) $brand : 0))
            ->when($collection !== '', fn (Builder $query): Builder => $query->where('recipes.product_collection_id', ctype_digit($collection) ? (int) $collection : 0))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                $query->leftJoin('product_sizes as size', 'size.id', '=', 'finished.product_size_id');
                $query->where(function (Builder $matches) use ($search, $term): void {
                    $matches->whereRaw("LOWER(recipes.name) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(size.name) LIKE ? ESCAPE '!'", [$term])
                        ->orWhereRaw("LOWER(size.sku) LIKE ? ESCAPE '!'", [$term]);

                    if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(g|kg|ml|l|oz|lb|fl[ _]?oz)?$/', $search, $parts)) {
                        $matches->orWhere(fn (Builder $content): Builder => $content
                            ->where('size.declared_content_value', str_replace(',', '.', $parts[1]))
                            ->when(isset($parts[2]), fn (Builder $value): Builder => $value
                                ->where('size.declared_content_unit', str_replace(['fl oz', 'floz'], 'fl_oz', $parts[2]))));
                    }
                });
            })
            ->groupBy('finished.recipe_id', 'finished.product_size_id', 'recipes.name')
            ->orderBy('recipes.name')
            ->orderBy('finished.recipe_id')
            ->orderBy('finished.product_size_id')
            ->with($includePublished
                ? ['recipe:id,name,brand_id,product_collection_id', 'recipe.brand:id,name', 'recipe.productCollection:id,name', 'productSize']
                : ['recipe:id,name', 'productSize'])
            ->paginate($perPage, pageName: 'finished-stock');
    }

    private function publishedFinishedSizes(Workspace $workspace): Builder
    {
        $latestVersion = DB::table('recipe_versions')
            ->select('id')
            ->whereColumn('recipe_id', 'product_sizes.recipe_id')
            ->where('workspace_id', $workspace->id)
            ->where('is_current', false)
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->limit(1);

        return ProductSize::query()
            ->select(['product_sizes.recipe_id', 'product_sizes.id as product_size_id'])
            ->selectRaw('0 AS available, 0 AS quarantined')
            ->join('recipes', 'recipes.id', '=', 'product_sizes.recipe_id')
            ->join('recipe_version_product_sizes as membership', 'membership.product_size_id', '=', 'product_sizes.id')
            ->where('recipes.workspace_id', $workspace->id)
            ->whereNull('recipes.archived_at')
            ->where(fn (Builder $product): Builder => $product
                ->where('recipes.production_output_type', ProductionOutputType::FinishedProduct)
                ->orWhereNull('recipes.production_output_type'))
            ->where('membership.recipe_version_id', '=', $latestVersion);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(User $actor, Workspace $workspace, array $filters = []): Builder
    {
        $this->access->assertReadable($actor, $workspace);

        $search = trim((string) ($filters['search'] ?? ''));
        $searchTerm = '%'.Str::lower($search).'%';
        $translationLocales = Ingredient::translationLocaleCandidates();
        $requestedStatus = (string) ($filters['lotStatus'] ?? 'all');
        $status = in_array($requestedStatus, ['all', StockLotStatus::Released->value, StockLotStatus::Quarantined->value], true)
            ? $requestedStatus
            : 'all';
        $requestedScope = (string) ($filters['lotScope'] ?? 'open');
        $scope = in_array($requestedScope, ['open', 'exhausted', 'all'], true) ? $requestedScope : 'open';
        $requestedOrigin = (string) ($filters['lotOrigin'] ?? '');
        $originOptions = collect(StockLotOrigin::cases())
            ->map(fn (StockLotOrigin $lotOrigin): string => $lotOrigin->value)
            ->all();
        $origin = in_array($requestedOrigin, $originOptions, true) ? $requestedOrigin : '';
        $storageLocation = $workspace->uses_storage_locations
            ? (string) ($filters['lotStorageLocation'] ?? 'all')
            : 'all';
        $dateBasis = (string) ($filters['lotDateBasis'] ?? 'stocked');
        $dateFrom = (string) ($filters['lotDateFrom'] ?? '');
        $dateUntil = (string) ($filters['lotDateUntil'] ?? '');
        $expiry = (string) ($filters['lotExpiry'] ?? 'all');
        $material = (string) ($filters['lotMaterial'] ?? '');
        $materialType = (string) ($filters['lotMaterialType'] ?? '');
        $supplier = (string) ($filters['lotSupplier'] ?? '');
        $sort = (string) ($filters['lotSort'] ?? 'newest');
        $physical = '(SELECT COALESCE(SUM(movements.quantity_delta), 0) FROM stock_movements AS movements WHERE movements.stock_lot_id = stock_lots.id)';
        $activeReserved = '(SELECT COALESCE(SUM(reservations.quantity), 0) FROM stock_reservations AS reservations WHERE reservations.stock_lot_id = stock_lots.id AND reservations.status = \'active\')';

        $with = [
            'ingredient.translations',
            'ingredient.workspaceCodes',
            'packagingItem',
            'recipe:id,name',
            'goodsReceiptLine.goodsReceipt.supplier',
            'supplierListing.supplier',
        ];

        if ($workspace->uses_storage_locations) {
            $with[] = 'storageLocation';
        }

        return StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->with($with)
            ->withSum('movements', 'quantity_delta')
            // Every lot is created with one immutable opening ledger entry.
            // Later movements can be backdated, so creation order identifies it.
            ->addSelect([
                'initial_quantity' => StockMovement::query()
                    ->select('quantity_delta')
                    ->whereColumn('stock_lot_id', 'stock_lots.id')
                    ->oldest('id')
                    ->limit(1),
            ])
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->when($status !== 'all', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($origin !== '', fn (Builder $query): Builder => $query->where('origin', $origin))
            ->when($storageLocation === 'unassigned', fn (Builder $query): Builder => $query->whereNull('storage_location_id'))
            ->when(
                $storageLocation !== 'all' && $storageLocation !== 'unassigned',
                fn (Builder $query): Builder => $query->where('storage_location_id', (int) $storageLocation),
            )
            ->when($scope === 'open', fn (Builder $query): Builder => $query
                ->whereRaw("({$physical} <> 0 OR {$activeReserved} <> 0)"))
            ->when($scope === 'exhausted', fn (Builder $query): Builder => $query
                ->whereRaw("{$physical} = 0")
                ->whereRaw("{$activeReserved} = 0"))
            ->when(
                $dateBasis === 'stocked' && $dateFrom !== '',
                fn (Builder $query): Builder => $query->whereDate('stocked_at', '>=', $dateFrom),
            )
            ->when(
                $dateBasis === 'stocked' && $dateUntil !== '',
                fn (Builder $query): Builder => $query->whereDate('stocked_at', '<=', $dateUntil),
            )
            ->when(
                $dateBasis === 'received' && ($dateFrom !== '' || $dateUntil !== ''),
                function (Builder $query) use ($dateFrom, $dateUntil): void {
                    $query->whereHas(
                        'goodsReceiptLine.goodsReceipt',
                        function (Builder $receiptQuery) use ($dateFrom, $dateUntil): void {
                            $receiptQuery
                                ->when($dateFrom !== '', fn (Builder $query): Builder => $query->whereDate('received_at', '>=', $dateFrom))
                                ->when($dateUntil !== '', fn (Builder $query): Builder => $query->whereDate('received_at', '<=', $dateUntil));
                        },
                    );
                },
            )
            ->when($expiry === 'active', fn (Builder $query): Builder => $query->whereNotNull('expires_at')->whereDate('expires_at', '>=', today()))
            ->when($expiry === 'expired', fn (Builder $query): Builder => $query->whereNotNull('expires_at')->whereDate('expires_at', '<', today()))
            ->when($expiry === 'none', fn (Builder $query): Builder => $query->whereNull('expires_at'))
            ->when($material !== '', function (Builder $query) use ($workspace, $material, $materialType): void {
                $query->where(function (Builder $materialQuery) use ($workspace, $material, $materialType): void {
                    if ($materialType === 'ingredient') {
                        $materialQuery->whereHas('ingredient', fn (Builder $ingredientQuery): Builder => $ingredientQuery->where('public_id', $material));

                        return;
                    }

                    if ($materialType === 'packaging') {
                        $materialQuery->whereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->where('workspace_id', $workspace->id)
                            ->where('public_id', $material));

                        return;
                    }

                    $materialQuery
                        ->whereHas('ingredient', fn (Builder $ingredientQuery): Builder => $ingredientQuery->where('public_id', $material))
                        ->orWhereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->where('workspace_id', $workspace->id)
                            ->where('public_id', $material));
                });
            })
            ->when($supplier !== '', function (Builder $query) use ($supplier): void {
                $query->where(function (Builder $supplierQuery) use ($supplier): void {
                    $supplierQuery
                        ->whereHas('supplierListing.supplier', fn (Builder $listingSupplierQuery): Builder => $listingSupplierQuery->where('public_id', $supplier))
                        ->orWhereHas('goodsReceiptLine.goodsReceipt.supplier', fn (Builder $receiptSupplierQuery): Builder => $receiptSupplierQuery->where('public_id', $supplier));
                });
            })
            ->when($search !== '', function (Builder $query) use ($searchTerm, $translationLocales, $workspace): void {
                $query->where(function (Builder $searchQuery) use ($searchTerm, $translationLocales, $workspace): void {
                    $searchQuery
                        ->whereRaw('LOWER(internal_lot_code) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(supplier_batch_number) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(product_size_label_snapshot) LIKE ?', [$searchTerm])
                        ->orWhereHas('recipe', fn (Builder $recipeQuery): Builder => $recipeQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]))
                        ->orWhereHas('ingredient', function (Builder $ingredientQuery) use ($searchTerm, $translationLocales): void {
                            $ingredientQuery->where(function (Builder $ingredientNameQuery) use ($searchTerm, $translationLocales): void {
                                $ingredientNameQuery
                                    ->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm])
                                    ->orWhereRaw('LOWER(COALESCE(inci_name, \'\')) LIKE ?', [$searchTerm])
                                    ->orWhereRaw('LOWER(COALESCE(soap_inci_naoh_name, \'\')) LIKE ?', [$searchTerm])
                                    ->orWhereRaw('LOWER(COALESCE(soap_inci_koh_name, \'\')) LIKE ?', [$searchTerm])
                                    ->orWhereRaw('LOWER(COALESCE(saponification_name, \'\')) LIKE ?', [$searchTerm]);

                                if ($translationLocales !== []) {
                                    $ingredientNameQuery->orWhereHas('translations', function (Builder $translationQuery) use ($searchTerm, $translationLocales): void {
                                        $translationQuery
                                            ->whereIn('locale', $translationLocales)
                                            ->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);
                                    });
                                }
                            });
                        })
                        ->orWhereHas('ingredient.workspaceCodes', fn (Builder $codeQuery): Builder => $codeQuery
                            ->where('workspace_id', $workspace->id)
                            ->whereRaw('LOWER(material_code) LIKE ?', [$searchTerm]))
                        ->orWhereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                            ->orWhereRaw('LOWER(material_code) LIKE ?', [$searchTerm]))
                        ->orWhereHas('supplierListing', function (Builder $listingQuery) use ($searchTerm): void {
                            $listingQuery
                                ->whereRaw('LOWER(COALESCE(supplier_sku, \'\')) LIKE ?', [$searchTerm])
                                ->orWhereRaw('LOWER(COALESCE(supplier_item_name, \'\')) LIKE ?', [$searchTerm])
                                ->orWhereHas('supplier', fn (Builder $supplierQuery): Builder => $supplierQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]));
                        })
                        ->orWhereHas('goodsReceiptLine.goodsReceipt.supplier', fn (Builder $supplierQuery): Builder => $supplierQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]));
                });
            })
            ->when($sort === 'oldest', fn (Builder $query): Builder => $query->orderBy('stocked_at')->orderBy('id'))
            ->when($sort === 'code', fn (Builder $query): Builder => $query->orderBy('internal_lot_code')->orderBy('id'))
            ->when($sort === 'newest', fn (Builder $query): Builder => $query->latest('stocked_at')->latest('id'));
    }
}
