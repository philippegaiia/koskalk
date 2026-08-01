<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplierListing;
use App\DecimalStringFormatter;
use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\CurrencyCatalog;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\Support\LocalizedDecimalInput;
use App\Visibility;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

    private CurrencyCatalog $currencyCatalog;

    #[Locked]
    public ?string $lockedSupplierPublicId = null;

    /** @var array<string, mixed> */
    public array $data = [];

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(ProductionBenchAccess $access, string|Supplier|null $supplier = null): void
    {
        $this->assertPageIsWritable($access);
        $workspace = $this->workspace();
        $lockedSupplier = null;

        if ($supplier !== null) {
            $supplierPublicId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
            $lockedSupplier = $this->workspaceSupplierByPublicId($supplierPublicId);
            $this->lockedSupplierPublicId = $lockedSupplier->public_id;
        }

        $displayUnit = $workspace->mass_display_system->priceUnit()->value;
        $this->form->fill([
            'supplier_id' => $lockedSupplier?->id,
            'material_type' => 'ingredient',
            'net_unit' => $displayUnit,
            'price_basis' => ListingPriceBasis::PerUnit->value,
            'price_unit' => $displayUnit,
            'currency' => $this->newListingCurrency($lockedSupplier),
            'minimum_packs' => 1,
            'is_active' => true,
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

        if (! $supplier instanceof Supplier || (! $subject instanceof Ingredient && ! $subject instanceof UserPackagingItem)) {
            return;
        }

        try {
            $saveSupplierListing->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                supplier: $supplier,
                subject: $subject,
                attributes: $this->listingAttributes($state),
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Supplier')->schema([
                    Select::make('supplier_id')
                        ->label('Supplier')
                        ->options(fn (): array => $this->lockedSupplierOptions())
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
                                $set('currency', $this->newListingCurrency($supplier));
                            }
                        }),
                ]),
                Section::make('Catalog item')
                    ->columns(['md' => 2])
                    ->schema([
                        Radio::make('material_type')
                            ->label('Type')
                            ->options(['ingredient' => 'Ingredient', 'packaging' => 'Packaging item'])
                            ->inline()
                            ->required()
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
                            ->label('Ingredient')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->ingredientSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->ingredientOptionLabel((int) $value))
                            ->required(fn (Get $get): bool => $get('material_type') === 'ingredient')
                            ->visible(fn (Get $get): bool => $get('material_type') === 'ingredient')
                            ->columnSpanFull(),
                        Select::make('packaging_item_id')
                            ->label('Packaging item')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->packagingSearchResults($search))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => $this->packagingOptionLabel((int) $value))
                            ->required(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->visible(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->columnSpanFull(),
                    ]),
                Section::make('Purchase format')
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('supplier_sku')->label('Supplier SKU')->maxLength(255),
                        TextInput::make('supplier_name')->label('Supplier item name')->maxLength(255),
                        TextInput::make('purchase_format')->label('Purchase format')->placeholder('200 kg drum')->required()->maxLength(255)->columnSpanFull(),
                        LocalizedDecimalInput::make('net_quantity')
                            ->label('Net quantity')
                            ->required()
                            ->minValue('0.000000001')
                            ->mutateStateForValidationUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->dehydrateStateUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->live(onBlur: true),
                        Select::make('net_unit')
                            ->label('Unit of measure')
                            ->options(fn (Get $get): array => $get('material_type') === 'packaging' ? ['count' => 'count'] : $this->massUnitOptions())
                            ->required()
                            ->disabled(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->dehydrated()
                            ->live(),
                    ]),
                Section::make('Pricing')
                    ->columns(['md' => 3])
                    ->schema([
                        Radio::make('price_basis')
                            ->label('Pricing basis')
                            ->options([
                                ListingPriceBasis::PerUnit->value => 'Price per unit of measure',
                                ListingPriceBasis::TotalPurchaseFormat->value => 'Total purchase-format price',
                            ])
                            ->inline()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                                $set('price_unit', $state === ListingPriceBasis::PerUnit->value ? $get('net_unit') : null);
                            })
                            ->columnSpanFull(),
                        LocalizedDecimalInput::make('price_amount')
                            ->label('Price')
                            ->required()
                            ->minValue('0.000000001')
                            ->mutateStateForValidationUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->dehydrateStateUsing(fn (mixed $state): mixed => $this->normalizedLocalizedDecimal($state))
                            ->live(onBlur: true),
                        Select::make('price_unit')
                            ->label('Price unit')
                            ->options(fn (Get $get): array => $get('material_type') === 'packaging' ? ['count' => 'count'] : $this->massUnitOptions())
                            ->required(fn (Get $get): bool => $get('price_basis') === ListingPriceBasis::PerUnit->value)
                            ->visible(fn (Get $get): bool => $get('price_basis') === ListingPriceBasis::PerUnit->value)
                            ->disabled(fn (Get $get): bool => $get('material_type') === 'packaging')
                            ->dehydrated()
                            ->live(),
                        Select::make('currency')
                            ->label('Currency')
                            ->options($this->currencyOptions())
                            ->searchable()
                            ->required()
                            ->live(),
                        Placeholder::make('price_preview')
                            ->label('Calculated price')
                            ->content(fn (): string => $this->pricePreviewText())
                            ->columnSpanFull(),
                    ]),
                Section::make('Ordering')
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('minimum_packs')->label('Minimum order')->helperText('Number of purchase formats.')->integer()->minValue(1)->required(),
                        Toggle::make('is_active')->label('Active')->default(true),
                        Textarea::make('notes')->label('Notes')->rows(4)->columnSpanFull(),
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
        return Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when($this->normalizedSearch($search) !== '', function (Builder $query) use ($search): void {
                $search = $this->normalizedSearch($search);
                $query->where(fn (Builder $nested): Builder => $nested->whereLike('code', "%{$search}%")->orWhereLike('name', "%{$search}%"));
            })
            ->orderBy('name')
            ->limit(self::OptionLimit)
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Supplier $supplier): array => [$supplier->id => $this->supplierLabel($supplier)])
            ->all();
    }

    /** @return array<int, string> */
    public function ingredientSearchResults(string $search): array
    {
        if (($this->data['material_type'] ?? 'ingredient') !== 'ingredient') {
            return [];
        }

        $search = $this->normalizedSearch($search);

        return $this->availableIngredientQuery()
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
    }

    /** @return array<int, string> */
    public function packagingSearchResults(string $search): array
    {
        if (($this->data['material_type'] ?? 'ingredient') !== 'packaging') {
            return [];
        }

        $search = $this->normalizedSearch($search);

        return UserPackagingItem::query()
            ->where('user_id', $this->workspace()->owner_user_id)
            ->when($search !== '', fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->limit(self::OptionLimit)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (UserPackagingItem $item): array => [$item->id => $item->name])
            ->all();
    }

    public function supplierOptionLabel(int $id): ?string
    {
        $supplier = $this->workspaceSupplierById($id);

        return $supplier instanceof Supplier ? $this->supplierLabel($supplier) : null;
    }

    public function ingredientOptionLabel(int $id): ?string
    {
        $ingredient = $this->availableIngredientQuery()->find($id);

        return $ingredient instanceof Ingredient ? $this->ingredientLabel($ingredient) : null;
    }

    public function packagingOptionLabel(int $id): ?string
    {
        return UserPackagingItem::query()
            ->where('user_id', $this->workspace()->owner_user_id)
            ->find($id)?->name;
    }

    /** @param array<string, mixed> $state */
    private function selectedSupplier(array $state): ?Supplier
    {
        $supplier = $this->lockedSupplierPublicId !== null
            ? $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)
            : $this->workspaceSupplierById(isset($state['supplier_id']) ? (int) $state['supplier_id'] : null);

        if (! $supplier instanceof Supplier) {
            $this->addError('data.supplier_id', 'Choose a supplier in this workspace.');
        }

        return $supplier;
    }

    /** @param array<string, mixed> $state */
    private function selectedSubject(array $state): Ingredient|UserPackagingItem|null
    {
        if (($state['material_type'] ?? null) === 'packaging') {
            $item = UserPackagingItem::query()
                ->where('user_id', $this->workspace()->owner_user_id)
                ->find($state['packaging_item_id'] ?? null);

            if (! $item instanceof UserPackagingItem) {
                $this->addError('data.packaging_item_id', 'Choose an existing packaging item.');
            }

            return $item;
        }

        $ingredient = $this->availableIngredientQuery()->find($state['ingredient_id'] ?? null);

        if (! $ingredient instanceof Ingredient) {
            $this->addError('data.ingredient_id', 'Choose an existing ingredient in this workspace.');
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
            'supplier_name' => $state['supplier_name'] ?? null,
            'purchase_format' => $state['purchase_format'],
            'container' => null,
            'net_quantity' => (string) $state['net_quantity'],
            'net_unit' => $isPackaging ? 'count' : $state['net_unit'],
            'price_basis' => $basis,
            'price_amount' => (string) $state['price_amount'],
            'price_unit' => $basis === ListingPriceBasis::TotalPurchaseFormat ? null : ($isPackaging ? 'count' : $state['price_unit']),
            'currency' => Str::upper(trim((string) $state['currency'])),
            'minimum_packs' => $state['minimum_packs'],
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

    /** @return array<int, string> */
    private function lockedSupplierOptions(): array
    {
        if ($this->lockedSupplierPublicId === null) {
            return [];
        }

        $supplier = $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId);

        return [$supplier->id => $this->supplierLabel($supplier)];
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

        return $preview === null ? 'Enter quantity and price.' : $preview['unit'].' · '.$preview['total'];
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
