<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\DeleteSupplierListing;
use App\Actions\Purchasing\SaveSupplierListing;
use App\DecimalStringFormatter;
use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\OrganicStatus;
use App\OwnerType;
use App\Services\CurrencyCatalog;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\Support\LocalizedDecimalInput;
use App\Support\NumberLocale;
use App\Visibility;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SupplierListingCreate extends Component implements HasForms
{
    use InteractsWithForms;
    use RestrictsFileUploadsToSchemaComponents;

    private const OptionLimit = 20;

    private const InitialOptionLimit = 10;

    private CurrencyCatalog $currencyCatalog;

    #[Locked]
    public ?string $lockedSupplierPublicId = null;

    #[Locked]
    public ?string $editingListingPublicId = null;

    /** @var array<int, string> */
    #[Locked]
    public array $supplierOptionLabels = [];

    /** @var array<int, string> */
    #[Locked]
    public array $supplierOptionPublicIds = [];

    /** @var array<int, string> */
    #[Locked]
    public array $ingredientOptionLabels = [];

    /** @var array<int, string> */
    #[Locked]
    public array $packagingOptionLabels = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(
        ProductionBenchAccess $access,
        string|Supplier|null $supplier = null,
        string|SupplierListing|null $listing = null,
    ): void {
        $this->assertPageIsWritable($access);
        $workspace = $this->workspace();
        $editingListing = $this->resolveEditingListing($listing);
        $lockedSupplier = null;

        $supplier = $editingListing?->supplier
            ?? $supplier
            ?? (is_string(request()->query('supplier')) ? request()->query('supplier') : null);

        if ($supplier !== null) {
            $supplierPublicId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
            $lockedSupplier = $this->workspaceSupplierByPublicId($supplierPublicId);
            $this->lockedSupplierPublicId = $lockedSupplier->public_id;
            $this->supplierOptionLabels[$lockedSupplier->id] = $this->supplierLabel($lockedSupplier);
            $this->supplierOptionPublicIds[$lockedSupplier->id] = $lockedSupplier->public_id;
        } else {
            $this->supplierSearchResults('');
        }

        $displayUnit = $workspace->mass_display_system->priceUnit()->value;
        $materialType = $editingListing instanceof SupplierListing
            ? ($editingListing->ingredient_id === null ? 'packaging' : 'ingredient')
            : (in_array(request()->query('material_type'), ['ingredient', 'packaging'], true)
                ? request()->query('material_type')
                : 'ingredient');
        $ingredient = $editingListing?->ingredient
            ?? (is_string(request()->query('ingredient'))
                ? $this->availableIngredientQuery()->where('public_id', request()->query('ingredient'))->first()
                : null);
        $packagingItem = $editingListing?->packagingItem
            ?? (is_string(request()->query('packaging_item'))
                ? PackagingItem::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('public_id', request()->query('packaging_item'))
                    ->first()
                : null);

        if ($ingredient instanceof Ingredient) {
            $materialType = 'ingredient';
            $this->ingredientOptionLabels[$ingredient->id] = $ingredient->localizedDisplayName();
        }

        if ($packagingItem instanceof PackagingItem) {
            $materialType = 'packaging';
            $this->packagingOptionLabels[$packagingItem->id] = $packagingItem->name;
        }

        $this->ingredientOptionLabels = array_replace($this->initialIngredientOptions(), $this->ingredientOptionLabels);
        $this->packagingOptionLabels = array_replace($this->initialPackagingOptions(), $this->packagingOptionLabels);

        $unit = $materialType === 'packaging' ? 'count' : $displayUnit;
        $this->form->fill([
            'supplier_id' => $lockedSupplier?->id,
            'material_type' => $materialType,
            'ingredient_id' => $ingredient?->id,
            'packaging_item_id' => $packagingItem?->id,
            'supplier_sku' => $editingListing?->supplier_sku,
            'supplier_item_name' => $editingListing?->supplier_item_name,
            'purchase_format' => $editingListing?->purchase_format,
            'net_quantity' => $this->editableDecimal(
                $editingListing?->net_quantity,
                minimumDecimals: $materialType === 'packaging' ? 0 : 2,
            ),
            'net_unit' => $editingListing?->net_unit ?? $unit,
            'price_basis' => $editingListing?->price_basis->value ?? ListingPriceBasis::PerUnit->value,
            'price_amount' => $this->editableDecimal($editingListing?->price_amount),
            'price_unit' => $editingListing?->price_unit ?? $unit,
            'currency' => $editingListing?->currency ?? $this->newListingCurrency($lockedSupplier),
            'minimum_packs' => $editingListing?->minimum_packs ?? 1,
            'organic_status' => $editingListing?->organic_status->value ?? OrganicStatus::Unknown->value,
            'notes' => $editingListing?->notes,
            'is_active' => $editingListing?->is_active ?? true,
        ]);
    }

    public function save(SaveSupplierListing $saveSupplierListing): void
    {
        if ($this->lockedSupplierPublicId !== null) {
            $this->data['supplier_id'] = $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)->id;
        }

        /** @var array<string, mixed> $state */
        $state = $this->form->getState();
        $supplier = $this->selectedSupplier($state);
        $subject = $this->selectedSubject($state);

        if (! $supplier instanceof Supplier || (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem)) {
            return;
        }

        try {
            $saveSupplierListing->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                supplier: $supplier,
                subject: $subject,
                attributes: $this->listingAttributes($state),
                listing: $this->editingListing(),
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception, (string) $state['material_type']);

            return;
        }

        if ($this->lockedSupplierPublicId !== null) {
            $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $supplier], navigate: true);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.listings', navigate: true);
    }

    public function delete(DeleteSupplierListing $deleteSupplierListing): void
    {
        $listing = $this->editingListing();

        if (! $listing instanceof SupplierListing) {
            abort(404);
        }

        $supplier = $listing->supplier;
        $deleted = $deleteSupplierListing->handle($this->user(), $this->workspace(), $listing);

        session()->flash(
            'status',
            $deleted ? __('production_bench.listing.deleted') : __('production_bench.listing.deactivated'),
        );

        $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $supplier], navigate: true);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('production_bench.supplier.singular'))->compact()->schema([
                    Select::make('supplier_id')
                        ->label(__('production_bench.filters.supplier'))
                        ->options(fn (): array => $this->supplierOptions())
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->supplierSearchResults($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->supplierOptionLabel((int) $value))
                        ->required()
                        ->disabled($this->lockedSupplierPublicId !== null)
                        ->dehydrated()
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $supplier = $this->workspaceSupplierById(is_numeric($state) ? (int) $state : null);

                            if ($supplier instanceof Supplier) {
                                $this->supplierOptionLabels[$supplier->id] = $this->supplierLabel($supplier);
                                $set('currency', $this->newListingCurrency($supplier));
                            }
                        }),
                ]),
                Section::make(__('production_bench.listing.catalog_item'))
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        Radio::make('material_type')
                            ->label(__('production_bench.filters.type'))
                            ->options(['ingredient' => __('production_bench.listing.ingredient'), 'packaging' => __('production_bench.listing.packaging_item')])
                            ->inline()
                            ->required()
                            ->disabled($this->isEditing())
                            ->dehydrated()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set): void {
                                $set('ingredient_id', null);
                                $set('packaging_item_id', null);
                                $unit = $state === 'packaging'
                                    ? 'count'
                                    : $this->workspace()->mass_display_system->priceUnit()->value;
                                $set('net_unit', $unit);
                                $set('price_unit', ($this->data['price_basis'] ?? null) === ListingPriceBasis::PerUnit->value ? $unit : null);
                            })
                            ->columnSpanFull(),
                        Select::make('ingredient_id')
                            ->label(__('production_bench.listing.ingredient'))
                            ->options(fn (): array => $this->ingredientOptions())
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->ingredientSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->ingredientOptionLabel((int) $value))
                            ->afterStateUpdated(fn (mixed $state): ?string => is_numeric($state) ? $this->ingredientOptionLabel((int) $state) : null)
                            ->required(fn (Get $get): bool => $get('material_type') === 'ingredient')
                            ->disabled($this->isEditing())
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => $get('material_type') === 'ingredient')
                            ->helperText(__('production_bench.listing.recent_items_help'))
                            ->columnSpanFull(),
                        Select::make('packaging_item_id')
                            ->label(__('production_bench.listing.packaging_item'))
                            ->options(fn (): array => $this->packagingOptions())
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->packagingSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->packagingOptionLabel((int) $value))
                            ->afterStateUpdated(fn (mixed $state): ?string => is_numeric($state) ? $this->packagingOptionLabel((int) $state) : null)
                            ->required(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->disabled($this->isEditing())
                            ->dehydrated()
                            ->visible(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->helperText(__('production_bench.listing.recent_items_help'))
                            ->columnSpanFull(),
                        SchemaView::make('livewire.production-bench.purchasing.catalog-item-create-link')
                            ->visible(fn (): bool => ! $this->isEditing())
                            ->columnSpanFull(),
                    ]),
                Section::make(__('production_bench.listing.purchase_format_section'))
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('supplier_sku')->label(__('production_bench.listing.supplier_sku'))->maxLength(255),
                        TextInput::make('supplier_item_name')->label(__('production_bench.listing.supplier_item_name'))->maxLength(255),
                        TextInput::make('purchase_format')->label(__('production_bench.listing.purchase_format'))->placeholder(__('production_bench.listing.purchase_format_placeholder'))->required()->maxLength(255)->columnSpanFull(),
                        LocalizedDecimalInput::make('net_quantity')
                            ->label(__('production_bench.listing.net_quantity'))
                            ->required()
                            ->minValue('0.000000001')
                            ->mutateStateForValidationUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->dehydrateStateUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->live(onBlur: true),
                        Select::make('net_unit')
                            ->label(__('production_bench.listing.unit_of_measure'))
                            ->options(fn (Get $get): array => $get('material_type') === 'packaging' ? ['count' => 'count'] : $this->massUnitOptions())
                            ->required()
                            ->disabled(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->dehydrated()
                            ->live(),
                        Select::make('organic_status')
                            ->label(__('production_bench.listing.organic_status'))
                            ->options([
                                OrganicStatus::Unknown->value => __('production_bench.listing.organic_unknown'),
                                OrganicStatus::Conventional->value => __('production_bench.listing.organic_conventional'),
                                OrganicStatus::Organic->value => __('production_bench.listing.organic_organic'),
                            ])
                            ->default(OrganicStatus::Unknown->value)
                            ->visible(fn (Get $get): bool => $get('material_type') === 'ingredient'),
                    ]),
                Section::make(__('production_bench.listing.pricing'))
                    ->compact()
                    ->columns(['md' => 3])
                    ->schema([
                        Radio::make('price_basis')
                            ->label(__('production_bench.listing.pricing_basis'))
                            ->options([
                                ListingPriceBasis::PerUnit->value => __('production_bench.listing.price_per_unit'),
                                ListingPriceBasis::TotalPurchaseFormat->value => __('production_bench.listing.total_purchase_format_price'),
                            ])
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                                $set('price_unit', $state === ListingPriceBasis::PerUnit->value ? $get('net_unit') : null);
                            })
                            ->columnSpanFull(),
                        LocalizedDecimalInput::make('price_amount')
                            ->label(__('production_bench.listing.price'))
                            ->required()
                            ->minValue('0.000000001')
                            ->mutateStateForValidationUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->dehydrateStateUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->live(onBlur: true),
                        Select::make('price_unit')
                            ->label(__('production_bench.listing.price_unit'))
                            ->options(fn (Get $get): array => $get('material_type') === 'packaging' ? ['count' => 'count'] : $this->massUnitOptions())
                            ->required(fn (Get $get): bool => $get('price_basis') === ListingPriceBasis::PerUnit->value)
                            ->visible(fn (Get $get): bool => $get('price_basis') === ListingPriceBasis::PerUnit->value)
                            ->disabled(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->dehydrated()
                            ->live(),
                        Select::make('currency')
                            ->label(__('production_bench.common.currency'))
                            ->options($this->currencyOptions())
                            ->searchable()
                            ->required()
                            ->live(),
                        TextEntry::make('price_preview')
                            ->label(__('production_bench.listing.calculated_price'))
                            ->state(fn (): string => $this->pricePreviewText())
                            ->columnSpanFull(),
                    ]),
                Section::make(__('production_bench.listing.ordering'))
                    ->compact()
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('minimum_packs')->label(__('production_bench.listing.minimum_order'))->helperText(__('production_bench.listing.minimum_order_help'))->integer()->minValue(1)->required(),
                        Toggle::make('is_active')->label(__('production_bench.common.active'))->default(true),
                        Textarea::make('notes')->label(__('production_bench.common.notes'))->rows(4)->columnSpanFull(),
                    ]),
            ])
            ->statePath('data')
            ->model(SupplierListing::class);
    }

    public function render(): View
    {
        return view('livewire.production-bench.purchasing.supplier-listing-create', [
            'lockedSupplier' => $this->lockedSupplierPublicId === null ? null : $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId),
        ]);
    }

    /** @return array<int, string> */
    public function supplierSearchResults(string $search): array
    {
        $suppliers = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when($this->normalizedSearch($search) !== '', function (Builder $query) use ($search): void {
                $search = $this->normalizedSearch($search);
                $query->where(fn (Builder $nested): Builder => $nested->whereLike('code', "%{$search}%")->orWhereLike('name', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(self::OptionLimit)
            ->get(['id', 'public_id', 'code', 'name']);
        $results = $suppliers
            ->mapWithKeys(fn (Supplier $supplier): array => [$supplier->id => $this->supplierLabel($supplier)])
            ->all();

        $this->supplierOptionLabels = array_replace($this->supplierOptionLabels, $results);
        $this->supplierOptionPublicIds = array_replace(
            $this->supplierOptionPublicIds,
            $suppliers->mapWithKeys(fn (Supplier $supplier): array => [$supplier->id => $supplier->public_id])->all(),
        );

        return $results;
    }

    /** @return array<int, string> */
    public function ingredientSearchResults(string $search): array
    {
        if (($this->data['material_type'] ?? 'ingredient') !== 'ingredient') {
            return [];
        }

        $search = $this->normalizedSearch($search);

        $results = $this->availableIngredientQuery()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested
                ->whereLike('display_name', "%{$search}%")
                ->orWhereLike('source_key', "%{$search}%")
                ->orWhereLike('inci_name', "%{$search}%")
                ->orWhereHas('translations', fn (Builder $translation): Builder => $translation->whereLike('display_name', "%{$search}%"))))
            ->orderBy('display_name')
            ->limit(self::OptionLimit)
            ->get()
            ->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->id => $this->ingredientLabel($ingredient)])
            ->all();

        $this->ingredientOptionLabels = array_replace($this->ingredientOptionLabels, $results);

        return $results;
    }

    /** @return array<int, string> */
    public function packagingSearchResults(string $search): array
    {
        if (($this->data['material_type'] ?? 'ingredient') !== 'packaging') {
            return [];
        }

        $search = $this->normalizedSearch($search);

        $results = PackagingItem::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when($search !== '', fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->limit(self::OptionLimit)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (PackagingItem $item): array => [$item->id => $item->name])
            ->all();

        $this->packagingOptionLabels = array_replace($this->packagingOptionLabels, $results);

        return $results;
    }

    public function supplierOptionLabel(int $id): ?string
    {
        if (isset($this->supplierOptionLabels[$id])) {
            return $this->supplierOptionLabels[$id];
        }

        $supplier = $this->workspaceSupplierById($id);

        if (! $supplier instanceof Supplier) {
            return null;
        }

        return $this->supplierOptionLabels[$id] = $this->supplierLabel($supplier);
    }

    public function ingredientOptionLabel(int $id): ?string
    {
        if (isset($this->ingredientOptionLabels[$id])) {
            return $this->ingredientOptionLabels[$id];
        }

        $ingredient = $this->availableIngredientQuery()->find($id);

        if (! $ingredient instanceof Ingredient) {
            return null;
        }

        return $this->ingredientOptionLabels[$id] = $this->ingredientLabel($ingredient);
    }

    public function packagingOptionLabel(int $id): ?string
    {
        if (isset($this->packagingOptionLabels[$id])) {
            return $this->packagingOptionLabels[$id];
        }

        $label = PackagingItem::query()
            ->where('workspace_id', $this->workspace()->id)
            ->find($id)?->name;

        if ($label === null) {
            return null;
        }

        return $this->packagingOptionLabels[$id] = $label;
    }

    /** @param array<string, mixed> $state */
    private function selectedSupplier(array $state): ?Supplier
    {
        $supplier = $this->lockedSupplierPublicId !== null
            ? $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)
            : $this->workspaceSupplierById(isset($state['supplier_id']) ? (int) $state['supplier_id'] : null);

        if (! $supplier instanceof Supplier) {
            $this->addError('data.supplier_id', __('production_bench.listing.choose_supplier'));
        }

        return $supplier;
    }

    /** @param array<string, mixed> $state */
    private function selectedSubject(array $state): Ingredient|PackagingItem|null
    {
        if (($state['material_type'] ?? null) === 'packaging') {
            $item = PackagingItem::query()
                ->where('workspace_id', $this->workspace()->id)
                ->find($state['packaging_item_id'] ?? null);

            if (! $item instanceof PackagingItem) {
                $this->addError('data.packaging_item_id', __('production_bench.listing.choose_packaging'));
            }

            return $item;
        }

        $ingredient = $this->availableIngredientQuery()->find($state['ingredient_id'] ?? null);

        if (! $ingredient instanceof Ingredient) {
            $this->addError('data.ingredient_id', __('production_bench.listing.choose_ingredient'));
        }

        return $ingredient;
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function listingAttributes(array $state): array
    {
        $isPackaging = $state['material_type'] === 'packaging';
        $basis = ListingPriceBasis::from($state['price_basis']);

        return [
            'supplier_sku' => $state['supplier_sku'] ?? null,
            'supplier_item_name' => $state['supplier_item_name'] ?? null,
            'purchase_format' => $state['purchase_format'],
            'container' => null,
            'net_quantity' => (string) $state['net_quantity'],
            'net_unit' => $isPackaging ? 'count' : $state['net_unit'],
            'price_basis' => $basis,
            'price_amount' => (string) $state['price_amount'],
            'price_unit' => $basis === ListingPriceBasis::TotalPurchaseFormat ? null : ($isPackaging ? 'count' : $state['price_unit']),
            'currency' => Str::upper(trim((string) $state['currency'])),
            'minimum_packs' => $state['minimum_packs'],
            'organic_status' => $isPackaging
                ? OrganicStatus::Unknown->value
                : ($state['organic_status'] ?? OrganicStatus::Unknown->value),
            'notes' => $state['notes'] ?? null,
            'is_active' => $state['is_active'],
        ];
    }

    private function availableIngredientQuery(): Builder
    {
        $user = $this->user();
        $workspace = $this->workspace();
        $localeCandidates = Ingredient::translationLocaleCandidates();

        return Ingredient::query()
            ->with(['translations' => fn ($query) => $query->whereIn('locale', $localeCandidates)])
            ->where('is_active', true)
            ->accessibleTo($user)
            ->where(function (Builder $query) use ($user, $workspace): void {
                $query->where('visibility', Visibility::Public->value)
                    ->orWhereNull('owner_type')
                    ->orWhere(fn (Builder $owned): Builder => $owned->where('owner_type', OwnerType::User->value)->where('owner_id', $user->id))
                    ->orWhere('workspace_id', $workspace->id)
                    ->orWhere(fn (Builder $owned): Builder => $owned->where('owner_type', OwnerType::Workspace->value)->where('owner_id', $workspace->id));
            });
    }

    private function workspaceSupplierById(?int $id): ?Supplier
    {
        return $id === null ? null : Supplier::query()->where('workspace_id', $this->workspace()->id)->find($id);
    }

    private function workspaceSupplierByPublicId(string $publicId): Supplier
    {
        return Supplier::query()->where('workspace_id', $this->workspace()->id)->where('public_id', $publicId)->firstOrFail();
    }

    private function resolveEditingListing(string|SupplierListing|null $listing): ?SupplierListing
    {
        if ($listing === null) {
            return null;
        }

        $publicId = $listing instanceof SupplierListing ? $listing->public_id : $listing;
        $editingListing = SupplierListing::query()
            ->with(['supplier', 'ingredient', 'packagingItem'])
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $this->editingListingPublicId = $editingListing->public_id;

        return $editingListing;
    }

    private function editingListing(): ?SupplierListing
    {
        if ($this->editingListingPublicId === null) {
            return null;
        }

        return SupplierListing::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $this->editingListingPublicId)
            ->firstOrFail();
    }

    private function isEditing(): bool
    {
        return $this->editingListingPublicId !== null;
    }

    /** @return array<int, string> */
    private function supplierOptions(): array
    {
        return $this->supplierOptionLabels;
    }

    /** @return array<int, string> */
    private function ingredientOptions(): array
    {
        return $this->ingredientOptionLabels;
    }

    /** @return array<int, string> */
    private function packagingOptions(): array
    {
        return $this->packagingOptionLabels;
    }

    /** @return array<int, string> */
    private function initialIngredientOptions(): array
    {
        $lastListingAt = SupplierListing::query()
            ->select('updated_at')
            ->whereColumn('supplier_listings.ingredient_id', 'ingredients.id')
            ->where('supplier_listings.workspace_id', $this->workspace()->id)
            ->latest('updated_at')
            ->limit(1);

        return $this->availableIngredientQuery()
            ->select('ingredients.*')
            ->addSelect(['last_listing_at' => $lastListingAt])
            ->orderByRaw('"last_listing_at" DESC NULLS LAST')
            ->orderByDesc('ingredients.created_at')
            ->orderByDesc('ingredients.id')
            ->limit(self::InitialOptionLimit)
            ->get()
            ->mapWithKeys(fn (Ingredient $ingredient): array => [$ingredient->id => $this->ingredientLabel($ingredient)])
            ->all();
    }

    /** @return array<int, string> */
    private function initialPackagingOptions(): array
    {
        $lastListingAt = SupplierListing::query()
            ->select('updated_at')
            ->whereColumn('supplier_listings.packaging_item_id', 'packaging_items.id')
            ->where('supplier_listings.workspace_id', $this->workspace()->id)
            ->latest('updated_at')
            ->limit(1);

        return PackagingItem::query()
            ->where('workspace_id', $this->workspace()->id)
            ->select('packaging_items.*')
            ->addSelect(['last_listing_at' => $lastListingAt])
            ->orderByRaw('"last_listing_at" DESC NULLS LAST')
            ->orderByDesc('packaging_items.created_at')
            ->orderByDesc('packaging_items.id')
            ->limit(self::InitialOptionLimit)
            ->get()
            ->mapWithKeys(fn (PackagingItem $item): array => [$item->id => $item->name])
            ->all();
    }

    public function catalogCreationSupplierPublicId(): ?string
    {
        if ($this->lockedSupplierPublicId !== null) {
            return $this->lockedSupplierPublicId;
        }

        $supplierId = $this->data['supplier_id'] ?? null;

        return is_numeric($supplierId) ? ($this->supplierOptionPublicIds[(int) $supplierId] ?? null) : null;
    }

    private function supplierLabel(Supplier $supplier): string
    {
        return $supplier->code.' · '.$supplier->name;
    }

    private function ingredientLabel(Ingredient $ingredient): string
    {
        return $ingredient->localizedDisplayName() ?? $ingredient->display_name ?? $ingredient->source_key;
    }

    private function normalizedSearch(string $search): string
    {
        return Str::limit(trim($search), 100, '');
    }

    private function newListingCurrency(?Supplier $supplier = null): string
    {
        foreach ([$supplier?->default_currency, $this->workspace()->default_currency] as $currency) {
            if (is_string($currency) && $this->currencyCatalog->isSelectable($currency)) {
                return Str::upper($currency);
            }
        }

        return '';
    }

    /** @return array<string, string> */
    private function currencyOptions(): array
    {
        return collect($this->currencyCatalog->options(app()->getLocale()))
            ->mapWithKeys(fn (string $name, string $code): array => [$code => $code.' · '.$name])
            ->all();
    }

    /** @return array<string, string> */
    private function massUnitOptions(): array
    {
        return ['g' => 'g', 'kg' => 'kg', 'oz' => 'oz', 'lb' => 'lb'];
    }

    private function pricePreviewText(): string
    {
        $preview = $this->pricePreview();

        return $preview === null ? '—' : $preview['unit'].' · '.$preview['total'];
    }

    /** @return array{unit: string, total: string}|null */
    private function pricePreview(): ?array
    {
        $quantity = (string) ($this->normalizedLocalizedDecimal($this->data['net_quantity'] ?? '') ?? '');
        $amount = (string) ($this->normalizedLocalizedDecimal($this->data['price_amount'] ?? '') ?? '');
        $basis = ListingPriceBasis::tryFrom((string) ($this->data['price_basis'] ?? ''));

        if ($quantity === '' || $amount === '' || ! $basis instanceof ListingPriceBasis) {
            return null;
        }

        try {
            $calculator = app(SupplierListingPriceCalculator::class);
            $formatter = app(DecimalStringFormatter::class);
            $currency = (string) ($this->data['currency'] ?? '');

            if (($this->data['material_type'] ?? null) === 'packaging') {
                $prices = $calculator->forCount($quantity, $basis, $amount);

                return [
                    'unit' => $currency.' '.$formatter->toFixed($prices['price_per_item']).' / item',
                    'total' => $currency.' '.$formatter->toFixed($prices['total_price']).' total',
                ];
            }

            $prices = $calculator->forMass(
                $quantity,
                (string) ($this->data['net_unit'] ?? ''),
                $basis,
                $amount,
                $basis === ListingPriceBasis::TotalPurchaseFormat ? null : (string) ($this->data['price_unit'] ?? ''),
            );
            $displayUnit = $this->workspace()->mass_display_system->priceUnit();
            $pricePerDisplayUnit = bcmul(
                bcdiv($prices['total_price'], $prices['canonical_quantity'], 18),
                app(MassConverter::class)->toGrams('1', $displayUnit),
                18,
            );

            return [
                'unit' => $currency.' '.$formatter->toFixed($pricePerDisplayUnit).' / '.$displayUnit->value,
                'total' => $currency.' '.$formatter->toFixed($prices['total_price']).' total',
            ];
        } catch (ValidationException) {
            return null;
        }
    }

    private function surfaceValidationErrors(ValidationException $exception, string $materialType): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $formField = match ($field) {
                'supplier' => 'supplier_id',
                'subject' => $materialType === 'packaging' ? 'packaging_item_id' : 'ingredient_id',
                'packaging_item' => 'packaging_item_id',
                default => $field,
            };

            foreach ($messages as $message) {
                $this->addError('data.'.$formField, $message);
            }
        }
    }

    private function normalizedLocalizedDecimal(mixed $state): mixed
    {
        if (blank($state)) {
            return null;
        }

        $normalized = preg_replace('/[\s\x{00a0}\x{202f}]/u', '', trim((string) $state));

        if (! is_string($normalized) || $normalized === '') {
            return $state;
        }

        $commaPosition = strrpos($normalized, ',');
        $dotPosition = strrpos($normalized, '.');

        if ($commaPosition !== false && $dotPosition !== false) {
            $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
            $groupingSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($groupingSeparator, '', $normalized);
            $normalized = str_replace($decimalSeparator, '.', $normalized);
        } elseif ($commaPosition !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        return is_numeric($normalized) ? $normalized : $state;
    }

    private function editableDecimal(?string $value, int $minimumDecimals = 2): ?string
    {
        if ($value === null) {
            return $value;
        }

        return NumberLocale::formatAdaptiveDecimal(
            $value,
            minimumDecimals: $minimumDecimals,
            maximumDecimals: 9,
            locale: $this->user()->number_locale,
        );
    }

    private function assertPageIsWritable(ProductionBenchAccess $access): void
    {
        try {
            $access->assertWritable($this->user(), $this->workspace());
        } catch (ValidationException) {
            abort(403);
        }
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
