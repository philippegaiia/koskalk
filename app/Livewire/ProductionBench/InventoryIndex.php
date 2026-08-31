<?php

namespace App\Livewire\ProductionBench;

use App\Actions\Inventory\CreateOpeningStockLot;
use App\Actions\Inventory\QuarantineStockLot;
use App\Actions\Inventory\ReleaseStockLot;
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Enums\StockLotOrigin;
use App\Enums\StockLotStatus;
use App\Enums\StockReservationStatus;
use App\Enums\StockUnitKind;
use App\Livewire\Concerns\InteractsWithAppNotifications;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StockLot;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CurrencyCatalog;
use App\Services\Inventory\WorkspaceMaterialInventoryQuery;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\StockPositionService;
use App\Support\LocalizedDecimalInput;
use App\Support\NumberLocale;
use Carbon\CarbonImmutable;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
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

    private CreateOpeningStockLot $createOpeningStockLot;

    private CurrencyCatalog $currencyCatalog;

    private MassConverter $massConverter;

    private ProductionBenchAccess $productionBenchAccess;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: 'all')]
    public string $materialType = 'all';

    #[Url(as: 'state', except: 'all')]
    public string $stockState = 'all';

    #[Url(as: 'demand', except: 'all')]
    public string $demandFilter = 'all';

    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    #[Url(as: 'subcategory', except: '')]
    public string $subcategoryFilter = '';

    #[Url(as: 'sort', except: 'priority')]
    public string $sort = 'priority';

    #[Url(as: 'direction', except: 'asc')]
    public string $direction = 'asc';

    #[Url(as: 'material', except: '')]
    public string $lotMaterial = '';

    #[Url(as: 'material_type', except: '')]
    public string $lotMaterialType = '';

    #[Url(as: 'lot_scope', except: 'open')]
    public string $lotScope = 'open';

    #[Url(as: 'status', except: 'all')]
    public string $lotStatus = 'all';

    #[Url(as: 'supplier', except: '')]
    public string $lotSupplier = '';

    #[Url(as: 'origin', except: '')]
    public string $lotOrigin = '';

    #[Url(as: 'stocked_from', except: '')]
    public string $lotStockedFrom = '';

    #[Url(as: 'stocked_until', except: '')]
    public string $lotStockedUntil = '';

    #[Url(as: 'expiry', except: 'all')]
    public string $lotExpiry = 'all';

    #[Url(as: 'lot_sort', except: 'newest')]
    public string $lotSort = 'newest';

    public string $mode = 'materials';

    /** @var array<string, mixed> */
    public array $lotFilters = [];

    public int $perPage = 25;

    public ?string $statusMessage = null;

    public string $statusType = 'idle';

    public function boot(
        CreateOpeningStockLot $createOpeningStockLot,
        CurrencyCatalog $currencyCatalog,
        MassConverter $massConverter,
        ProductionBenchAccess $productionBenchAccess,
    ): void {
        $this->createOpeningStockLot = $createOpeningStockLot;
        $this->currencyCatalog = $currencyCatalog;
        $this->massConverter = $massConverter;
        $this->productionBenchAccess = $productionBenchAccess;
    }

    public function mount(string $mode = 'materials'): void
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : 'materials';
        $this->normalizeFilterState();
        $this->lotFilters = [
            'lotMaterialSelection' => $this->lotMaterial !== ''
                ? $this->lotMaterialType.':'.$this->lotMaterial
                : null,
        ];
    }

    /**
     * Lot register filter schema. It currently carries only the material
     * combobox because that is the one control a plain `<select>` cannot
     * express: the catalogue is too large to render as options.
     */
    public function lotFiltersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('lotMaterialSelection')
                    ->label(__('production_bench.inventory.lot_material'))
                    ->placeholder(__('production_bench.inventory.lot_material_filter'))
                    ->searchable()
                    ->native(false)
                    ->getSearchResultsUsing(fn (string $search, WorkspaceMaterialInventoryQuery $inventoryQuery): array => $this->lotMaterialSearchResults($search, $inventoryQuery))
                    ->getOptionLabelUsing(fn (mixed $value, WorkspaceMaterialInventoryQuery $inventoryQuery): ?string => $this->lotMaterialSelectionLabel(is_string($value) ? $value : null, $inventoryQuery))
                    ->live()
                    ->afterStateUpdated(fn (?string $state, WorkspaceMaterialInventoryQuery $inventoryQuery) => $this->selectLotMaterial($state, $inventoryQuery)),
            ])
            ->statePath('lotFilters');
    }

    public function updatedSearch(): void
    {
        $this->resetInventoryPages();
    }

    public function updatedMaterialType(): void
    {
        $this->resetPage('materials');
    }

    public function updatedStockState(): void
    {
        $this->resetPage('materials');
    }

    public function updatedDemandFilter(): void
    {
        $this->resetPage('materials');
    }

    public function updatedCategoryFilter(): void
    {
        $this->subcategoryFilter = '';
        $this->resetPage('materials');
    }

    public function updatedSubcategoryFilter(): void
    {
        $this->resetPage('materials');
    }

    public function updatedSort(): void
    {
        $this->resetPage('materials');
    }

    public function updatedDirection(): void
    {
        $this->resetPage('materials');
    }

    public function updatedLotMaterial(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotMaterialType(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotScope(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotStatus(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotSupplier(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotOrigin(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotStockedFrom(): void
    {
        // Lot dates reach whereDate() without an allow-list, so they are the one
        // filter that must be re-validated on update, not only at mount.
        $this->lotStockedFrom = $this->normalizeLotDate($this->lotStockedFrom);
        $this->resetPage('stock-lots');
    }

    public function updatedLotStockedUntil(): void
    {
        $this->lotStockedUntil = $this->normalizeLotDate($this->lotStockedUntil);
        $this->resetPage('stock-lots');
    }

    public function updatedLotExpiry(): void
    {
        $this->resetPage('stock-lots');
    }

    public function updatedLotSort(): void
    {
        $this->resetPage('stock-lots');
    }

    /**
     * The shortage tile is a shortcut into the same state the filter panel
     * exposes: the design calls for the negative-forecast summary to be able to
     * activate that filter directly. Toggling rather than only applying keeps
     * the tile usable as an "off" switch once the filter is on, which matters
     * because the tile stays visible while the filter narrows the table.
     */
    public function toggleShortageFilter(): void
    {
        $this->stockState = $this->stockState === 'negative_forecast' ? 'all' : 'negative_forecast';
        $this->resetPage('materials');
    }

    /**
     * Bounded option source for the Lot register material combobox.
     *
     * @return array<string, string>
     */
    public function lotMaterialSearchResults(string $search, WorkspaceMaterialInventoryQuery $inventoryQuery): array
    {
        return $inventoryQuery->materialOptions($this->workspace(), trim($search), self::OPTION_LIMIT);
    }

    /**
     * Label for the currently held combobox value, resolved from the value
     * itself rather than from component state so a stale selection cannot
     * render another material's name.
     */
    public function lotMaterialSelectionLabel(?string $selection, WorkspaceMaterialInventoryQuery $inventoryQuery): ?string
    {
        if (! is_string($selection) || ! str_contains($selection, ':')) {
            return null;
        }

        [$type, $publicId] = explode(':', $selection, 2);

        if (! in_array($type, ['ingredient', 'packaging'], true)) {
            return null;
        }

        $subject = $inventoryQuery->resolveMaterialOption($this->workspace(), $type, $publicId);

        return $subject instanceof Ingredient
            ? (string) $subject->localizedDisplayName()
            : $subject?->name;
    }

    /**
     * Applies a compound `type:public_id` selection from the material combobox.
     *
     * `lotMaterial` and `lotMaterialType` stay the durable, URL-bound state: the
     * combobox is only a way to set them, which keeps bookmarked Lot register
     * URLs working exactly as they did when the filter was driven by a link.
     */
    public function selectLotMaterial(?string $selection, WorkspaceMaterialInventoryQuery $inventoryQuery): void
    {
        if (! is_string($selection) || ! str_contains($selection, ':')) {
            $this->clearLotMaterial();

            return;
        }

        [$type, $publicId] = explode(':', $selection, 2);

        abort_unless(in_array($type, ['ingredient', 'packaging'], true), 422);

        $subject = $inventoryQuery->resolveMaterialOption($this->workspace(), $type, $publicId);

        abort_unless($subject instanceof Ingredient || $subject instanceof PackagingItem, 404);

        $this->lotMaterialType = $type;
        $this->lotMaterial = $publicId;
        $this->resetPage('stock-lots');
    }

    public function clearLotMaterial(): void
    {
        $this->lotMaterial = '';
        $this->lotMaterialType = '';
        $this->lotFilters['lotMaterialSelection'] = null;
        $this->resetPage('stock-lots');
    }

    public function clearMaterialFilters(): void
    {
        $this->search = '';
        $this->materialType = 'all';
        $this->stockState = 'all';
        $this->demandFilter = 'all';
        $this->categoryFilter = '';
        $this->subcategoryFilter = '';
        $this->sort = 'priority';
        $this->direction = 'asc';
        $this->resetPage('materials');
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
        WorkspaceMaterialInventoryQuery $inventoryQuery,
    ): View {
        $workspace = $this->workspace();
        $displayUnit = $workspace->mass_display_system->priceUnit()->value;
        $materialFilters = $this->materialFilters();
        $materialPage = $this->mode === 'materials'
            ? $inventoryQuery->paginate($workspace, $materialFilters, $this->normalizedPerPage(), 'materials')
            : null;

        if ($materialPage instanceof LengthAwarePaginator) {
            $materialPage = $this->formatMaterialPage($materialPage, $massConverter, $displayUnit);
        }

        return view('livewire.production-bench.inventory-index', [
            'workspace' => $workspace,
            'isActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'lots' => $this->mode === 'stock'
                ? $this->stockLots($workspace, $positions, $massConverter, $displayUnit)
                : collect(),
            'materials' => $materialPage,
            'inventorySummary' => $this->mode === 'materials'
                ? $inventoryQuery->summary($workspace, $materialFilters)
                : [],
            'materialFiltersActive' => $this->materialFiltersActive(),
            'categoryOptions' => IngredientCategory::options(),
            'subcategoryOptions' => IngredientSubcategory::optionsFor($this->categoryFilter),
            'categoryOptionsForCombobox' => $this->comboboxOptions(IngredientCategory::options()),
            'subcategoryOptionsForCombobox' => $this->comboboxOptions(IngredientSubcategory::optionsFor($this->categoryFilter)),
            'lotSupplierOptions' => $this->lotSupplierOptions($workspace),
            'lotOriginOptions' => $this->lotOriginOptions(),
            'lotMaterialLabel' => $this->lotMaterialLabel($workspace),
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
        $search = trim($this->search);
        $searchTerm = '%'.Str::lower($search).'%';
        $translationLocales = Ingredient::translationLocaleCandidates();
        $status = in_array($this->lotStatus, ['all', StockLotStatus::Released->value, StockLotStatus::Quarantined->value], true)
            ? $this->lotStatus
            : 'all';
        $scope = in_array($this->lotScope, ['open', 'exhausted', 'all'], true) ? $this->lotScope : 'open';
        $origin = array_key_exists($this->lotOrigin, $this->lotOriginOptions()) ? $this->lotOrigin : '';
        $physical = '(SELECT COALESCE(SUM(movements.quantity_delta), 0) FROM stock_movements AS movements WHERE movements.stock_lot_id = stock_lots.id)';
        $activeReserved = '(SELECT COALESCE(SUM(reservations.quantity), 0) FROM stock_reservations AS reservations WHERE reservations.stock_lot_id = stock_lots.id AND reservations.status = \'active\')';

        $stockLots = StockLot::query()
            ->where('workspace_id', $workspace->id)
            ->with([
                'ingredient.translations',
                'ingredient.workspaceCodes',
                'packagingItem',
                'goodsReceiptLine.goodsReceipt.supplier',
                'supplierListing.supplier',
            ])
            ->withSum('movements', 'quantity_delta')
            ->withSum([
                'reservations as active_reserved_quantity' => fn (Builder $query): Builder => $query->where('status', StockReservationStatus::Active),
            ], 'quantity')
            ->when($status !== 'all', fn (Builder $query): Builder => $query->where('status', $status))
            ->when($origin !== '', fn (Builder $query): Builder => $query->where('origin', $origin))
            ->when($scope === 'open', fn (Builder $query): Builder => $query
                ->whereRaw("({$physical} <> 0 OR {$activeReserved} <> 0)"))
            ->when($scope === 'exhausted', fn (Builder $query): Builder => $query
                ->whereRaw("{$physical} = 0")
                ->whereRaw("{$activeReserved} = 0"))
            ->when($this->lotStockedFrom !== '', fn (Builder $query): Builder => $query->whereDate('stocked_at', '>=', $this->lotStockedFrom))
            ->when($this->lotStockedUntil !== '', fn (Builder $query): Builder => $query->whereDate('stocked_at', '<=', $this->lotStockedUntil))
            ->when($this->lotExpiry === 'active', fn (Builder $query): Builder => $query->whereNotNull('expires_at')->whereDate('expires_at', '>=', today()))
            ->when($this->lotExpiry === 'expired', fn (Builder $query): Builder => $query->whereNotNull('expires_at')->whereDate('expires_at', '<', today()))
            ->when($this->lotExpiry === 'none', fn (Builder $query): Builder => $query->whereNull('expires_at'))
            ->when($this->lotMaterial !== '', function (Builder $query) use ($workspace): void {
                $query->where(function (Builder $materialQuery) use ($workspace): void {
                    if ($this->lotMaterialType === 'ingredient') {
                        $materialQuery->whereHas('ingredient', fn (Builder $ingredientQuery): Builder => $ingredientQuery->where('public_id', $this->lotMaterial));

                        return;
                    }

                    if ($this->lotMaterialType === 'packaging') {
                        $materialQuery->whereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->where('workspace_id', $workspace->id)
                            ->where('public_id', $this->lotMaterial));

                        return;
                    }

                    $materialQuery
                        ->whereHas('ingredient', fn (Builder $ingredientQuery): Builder => $ingredientQuery->where('public_id', $this->lotMaterial))
                        ->orWhereHas('packagingItem', fn (Builder $packagingQuery): Builder => $packagingQuery
                            ->where('workspace_id', $workspace->id)
                            ->where('public_id', $this->lotMaterial));
                });
            })
            ->when($this->lotSupplier !== '', function (Builder $query): void {
                $query->where(function (Builder $supplierQuery): void {
                    $supplierQuery
                        ->whereHas('supplierListing.supplier', fn (Builder $listingSupplierQuery): Builder => $listingSupplierQuery->where('public_id', $this->lotSupplier))
                        ->orWhereHas('goodsReceiptLine.goodsReceipt.supplier', fn (Builder $receiptSupplierQuery): Builder => $receiptSupplierQuery->where('public_id', $this->lotSupplier));
                });
            })
            ->when($search !== '', function (Builder $query) use ($searchTerm, $translationLocales, $workspace): void {
                $query->where(function (Builder $searchQuery) use ($searchTerm, $translationLocales, $workspace): void {
                    $searchQuery
                        ->whereRaw('LOWER(internal_lot_code) LIKE ?', [$searchTerm])
                        ->orWhereRaw('LOWER(supplier_batch_number) LIKE ?', [$searchTerm])
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
            ->when($this->lotSort === 'oldest', fn (Builder $query): Builder => $query->orderBy('stocked_at')->orderBy('id'))
            ->when($this->lotSort === 'code', fn (Builder $query): Builder => $query->orderBy('internal_lot_code')->orderBy('id'))
            ->when($this->lotSort === 'newest', fn (Builder $query): Builder => $query->latest('stocked_at')->latest('id'))
            ->paginate($this->normalizedPerPage(), ['*'], 'stock-lots');

        return $stockLots->through(function (StockLot $lot) use ($positions, $massConverter, $displayUnit): array {
            $stock = $positions->forLotWithLoadedMovementSum($lot);

            return [
                'lot' => $lot,
                // The register is the second way into a material, so each row
                // carries its own detail route rather than rebuilding it in Blade.
                'detail_url' => $this->lotMaterialDetailUrl($lot),
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
     * Null when the lot is held against a recipe, which has no inventory detail
     * page of its own.
     */
    private function lotMaterialDetailUrl(StockLot $lot): ?string
    {
        return match (true) {
            $lot->ingredient instanceof Ingredient => route('production-bench.inventory.material.ingredient', $lot->ingredient),
            $lot->packagingItem instanceof PackagingItem => route('production-bench.inventory.material.packaging', $lot->packagingItem),
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function materialFilters(): array
    {
        return [
            'search' => $this->search,
            'type' => $this->materialType,
            'stock_state' => $this->stockState,
            'demand' => $this->demandFilter,
            'category' => $this->categoryFilter,
            'subcategory' => $this->subcategoryFilter,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }

    private function materialFiltersActive(): bool
    {
        return trim($this->search) !== ''
            || $this->materialType !== 'all'
            || $this->stockState !== 'all'
            || $this->demandFilter !== 'all'
            || $this->categoryFilter !== ''
            || $this->subcategoryFilter !== '';
    }

    private function resetInventoryPages(): void
    {
        $this->resetPage('materials');
        $this->resetPage('stock-lots');
    }

    private function formatMaterialPage(LengthAwarePaginator $page, MassConverter $massConverter, string $displayUnit): LengthAwarePaginator
    {
        return $page->through(function (array $row) use ($massConverter, $displayUnit): array {
            $format = $row['display_unit'] === 'mass'
                ? fn (string $quantity): string => number_format((float) $massConverter->fromGramsSigned($quantity, $displayUnit), 2)
                : fn (string $quantity): string => number_format((float) $quantity, 0);

            foreach (['physical', 'available', 'reserved', 'quarantined', 'incoming', 'required', 'forecast'] as $position) {
                $row['positions'][$position] = $format($row['positions'][$position]);
                $row[$position] = $row['positions'][$position];
            }

            if ($row['buffer_quantity'] !== null) {
                $row['buffer_quantity'] = $format($row['buffer_quantity']);
            }

            $row['display_unit'] = $row['display_unit'] === 'mass'
                ? $displayUnit
                : __('production_bench.inventory.units');

            // Route construction stays out of Blade so the view never has to know
            // which subject type it is rendering.
            $row['detail_url'] = $row['subject'] instanceof Ingredient
                ? route('production-bench.inventory.material.ingredient', $row['subject'])
                : route('production-bench.inventory.material.packaging', $row['subject']);

            return $row;
        });
    }

    /** @param  array<string, string>  $options  @return array<int, array{id: string, label: string}> */
    private function comboboxOptions(array $options): array
    {
        return collect($options)
            ->map(fn (string $label, string $id): array => ['id' => $id, 'label' => $label])
            ->values()
            ->all();
    }

    private function normalizeFilterState(): void
    {
        $this->materialType = in_array($this->materialType, ['all', 'ingredient', 'packaging'], true)
            ? $this->materialType
            : 'all';
        $this->stockState = in_array($this->stockState, ['all', 'negative_forecast', 'below_buffer', 'quarantined', 'incoming'], true)
            ? $this->stockState
            : 'all';
        $this->demandFilter = in_array($this->demandFilter, ['all', 'planned', 'unplanned'], true)
            ? $this->demandFilter
            : 'all';
        $this->categoryFilter = IngredientCategory::tryFrom($this->categoryFilter)?->value ?? '';
        $subcategory = IngredientSubcategory::tryFrom($this->subcategoryFilter);
        $this->subcategoryFilter = $subcategory instanceof IngredientSubcategory
            && ($this->categoryFilter === '' || $subcategory->category()->value === $this->categoryFilter)
            ? $subcategory->value
            : '';
        $this->sort = in_array($this->sort, ['priority', 'name', 'physical', 'available', 'forecast'], true)
            ? $this->sort
            : 'priority';
        $this->direction = $this->direction === 'desc' ? 'desc' : 'asc';
        $this->lotMaterialType = in_array($this->lotMaterialType, ['', 'ingredient', 'packaging'], true)
            ? $this->lotMaterialType
            : '';
        // `public_id` is a uuid column, so PostgreSQL rejects a non-uuid value
        // outright instead of returning no rows. This filter arrives from the
        // URL, so it is normalized before it can reach any query. An unusable
        // id also clears the type: the two only ever mean anything together.
        if (! Str::isUuid($this->lotMaterial)) {
            $this->lotMaterial = '';
            $this->lotMaterialType = '';
        }
        $this->lotScope = in_array($this->lotScope, ['open', 'exhausted', 'all'], true) ? $this->lotScope : 'open';
        $this->lotStatus = in_array($this->lotStatus, ['all', StockLotStatus::Released->value, StockLotStatus::Quarantined->value], true)
            ? $this->lotStatus
            : 'all';
        $this->lotOrigin = array_key_exists($this->lotOrigin, $this->lotOriginOptions()) ? $this->lotOrigin : '';
        $this->lotStockedFrom = $this->normalizeLotDate($this->lotStockedFrom);
        $this->lotStockedUntil = $this->normalizeLotDate($this->lotStockedUntil);
        $this->lotExpiry = in_array($this->lotExpiry, ['all', 'active', 'expired', 'none'], true) ? $this->lotExpiry : 'all';
        $this->lotSort = in_array($this->lotSort, ['newest', 'oldest', 'code'], true) ? $this->lotSort : 'newest';
    }

    /** @return array<string, string> */
    private function lotSupplierOptions(Workspace $workspace): array
    {
        return Supplier::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['public_id', 'name'])
            ->mapWithKeys(fn (Supplier $supplier): array => [$supplier->public_id => $supplier->name])
            ->all();
    }

    /** @return array<string, string> */
    private function lotOriginOptions(): array
    {
        return collect(StockLotOrigin::cases())
            ->mapWithKeys(fn (StockLotOrigin $origin): array => [
                $origin->value => __('production_bench.inventory.origin_'.$origin->value),
            ])
            ->all();
    }

    private function lotMaterialLabel(Workspace $workspace): ?string
    {
        if ($this->lotMaterial === '') {
            return null;
        }

        if ($this->lotMaterialType === 'packaging') {
            return PackagingItem::query()
                ->where('workspace_id', $workspace->id)
                ->where('public_id', $this->lotMaterial)
                ->value('name');
        }

        $ingredient = Ingredient::query()->where('public_id', $this->lotMaterial)->first();

        if ($ingredient instanceof Ingredient) {
            return (string) $ingredient->localizedDisplayName();
        }

        return PackagingItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $this->lotMaterial)
            ->value('name');
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

    private function normalizeLotDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            return '';
        }

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date
            ? $date
            : '';
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
