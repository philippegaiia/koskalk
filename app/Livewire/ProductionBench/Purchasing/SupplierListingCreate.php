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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SupplierListingCreate extends Component
{
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

    public function mount(ProductionBenchAccess $access, string|Supplier|null $supplier = null): void
    {
        $this->assertPageIsWritable($access);
        $workspace = $this->workspace();
        $this->currency = $workspace->default_currency;
        $this->netUnit = $workspace->mass_display_system->priceUnit()->value;
        $this->priceUnit = $workspace->mass_display_system->priceUnit()->value;

        if ($supplier === null) {
            return;
        }

        $supplierPublicId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
        $lockedSupplier = $this->workspaceSupplierByPublicId($supplierPublicId);
        $this->lockedSupplierPublicId = $lockedSupplier->public_id;
        $this->supplierId = $lockedSupplier->id;
        $this->currency = $lockedSupplier->default_currency;
    }

    public function updatedSupplierId(): void
    {
        if ($this->lockedSupplierPublicId !== null) {
            $this->supplierId = $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId)->id;

            return;
        }

        $supplier = $this->workspaceSupplierById($this->supplierId);

        if ($supplier instanceof Supplier) {
            $this->currency = $supplier->default_currency;
        }
    }

    public function updatedMaterialType(): void
    {
        $this->ingredientId = null;
        $this->packagingItemId = null;

        if ($this->materialType === 'packaging') {
            $this->netUnit = 'count';
            $this->priceUnit = $this->priceBasis === ListingPriceBasis::PerUnit->value ? 'count' : '';

            return;
        }

        $displayUnit = $this->workspace()->mass_display_system->priceUnit()->value;
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
        CurrencyCatalog $currencyCatalog,
        DecimalStringFormatter $decimalStringFormatter,
        MassConverter $massConverter,
        SupplierListingPriceCalculator $priceCalculator,
    ): View {
        $workspace = $this->workspace();
        $lockedSupplier = $this->lockedSupplierPublicId === null
            ? null
            : $this->workspaceSupplierByPublicId($this->lockedSupplierPublicId);

        return view('livewire.production-bench.purchasing.supplier-listing-create', [
            'currencyOptions' => collect($currencyCatalog->options(app()->getLocale(), [$this->currency]))
                ->map(fn (string $name, string $code): array => ['id' => $code, 'label' => $code.' — '.$name])
                ->values()
                ->all(),
            'ingredientOptions' => $this->ingredientOptions(),
            'lockedSupplier' => $lockedSupplier,
            'packagingOptions' => $this->packagingOptions(),
            'pricePreview' => $this->pricePreview($priceCalculator, $massConverter, $decimalStringFormatter),
            'supplierOptions' => $this->supplierOptions(),
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
            'currency' => ['required', 'alpha:ascii', 'size:3'],
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
    private function supplierOptions(): array
    {
        return Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Supplier $supplier): array => [
                'id' => $supplier->id,
                'label' => $supplier->code.' · '.$supplier->name,
            ])
            ->all();
    }

    /** @return list<array{id: int, label: string}> */
    private function ingredientOptions(): array
    {
        return Ingredient::query()
            ->with('translations')
            ->where('is_active', true)
            ->accessibleTo($this->user())
            ->orderBy('display_name')
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $this->ingredientIsAvailable($ingredient))
            ->map(fn (Ingredient $ingredient): array => [
                'id' => $ingredient->id,
                'label' => $ingredient->localizedDisplayName(),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, label: string}> */
    private function packagingOptions(): array
    {
        return UserPackagingItem::query()
            ->where('user_id', $this->workspace()->owner_user_id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (UserPackagingItem $packagingItem): array => [
                'id' => $packagingItem->id,
                'label' => $packagingItem->name,
            ])
            ->all();
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
