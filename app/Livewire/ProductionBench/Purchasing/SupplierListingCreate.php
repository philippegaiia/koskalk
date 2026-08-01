<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplierListing;
use App\DecimalStringFormatter;
use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\CurrencyCatalog;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\Visibility;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SupplierListingCreate extends Component
{
    private const OptionLimit = 20;

    private CurrencyCatalog $currencyCatalog;

    public ?int $supplierId = null;

    #[Locked]
    public ?string $lockedSupplierPublicId = null;

    public string $materialType = 'ingredient';

    public ?int $ingredientId = null;

    public ?int $packagingItemId = null;

    public string $supplierSku = '';

    public string $supplierName = '';

    public string $purchaseFormat = '';

    public string $netQuantity = '';

    public string $netUnit = '';

    public string $priceBasis = 'per_unit';

    public string $priceAmount = '';

    public string $priceUnit = '';

    public string $currency = '';

    public int $minimumPacks = 1;

    public bool $isActive = true;

    public string $notes = '';

    /** @var list<array{id: int, label: string}> */
    #[Locked]
    public array $supplierOptions = [];

    /** @var list<array{id: int, label: string}> */
    #[Locked]
    public array $ingredientOptions = [];

    /** @var list<array{id: int, label: string}> */
    #[Locked]
    public array $packagingOptions = [];

    public function boot(CurrencyCatalog $currencyCatalog): void
    {
        $this->currencyCatalog = $currencyCatalog;
    }

    public function mount(ProductionBenchAccess $access, string|Supplier|null $supplier = null): void
    {
        $this->assertPageIsWritable($access);
        $workspace = $this->workspace();
        $this->currency = $this->newListingCurrency();
        $this->netUnit = $workspace->mass_display_system->priceUnit()->value;
        $this->priceUnit = $workspace->mass_display_system->priceUnit()->value;

        if ($supplier === null) {
            $this->supplierOptions = $this->loadSupplierOptions();
            $this->ingredientOptions = $this->loadIngredientOptions();

            return;
        }

        $supplierPublicId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
        $lockedSupplier = $this->workspaceSupplierByPublicId($supplierPublicId);
        $this->lockedSupplierPublicId = $lockedSupplier->public_id;
        $this->supplierId = $lockedSupplier->id;
        $this->currency = $this->newListingCurrency($lockedSupplier);
        $this->ingredientOptions = $this->loadIngredientOptions();
    }

    public function updatedSupplierId(): void
    {
        if ($this->lockedSupplierPublicId !== null) {
            $this->supplierId = $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)->id;

            return;
        }

        $supplier = $this->workspaceSupplierById($this->supplierId);

        if ($supplier instanceof Supplier) {
            $this->currency = $this->newListingCurrency($supplier);
        }
    }

    public function updatedMaterialType(): void
    {
        $this->ingredientId = null;
        $this->packagingItemId = null;

        if ($this->materialType === 'packaging') {
            $this->ingredientOptions = [];
            $this->packagingOptions = $this->loadPackagingOptions();
            $this->netUnit = 'count';
            $this->priceUnit = $this->priceBasis === ListingPriceBasis::PerUnit->value ? 'count' : '';

            return;
        }

        $displayUnit = $this->workspace()->mass_display_system->priceUnit()->value;
        $this->packagingOptions = [];
        $this->ingredientOptions = $this->loadIngredientOptions();
        $this->netUnit = $displayUnit;
        $this->priceUnit = $this->priceBasis === ListingPriceBasis::PerUnit->value ? $displayUnit : '';
    }

    public function updatedPriceBasis(): void
    {
        if ($this->priceBasis === ListingPriceBasis::TotalPurchaseFormat->value) {
            $this->priceUnit = '';

            return;
        }

        $this->priceUnit = $this->materialType === 'packaging'
            ? 'count'
            : $this->workspace()->mass_display_system->priceUnit()->value;
    }

    public function searchSupplierOptions(string $search = ''): void
    {
        if ($this->lockedSupplierPublicId !== null) {
            $this->skipRender();

            return;
        }

        $this->supplierOptions = $this->loadSupplierOptions($search);
        $this->dispatch('supplier-listing-supplier-options-updated', options: $this->supplierOptions);
        $this->skipRender();
    }

    public function searchIngredientOptions(string $search = ''): void
    {
        if ($this->materialType !== 'ingredient') {
            $this->skipRender();

            return;
        }

        $this->ingredientOptions = $this->loadIngredientOptions($search);
        $this->dispatch('supplier-listing-ingredient-options-updated', options: $this->ingredientOptions);
        $this->skipRender();
    }

    public function searchPackagingOptions(string $search = ''): void
    {
        if ($this->materialType !== 'packaging') {
            $this->skipRender();

            return;
        }

        $this->packagingOptions = $this->loadPackagingOptions($search);
        $this->dispatch('supplier-listing-packaging-options-updated', options: $this->packagingOptions);
        $this->skipRender();
    }

    public function save(SaveSupplierListing $saveSupplierListing): void
    {
        if ($this->lockedSupplierPublicId !== null) {
            $this->supplierId = $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)->id;
        }

        $this->currency = Str::upper(trim($this->currency));
        $this->validate($this->rules());

        $supplier = $this->selectedSupplier();
        $subject = $this->selectedSubject();

        if (
            ! $supplier instanceof Supplier
            || (! $subject instanceof Ingredient && ! $subject instanceof UserPackagingItem)
        ) {
            return;
        }

        try {
            $saveSupplierListing->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                supplier: $supplier,
                subject: $subject,
                attributes: $this->listingAttributes(),
            );
        } catch (ValidationException $exception) {
            $this->surfaceValidationErrors($exception);

            return;
        }

        if ($this->lockedSupplierPublicId !== null) {
            $this->redirectRoute('production-bench.purchasing.supplier', ['supplier' => $supplier], navigate: true);

            return;
        }

        $this->redirectRoute('production-bench.purchasing.listings', navigate: true);
    }

    public function render(
        DecimalStringFormatter $decimalStringFormatter,
        MassConverter $massConverter,
        SupplierListingPriceCalculator $priceCalculator,
    ): View {
        $workspace = $this->workspace();
        $lockedSupplier = $this->lockedSupplierPublicId === null
            ? null
            : $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId);

        return view('livewire.production-bench.purchasing.supplier-listing-create', [
            'currencyOptions' => collect($this->currencyCatalog->options(app()->getLocale(), [$this->currency]))
                ->map(fn (string $name, string $code): array => ['id' => $code, 'label' => $code.' — '.$name])
                ->values()
                ->all(),
            'ingredientOptions' => $this->ingredientOptions,
            'lockedSupplier' => $lockedSupplier,
            'packagingOptions' => $this->packagingOptions,
            'pricePreview' => $this->pricePreview($priceCalculator, $massConverter, $decimalStringFormatter),
            'supplierOptions' => $this->supplierOptions,
            'workspace' => $workspace,
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'supplierId' => ['required', 'integer'],
            'materialType' => ['required', Rule::in(['ingredient', 'packaging'])],
            'ingredientId' => ['nullable', 'required_if:materialType,ingredient', 'integer'],
            'packagingItemId' => ['nullable', 'required_if:materialType,packaging', 'integer'],
            'supplierSku' => ['nullable', 'string', 'max:255'],
            'supplierName' => ['nullable', 'string', 'max:255'],
            'purchaseFormat' => ['required', 'string', 'max:255'],
            'netQuantity' => ['required', 'string', 'max:255'],
            'netUnit' => ['required', Rule::in($this->materialType === 'packaging' ? ['count'] : ['g', 'kg', 'oz', 'lb'])],
            'priceBasis' => ['required', Rule::enum(ListingPriceBasis::class)],
            'priceAmount' => ['required', 'string', 'max:255'],
            'priceUnit' => [Rule::requiredIf($this->priceBasis === ListingPriceBasis::PerUnit->value), 'nullable', Rule::in($this->materialType === 'packaging' ? ['count'] : ['g', 'kg', 'oz', 'lb'])],
            'currency' => ['required', 'string', 'size:3', Rule::in($this->currencyCatalog->selectableCodes())],
            'minimumPacks' => ['required', 'integer', 'min:1'],
            'isActive' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /** @return array<string, mixed> */
    private function listingAttributes(): array
    {
        return [
            'supplier_sku' => $this->supplierSku,
            'supplier_name' => $this->supplierName,
            'purchase_format' => $this->purchaseFormat,
            'container' => null,
            'net_quantity' => $this->netQuantity,
            'net_unit' => $this->materialType === 'packaging' ? 'count' : $this->netUnit,
            'price_basis' => ListingPriceBasis::from($this->priceBasis),
            'price_amount' => $this->priceAmount,
            'price_unit' => $this->priceBasis === ListingPriceBasis::TotalPurchaseFormat->value
                ? null
                : ($this->materialType === 'packaging' ? 'count' : $this->priceUnit),
            'currency' => $this->currency,
            'minimum_packs' => $this->minimumPacks,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
        ];
    }

    private function selectedSupplier(): ?Supplier
    {
        $supplier = $this->lockedSupplierPublicId !== null
            ? $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)
            : $this->workspaceSupplierById($this->supplierId);

        if (! $supplier instanceof Supplier) {
            $this->addError('supplierId', 'Choose a supplier in this workspace.');
        }

        return $supplier;
    }

    private function selectedSubject(): Ingredient|UserPackagingItem|null
    {
        if ($this->materialType === 'packaging') {
            $packagingItem = UserPackagingItem::query()
                ->where('user_id', $this->workspace()->owner_user_id)
                ->find($this->packagingItemId);

            if (! $packagingItem instanceof UserPackagingItem) {
                $this->addError('packagingItemId', 'Choose an existing packaging item.');
            }

            return $packagingItem;
        }

        $ingredient = Ingredient::query()->find($this->ingredientId);

        if (! $ingredient instanceof Ingredient || ! $this->ingredientIsAvailable($ingredient)) {
            $this->addError('ingredientId', 'Choose an existing ingredient in this workspace.');

            return null;
        }

        return $ingredient;
    }

    private function workspaceSupplierById(?int $supplierId): ?Supplier
    {
        if ($supplierId === null) {
            return null;
        }

        return Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->find($supplierId);
    }

    private function workspaceSupplierByPublicId(string $publicId): Supplier
    {
        return Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return list<array{id: int, label: string}> */
    private function loadSupplierOptions(string $search = ''): array
    {
        $search = $this->normalizedSearch($search);
        $options = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $searchQuery): Builder => $searchQuery
                    ->whereLike('code', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%"),
            ))
            ->orderBy('name')
            ->limit(self::OptionLimit)
            ->get(['id', 'code', 'name'])
            ->map(fn (Supplier $supplier): array => $this->supplierOption($supplier));

        return $this->retainSelectedOption(
            $options,
            $this->supplierId,
            fn (int $id): ?array => ($supplier = Supplier::query()
                ->where('workspace_id', $this->workspace()->id)
                ->find($id)) instanceof Supplier
                    ? $this->supplierOption($supplier)
                    : null,
        );
    }

    /** @return list<array{id: int, label: string}> */
    private function loadIngredientOptions(string $search = ''): array
    {
        $search = $this->normalizedSearch($search);
        $options = $this->availableIngredientQuery()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $searchQuery): Builder => $searchQuery
                    ->whereLike('display_name', "%{$search}%")
                    ->orWhereLike('source_key', "%{$search}%")
                    ->orWhereLike('inci_name', "%{$search}%")
                    ->orWhereHas('translations', fn (Builder $translationQuery): Builder => $translationQuery
                        ->whereLike('display_name', "%{$search}%")),
            ))
            ->orderBy('display_name')
            ->limit(self::OptionLimit)
            ->get()
            ->map(fn (Ingredient $ingredient): array => $this->ingredientOption($ingredient));

        return $this->retainSelectedOption(
            $options,
            $this->ingredientId,
            fn (int $id): ?array => ($ingredient = $this->availableIngredientQuery()->find($id)) instanceof Ingredient
                    ? $this->ingredientOption($ingredient)
                    : null,
        );
    }

    /** @return list<array{id: int, label: string}> */
    private function loadPackagingOptions(string $search = ''): array
    {
        $search = $this->normalizedSearch($search);
        $options = UserPackagingItem::query()
            ->where('user_id', $this->workspace()->owner_user_id)
            ->when($search !== '', fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->limit(self::OptionLimit)
            ->get(['id', 'name'])
            ->map(fn (UserPackagingItem $packagingItem): array => $this->packagingOption($packagingItem));

        return $this->retainSelectedOption(
            $options,
            $this->packagingItemId,
            fn (int $id): ?array => ($packagingItem = UserPackagingItem::query()
                ->where('user_id', $this->workspace()->owner_user_id)
                ->find($id)) instanceof UserPackagingItem
                    ? $this->packagingOption($packagingItem)
                    : null,
        );
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
                    ->orWhere(function (Builder $ownedQuery) use ($user): void {
                        $ownedQuery
                            ->where('owner_type', OwnerType::User->value)
                            ->where('owner_id', $user->id);
                    })
                    ->orWhere('workspace_id', $workspace->id)
                    ->orWhere(function (Builder $workspaceQuery) use ($workspace): void {
                        $workspaceQuery
                            ->where('owner_type', OwnerType::Workspace->value)
                            ->where('owner_id', $workspace->id);
                    });
            });
    }

    /** @return array{id: int, label: string} */
    private function supplierOption(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'label' => $supplier->code.' · '.$supplier->name,
        ];
    }

    /** @return array{id: int, label: string} */
    private function ingredientOption(Ingredient $ingredient): array
    {
        return [
            'id' => $ingredient->id,
            'label' => $ingredient->localizedDisplayName() ?? $ingredient->display_name ?? $ingredient->source_key,
        ];
    }

    /** @return array{id: int, label: string} */
    private function packagingOption(UserPackagingItem $packagingItem): array
    {
        return [
            'id' => $packagingItem->id,
            'label' => $packagingItem->name,
        ];
    }

    /**
     * @param  Collection<int, array{id: int, label: string}>  $options
     * @param  callable(int): (array{id: int, label: string}|null)  $resolveSelected
     * @return list<array{id: int, label: string}>
     */
    private function retainSelectedOption(Collection $options, ?int $selectedId, callable $resolveSelected): array
    {
        if ($selectedId !== null && ! $options->contains('id', $selectedId)) {
            $selected = $resolveSelected($selectedId);

            if ($selected !== null) {
                $options->prepend($selected);
            }
        }

        return $options->take(self::OptionLimit)->values()->all();
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

    private function ingredientIsAvailable(Ingredient $ingredient): bool
    {
        if (! $ingredient->isAccessibleBy($this->user())) {
            return false;
        }

        $ingredientWorkspaceId = $ingredient->tenantWorkspaceId();

        if ($ingredientWorkspaceId === null && $ingredient->tenantOwnerType() === OwnerType::Workspace) {
            $ingredientWorkspaceId = $ingredient->tenantOwnerId();
        }

        return $ingredient->isPublicCatalog()
            || $ingredientWorkspaceId === null
            || $ingredientWorkspaceId === $this->workspace()->id;
    }

    /** @return array{unit: string, total: string}|null */
    private function pricePreview(
        SupplierListingPriceCalculator $priceCalculator,
        MassConverter $massConverter,
        DecimalStringFormatter $formatter,
    ): ?array {
        if ($this->netQuantity === '' || $this->priceAmount === '') {
            return null;
        }

        $basis = ListingPriceBasis::tryFrom($this->priceBasis);

        if (! $basis instanceof ListingPriceBasis) {
            return null;
        }

        try {
            if ($this->materialType === 'packaging') {
                $prices = $priceCalculator->forCount($this->netQuantity, $basis, $this->priceAmount);

                return [
                    'unit' => $this->currency.' '.$formatter->toFixed($prices['price_per_item']).' / item',
                    'total' => $this->currency.' '.$formatter->toFixed($prices['total_price']).' total',
                ];
            }

            $priceUnit = $basis === ListingPriceBasis::TotalPurchaseFormat ? null : $this->priceUnit;
            $prices = $priceCalculator->forMass($this->netQuantity, $this->netUnit, $basis, $this->priceAmount, $priceUnit);
            $displayUnit = $this->workspace()->mass_display_system->priceUnit();
            $pricePerDisplayUnit = bcmul(
                bcdiv($prices['total_price'], $prices['canonical_quantity'], 18),
                $massConverter->toGrams('1', $displayUnit),
                18,
            );

            return [
                'unit' => $this->currency.' '.$formatter->toFixed($pricePerDisplayUnit).' / '.$displayUnit->value,
                'total' => $this->currency.' '.$formatter->toFixed($prices['total_price']).' total',
            ];
        } catch (ValidationException) {
            return null;
        }
    }

    private function surfaceValidationErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($this->formField($field), $message);
            }
        }
    }

    private function formField(string $field): string
    {
        return match ($field) {
            'supplier' => 'supplierId',
            'subject' => $this->materialType === 'packaging' ? 'packagingItemId' : 'ingredientId',
            'packaging_item' => 'packagingItemId',
            'supplier_sku' => 'supplierSku',
            'supplier_name' => 'supplierName',
            'purchase_format' => 'purchaseFormat',
            'net_quantity' => 'netQuantity',
            'net_unit' => 'netUnit',
            'price_basis' => 'priceBasis',
            'price_amount' => 'priceAmount',
            'price_unit' => 'priceUnit',
            'minimum_packs' => 'minimumPacks',
            'is_active' => 'isActive',
            default => $field,
        };
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
