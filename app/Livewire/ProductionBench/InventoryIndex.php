<?php

namespace App\Livewire\ProductionBench;

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\QuarantineStockLot;
use App\Actions\Inventory\ReleaseStockLot;
use App\Enums\StockLotStatus;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\MassConverter;
use App\Services\Production\WorkspaceMaterialCatalog;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\Services\WorkspaceIngredientCodeService;
use App\Support\LocalizedDecimalInput;
use App\Support\NumberLocale;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryIndex extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithAppNotifications;
    use InteractsWithForms;
    use WithPagination;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    private const int OPTION_LIMIT = 30;

    /**
     * The two grains the module exposes: materials and the lots that hold them.
     * The material view carries both planned demand and listed-only materials,
     * which is what the earlier requirement/stock/overview split could not do.
     */
    private const array MODES = ['materials', 'stock'];

    private const array MATERIAL_SCOPES = ['all', 'shortages', 'planned', 'unplanned'];

    private CreateOpeningStockLot $createOpeningStockLot;

    private CurrencyCatalog $currencyCatalog;

    private MassConverter $massConverter;

    private ProductionBenchAccess $productionBenchAccess;

    private WorkspaceIngredientCodeService $ingredientCodes;

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $mode = 'materials';

    public int $perPage = 25;

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function boot(
        CreateOpeningStockLot $createOpeningStockLot,
        CurrencyCatalog $currencyCatalog,
        MassConverter $massConverter,
        ProductionBenchAccess $productionBenchAccess,
        WorkspaceIngredientCodeService $ingredientCodes,
    ): void {
        $this->createOpeningStockLot = $createOpeningStockLot;
        $this->currencyCatalog = $currencyCatalog;
        $this->massConverter = $massConverter;
        $this->productionBenchAccess = $productionBenchAccess;
        $this->ingredientCodes = $ingredientCodes;
    }

    public function mount(string $mode = 'materials'): void
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : 'materials';
        $this->filtersForm->fill([
            'search' => '',
            'scope' => 'all',
            'status' => 'all',
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['md' => 2])
                    ->schema([
                        TextInput::make('search')
                            ->label(__('production_bench.common.search'))
                            ->type('search')
                            ->live(debounce: 300)
                            ->afterStateUpdated(function (): void {
                                $this->resetPage('materials');
                                $this->resetPage('stock-lots');
                            }),
                        Select::make('scope')
                            ->label(__('production_bench.inventory.scope'))
                            ->options([
                                'all' => __('production_bench.inventory.scope_all'),
                                'shortages' => __('production_bench.inventory.scope_shortages'),
                                'planned' => __('production_bench.inventory.scope_planned'),
                                'unplanned' => __('production_bench.inventory.scope_unplanned'),
                            ])
                            ->native(false)
                            ->live()
                            ->visible(fn (): bool => $this->mode === 'materials')
                            ->afterStateUpdated(fn () => $this->resetPage('materials')),
                        Select::make('status')
                            ->label(__('production_bench.common.status'))
                            ->options([
                                'all' => __('production_bench.common.all'),
                                StockLotStatus::Released->value => __('production_bench.inventory.released'),
                                StockLotStatus::Quarantined->value => __('production_bench.inventory.quarantined'),
                            ])
                            ->native(false)
                            ->live()
                            ->visible(fn (): bool => $this->mode === 'stock')
                            ->afterStateUpdated(fn () => $this->resetPage('stock-lots')),
                    ]),
            ])
            ->statePath('filters');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage('materials');
        $this->resetPage('stock-lots');
    }

    public function addStockAction(): Action
    {
        return Action::make('addStock')
            ->label(__('production_bench.inventory.add_stock_manually'))
            ->modalHeading(__('production_bench.inventory.add_stock_manually'))
            ->modalDescription(__('production_bench.inventory.add_stock_description'))
            ->modalSubmitActionLabel(__('production_bench.inventory.add_stock'))
            ->modalCancelActionLabel(__('production_bench.common.cancel'))
            ->modalWidth(Width::FourExtraLarge)
            ->visible(fn (): bool => $this->mode === 'stock' && $this->canAddStock())
            ->fillForm(fn (): array => [
                'currency' => $this->workspace()->default_currency,
                'stocked_at' => today()->toDateString(),
                'unit' => $this->workspace()->mass_display_system->priceUnit()->value,
            ])
            ->schema([
                Select::make('supplier_listing_id')
                    ->label(__('production_bench.inventory.supplier_listing'))
                    ->placeholder(__('production_bench.inventory.search_supplier_listing'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => $this->supplierListingSearchResults($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $this->supplierListingOptionLabel(is_numeric($value) ? (int) $value : null))
                    ->rules([
                        Rule::exists(SupplierListing::class, 'id')->where(fn (QueryBuilder $query): QueryBuilder => $query
                            ->where('workspace_id', $this->workspace()->id)
                            ->where('is_active', true)),
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $listing = $this->activeSupplierListing(is_numeric($state) ? (int) $state : null);

                        if (! $listing instanceof SupplierListing) {
                            $set('price_per_unit', null);

                            return;
                        }

                        $unit = $listing->unit_kind === StockUnitKind::Count
                            ? 'count'
                            : $listing->net_unit;
                        $set('unit', $unit);
                        $set('currency', $listing->currency);
                        $set('price_per_unit', $this->listingPricePerUnit($listing, $unit));
                    })
                    ->columnSpanFull(),
                Grid::make(2)
                    ->schema([
                        LocalizedDecimalInput::make('quantity')
                            ->label(__('production_bench.inventory.quantity'))
                            ->required()
                            ->minValue('0.000000001'),
                        Select::make('unit')
                            ->label(__('production_bench.inventory.unit'))
                            ->options(fn (Get $get): array => $this->unitOptions($get('supplier_listing_id')))
                            ->required()
                            ->disabled(fn (Get $get): bool => filled($get('supplier_listing_id')))
                            ->dehydrated(),
                        LocalizedDecimalInput::make('price_per_unit')
                            ->label(fn (Get $get): string => __('production_bench.inventory.cost_per', [
                                'unit' => $get('unit') === 'count' ? __('production_bench.inventory.item') : ($get('unit') ?: __('production_bench.inventory.unit')),
                            ]))
                            ->helperText(__('production_bench.inventory.cost_help'))
                            ->required()
                            ->minValue(0),
                        Select::make('currency')
                            ->label(__('production_bench.common.currency'))
                            ->options($this->currencyOptions())
                            ->searchable()
                            ->rules([Rule::in(array_keys($this->currencyOptions()))])
                            ->required()
                            ->disabled(fn (Get $get): bool => filled($get('supplier_listing_id')))
                            ->dehydrated(),
                    ])
                    ->columnSpanFull(),
                Grid::make(2)
                    ->schema([
                        DatePicker::make('stocked_at')
                            ->label(__('production_bench.inventory.stocked_on'))
                            ->native(false)
                            ->closeOnDateSelection()
                            ->weekStartsOnMonday()
                            ->maxDate(today()->toDateString())
                            ->validationMessages([
                                'before_or_equal' => __('production_bench.inventory.stocked_on_future'),
                            ])
                            ->required(),
                        DatePicker::make('expires_at')
                            ->label(__('production_bench.inventory.expires_on'))
                            ->native(false)
                            ->closeOnDateSelection()
                            ->weekStartsOnMonday()
                            ->afterOrEqual('stocked_at'),
                    ])
                    ->columnSpanFull(),
                Grid::make(2)
                    ->schema([
                        TextEntry::make('internal_batch_number')
                            ->label(__('production_bench.inventory.internal_batch'))
                            ->state(__('production_bench.inventory.internal_batch_generated')),
                        TextInput::make('supplier_batch_number')
                            ->label(__('production_bench.inventory.supplier_batch'))
                            ->maxLength(255),
                    ])
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('production_bench.common.notes'))
                    ->rows(3)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ])
            ->action(fn (array $data) => $this->createManualStock($data));
    }

    public function release(int $lotId, ReleaseStockLot $action): void
    {
        $action->handle($this->user(), $this->lot($lotId));
    }

    public function quarantine(int $lotId, QuarantineStockLot $action): void
    {
        $action->handle($this->user(), $this->lot($lotId));
    }

    public function render(
        ProductionBenchAccess $access,
        StockPositionService $positions,
        MassConverter $massConverter,
        WorkspaceMaterialCatalog $catalog,
    ): View {
        $workspace = $this->workspace();
        $displayUnit = $workspace->mass_display_system->priceUnit()->value;

        // Materials and lots are two grains of the same stock. No view needs
        // both, so only the active mode pays for its queries.
        $materialRows = $this->mode === 'materials'
            ? $this->materialRows($workspace, $catalog, $positions, $massConverter, $displayUnit)
            : collect();

        return view('livewire.production-bench.inventory-index', [
            'workspace' => $workspace,
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'lots' => $this->mode === 'stock'
                ? $this->stockLots($workspace, $positions, $massConverter, $displayUnit)
                : collect(),
            'materials' => $this->paginate($materialRows, 'materials'),
            'inventorySummary' => $this->materialSummary($materialRows),
            'materialFiltersActive' => $this->materialFiltersActive(),
            'displayUnit' => $displayUnit,
        ]);
    }

    /**
     * The lot register: one row per physical batch, with its own positions.
     */
    private function stockLots(
        Workspace $workspace,
        StockPositionService $positions,
        MassConverter $massConverter,
        string $displayUnit,
    ): LengthAwarePaginator {
        $search = trim((string) ($this->filters['search'] ?? ''));
        $searchTerm = '%'.Str::lower($search).'%';
        $translationLocales = Ingredient::translationLocaleCandidates();
        $status = in_array($this->filters['status'] ?? null, ['all', StockLotStatus::Released->value, StockLotStatus::Quarantined->value], true)
            ? (string) $this->filters['status']
            : 'all';

        $stockLots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'ingredient.translations',
                'packagingItem',
                'goodsReceiptLine.goodsReceipt.supplier',
            ])
            ->withSum('movements', 'quantity_delta')
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->when($status !== 'all', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($searchTerm, $translationLocales): void {
                $query->where(function (Builder $searchQuery) use ($searchTerm, $translationLocales): void {
                    $searchQuery
                        ->whereRaw('LOWER(internal_lot_code) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(supplier_batch_number) LIKE ?', [$searchTerm])
                        ->orWhereHas('ingredient', function (Builder $ingredientQuery) use ($searchTerm, $translationLocales): void {
                            $ingredientQuery->where(function (Builder $ingredientNameQuery) use ($searchTerm, $translationLocales): void {
                                $ingredientNameQuery->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);

                                if ($translationLocales !== []) {
                                    $ingredientNameQuery->orWhereHas('translations', function (Builder $translationQuery) use ($searchTerm, $translationLocales): void {
                                        $translationQuery
                                            ->whereIn('locale', $translationLocales)
                                            ->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);
                                    });
                                }
                            });
                        })
                        ->orWhereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                            ->orWhereRaw('LOWER(material_code) LIKE ?', [$searchTerm]));
                });
            })
            ->latest('stocked_at')
            ->latest('id')
            ->paginate($this->normalizedPerPage(), ['*'], 'stock-lots');

        return $stockLots->through(function (StockLot $lot) use ($positions, $massConverter, $displayUnit): array {
            $stock = $positions->forLotWithLoadedMovementSum($lot);

            return [
                'lot' => $lot,
                'positions' => collect($stock)
                    ->only(['physical', 'quarantined', 'reserved', 'available'])
                    ->map(
                        fn (string $quantity): string => $lot->ingredient_id !== null
                            ? number_format((float) $massConverter->fromGramsSigned($quantity, $displayUnit), 2)
                            : number_format((float) $quantity, 0),
                    )
                    ->all(),
            ];
        });
    }

    /**
     * Every tracked material, filtered and ordered, before pagination.
     *
     * Tracked means planned production asks for it or the workspace holds a
     * supplier listing for it, so a material the workspace can buy shows up
     * long before a run actually demands it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function materialRows(
        Workspace $workspace,
        WorkspaceMaterialCatalog $catalog,
        StockPositionService $positions,
        MassConverter $massConverter,
        string $displayUnit,
    ): Collection {
        $materials = $catalog->materials($workspace);

        if ($materials->isEmpty()) {
            return collect();
        }

        $codes = $this->ingredientCodes->codesFor(
            $workspace,
            $materials
                ->pluck('subject')
                ->filter(fn (mixed $subject): bool => $subject instanceof Ingredient)
                ->map(fn (Ingredient $ingredient): int => $ingredient->id)
                ->values()
                ->all(),
        );

        $search = Str::lower(trim((string) ($this->filters['search'] ?? '')));
        $scope = in_array($this->filters['scope'] ?? null, self::MATERIAL_SCOPES, true)
            ? (string) $this->filters['scope']
            : 'all';

        $rows = $materials
            ->map(function (array $material) use ($codes, $displayUnit): array {
                $subject = $material['subject'];

                return [
                    'key' => $material['key'],
                    'subject' => $subject,
                    'name' => $this->subjectName($subject),
                    'material_code' => $subject instanceof Ingredient
                        ? ($codes[$subject->id] ?? null)
                        : $subject->material_code,
                    'display_unit' => $subject instanceof Ingredient
                        ? $displayUnit
                        : __('production_bench.inventory.units'),
                    'has_demand' => $material['has_demand'],
                    'has_listing' => $material['has_listing'],
                ];
            })
            ->when($search !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => str_contains(Str::lower($row['name']), $search)
                    || str_contains(Str::lower((string) $row['material_code']), $search),
            ))
            // Narrowing by demand needs no stock positions, so it runs first and
            // spares the position queries everything it filters out.
            ->when(in_array($scope, ['planned', 'unplanned'], true), fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => $row['has_demand'] === ($scope === 'planned'),
            ))
            ->values();

        if ($rows->isEmpty()) {
            return collect();
        }

        $positionsByKey = $positions->forWorkspaceSubjects(
            workspace: $workspace,
            subjectKeys: $rows->pluck('key')->all(),
        );

        return $rows
            ->map(function (array $row) use ($positionsByKey, $massConverter, $displayUnit): array {
                $subject = $row['subject'];
                $stock = $positionsByKey[$row['key']] ?? [
                    'reserved' => '0.000000000',
                    'available' => '0.000000000',
                    'incoming' => '0.000000000',
                    'forecast' => '0.000000000',
                    'quarantined' => '0.000000000',
                ];
                $format = fn (string $quantity): string => $subject instanceof Ingredient
                    ? number_format((float) $massConverter->fromGramsSigned($quantity, $displayUnit), 2)
                    : number_format((float) $quantity, 0);

                return [
                    ...$row,
                    'is_shortage' => bccomp($stock['forecast'], '0', 9) < 0,
                    'has_incoming' => bccomp($stock['incoming'], '0', 9) > 0,
                    'has_quarantined' => bccomp($stock['quarantined'], '0', 9) > 0,
                    'required' => $format(
                        bcsub(bcadd($stock['available'], $stock['incoming'], 9), $stock['forecast'], 9),
                    ),
                    'positions' => [
                        'reserved' => $format($stock['reserved']),
                        'available' => $format($stock['available']),
                        'incoming' => $format($stock['incoming']),
                        'forecast' => $format($stock['forecast']),
                    ],
                ];
            })
            ->when($scope === 'shortages', fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => $row['is_shortage'],
            ))
            // Shortages first, then materials a run actually asks for, so the
            // listed-only materials this view adds sit below everything that
            // needs a decision today.
            ->sort(function (array $left, array $right): int {
                if ($left['is_shortage'] !== $right['is_shortage']) {
                    return $left['is_shortage'] ? -1 : 1;
                }

                if ($left['has_demand'] !== $right['has_demand']) {
                    return $left['has_demand'] ? -1 : 1;
                }

                return strnatcasecmp((string) $left['name'], (string) $right['name']);
            })
            ->values();
    }

    /**
     * Paginate an already-filtered collection. The ordering above is derived,
     * so it cannot be pushed into SQL.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function paginate(Collection $rows, string $pageName): LengthAwarePaginator
    {
        $perPage = $this->normalizedPerPage();
        $page = max(1, (int) $this->getPage($pageName));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

    /**
     * Whether the material table is narrowed, which is what decides whether an
     * empty table means "nothing tracked" or "nothing matches".
     */
    private function materialFiltersActive(): bool
    {
        return trim((string) ($this->filters['search'] ?? '')) !== ''
            || (in_array($this->filters['scope'] ?? null, self::MATERIAL_SCOPES, true)
                && (string) $this->filters['scope'] !== 'all');
    }

    /**
     * Tile counts taken from the filtered rows, so the tiles and the table
     * always describe the same set of materials.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{materials: int, shortages: int, incoming: int, quarantined: int, unplanned: int}
     */
    private function materialSummary(Collection $rows): array
    {
        return [
            'materials' => $rows->count(),
            'shortages' => $rows->where('is_shortage', true)->count(),
            'incoming' => $rows->where('has_incoming', true)->count(),
            'quarantined' => $rows->where('has_quarantined', true)->count(),
            'unplanned' => $rows->where('has_demand', false)->count(),
        ];
    }

    private function lot(int $lotId): StockLot
    {
        return StockLot::query()
            ->where('workspace_id', $this->workspace()->id)
            ->findOrFail($lotId);
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }

    private function subjectName(Ingredient|PackagingItem $subject): string
    {
        return $subject instanceof Ingredient
            ? (string) $subject->localizedDisplayName()
            : $subject->name;
    }

    /** @return array<int, string> */
    public function supplierListingSearchResults(string $search): array
    {
        $search = Str::lower(trim($search));
        $searchTerm = "%{$search}%";
        $translationLocales = Ingredient::translationLocaleCandidates();

        return SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('is_active', true)
            ->with(['supplier', 'ingredient.translations', 'packagingItem'])
            ->when($search !== '', function (Builder $query) use ($searchTerm, $translationLocales): void {
                $query->where(function (Builder $searchQuery) use ($searchTerm, $translationLocales): void {
                    $searchQuery
                        ->whereRaw('LOWER(supplier_sku) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(supplier_item_name) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(purchase_format) LIKE ?', [$searchTerm])
                        ->orWhereHas('supplier', fn (Builder $supplierQuery): Builder => $supplierQuery->whereRaw('LOWER(name) LIKE ?', [$searchTerm]))
                        ->orWhereHas('ingredient', function (Builder $ingredientQuery) use ($searchTerm, $translationLocales): void {
                            $ingredientQuery->where(function (Builder $ingredientNameQuery) use ($searchTerm, $translationLocales): void {
                                $ingredientNameQuery->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);

                                if ($translationLocales !== []) {
                                    $ingredientNameQuery->orWhereHas('translations', function (Builder $translationQuery) use ($searchTerm, $translationLocales): void {
                                        $translationQuery
                                            ->whereIn('locale', $translationLocales)
                                            ->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);
                                    });
                                }
                            });
                        })
                        ->orWhereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                            ->orWhereRaw('LOWER(material_code) LIKE ?', [$searchTerm]));
                });
            })
            ->latest('id')
            ->limit(self::OPTION_LIMIT)
            ->get()
            ->mapWithKeys(fn (SupplierListing $listing): array => [$listing->id => $this->supplierListingLabel($listing)])
            ->all();
    }

    public function supplierListingOptionLabel(?int $listingId): ?string
    {
        $listing = $this->activeSupplierListing($listingId);

        return $listing instanceof SupplierListing ? $this->supplierListingLabel($listing) : null;
    }

    /** @param array<string, mixed> $data */
    private function createManualStock(array $data): void
    {
        $workspace = $this->workspace();
        $listing = $this->activeSupplierListing((int) $data['supplier_listing_id']) ?? abort(404);
        $unit = (string) $data['unit'];
        $pricePerUnit = (string) $data['price_per_unit'];
        $pricePerCanonicalUnit = $listing->ingredient_id !== null
            ? bcdiv($pricePerUnit, $this->massConverter->toGrams('1', $unit), 12)
            : $pricePerUnit;

        $lot = $this->createOpeningStockLot->handle(
            actor: $this->user(),
            workspace: $workspace,
            listing: $listing,
            quantity: (string) $data['quantity'],
            unit: $unit,
            pricePerCanonicalUnit: $pricePerCanonicalUnit,
            currency: $listing->currency,
            idempotencyKey: (string) Str::uuid(),
            supplierBatchNumber: filled($data['supplier_batch_number'] ?? null) ? (string) $data['supplier_batch_number'] : null,
            stockedAt: (string) $data['stocked_at'],
            expiresAt: filled($data['expires_at'] ?? null) ? (string) $data['expires_at'] : null,
            notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
        );

        $this->showAppNotification(__('production_bench.inventory.lot_created', ['code' => $lot->internal_lot_code]));
    }

    private function activeSupplierListing(mixed $listingId): ?SupplierListing
    {
        if (! is_numeric($listingId)) {
            return null;
        }

        return SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('is_active', true)
            ->with(['supplier', 'ingredient.translations', 'packagingItem'])
            ->find((int) $listingId);
    }

    private function canAddStock(): bool
    {
        $workspace = $this->workspace();

        return $this->productionBenchAccess->isActive($workspace)
            && ! $this->productionBenchAccess->isReadOnly($workspace);
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        return collect($this->currencyCatalog->options(app()->getLocale(), [$this->workspace()->default_currency]))
            ->mapWithKeys(fn (string $name, string $code): array => [$code => $code.' · '.$name])
            ->all();
    }

    private function listingPricePerUnit(SupplierListing $listing, string $unit): string
    {
        if ($listing->unit_kind === StockUnitKind::Count) {
            $pricePerUnit = bcdiv($listing->total_price, $listing->canonical_quantity_per_purchase_format, 9);
        } else {
            $pricePerGram = bcdiv($listing->total_price, $listing->canonical_quantity_per_purchase_format, 12);
            $pricePerUnit = bcmul($pricePerGram, $this->massConverter->toGrams('1', $unit), 9);
        }

        return NumberLocale::formatAdaptiveDecimal(
            $pricePerUnit,
            minimumDecimals: 2,
            maximumDecimals: 4,
            locale: $this->user()->number_locale,
        );
    }

    private function selectedListingIsPackaging(mixed $listingId): bool
    {
        return $this->activeSupplierListing($listingId)?->unit_kind === StockUnitKind::Count;
    }

    private function supplierListingLabel(SupplierListing $listing): string
    {
        $subjectName = $listing->ingredient?->localizedDisplayName()
            ?? $listing->packagingItem?->name
            ?? __('production_bench.inventory.unknown_item');
        $materialCode = $listing->packagingItem?->material_code;

        return collect([
            $materialCode,
            $subjectName,
            $listing->supplier->name,
            $listing->supplier_sku,
            $listing->purchase_format,
        ])->filter()->join(' · ');
    }

    /** @return array<string, string> */
    private function unitOptions(mixed $listingId): array
    {
        if ($this->selectedListingIsPackaging($listingId)) {
            return ['count' => __('production_bench.inventory.units')];
        }

        return ['g' => 'g', 'kg' => 'kg', 'oz' => 'oz', 'lb' => 'lb'];
    }

    private function user(): User
    {
        return auth()->user() ?? abort(401);
    }

    private function workspace(): Workspace
    {
        return $this->user()->company() ?? abort(404);
    }
}
