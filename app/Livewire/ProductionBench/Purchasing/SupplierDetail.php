<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
use App\Actions\Purchasing\SaveSupplierListing;
use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SupplierDetail extends Component
{
    public string|Supplier $supplier;

    public string $name = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $region = '';

    public string $postalCode = '';

    public string $countryCode = '';

    public string $website = '';

    public string $contactName = '';

    public string $email = '';

    public string $phone = '';

    public string $defaultCurrency = '';

    public string $notes = '';

    public bool $isActive = true;

    public string $listingSubjectType = 'ingredient';

    public ?int $listingSubjectId = null;

    public string $supplierSku = '';

    public string $supplierName = '';

    public string $purchaseFormat = '';

    public string $netQuantity = '';

    public string $netUnit = 'kg';

    public string $priceBasis = 'per_unit';

    public string $priceAmount = '';

    public string $priceUnit = 'kg';

    public int $minimumPacks = 1;

    public string $listingNotes = '';

    public bool $listingIsActive = true;

    public ?string $listingSavedMessage = null;

    public ?string $supplierSavedMessage = null;

    public function mount(string|Supplier $supplier): void
    {
        $supplierId = $supplier instanceof Supplier ? $supplier->public_id : $supplier;
        $this->supplier = Supplier::query()
            ->where('workspace_id', $this->workspace()->id)
            ->where('public_id', $supplierId)
            ->firstOrFail();

        $this->fillSupplierForm();
    }

    public function updatedListingSubjectType(): void
    {
        $this->listingSubjectId = null;
        $this->netUnit = $this->listingSubjectType === 'packaging' ? 'count' : 'kg';
        $this->priceUnit = $this->listingSubjectType === 'packaging' ? 'count' : 'kg';
    }

    public function saveSupplier(SaveSupplier $saveSupplier): void
    {
        $this->validate($this->supplierRules());

        $this->supplier = $saveSupplier->handle(
            $this->user(),
            $this->workspace(),
            $this->supplierAttributes(),
            $this->supplier,
        );
        $this->supplierSavedMessage = 'Supplier details saved.';
    }

    public function saveListing(SaveSupplierListing $saveSupplierListing): void
    {
        $this->validate([
            'listingSubjectType' => ['required', 'in:ingredient,packaging'],
            'listingSubjectId' => ['required', 'integer'],
            'purchaseFormat' => ['required', 'string', 'max:255'],
            'netQuantity' => ['required', 'string', 'max:255'],
            'netUnit' => ['required', 'string', 'max:24'],
            'priceBasis' => ['required', 'in:per_unit,total_purchase_format'],
            'priceAmount' => ['required', 'string', 'max:255'],
            'priceUnit' => ['nullable', 'string', 'max:24'],
            'minimumPacks' => ['required', 'integer', 'min:1'],
        ]);

        $subject = $this->listingSubjectType === 'ingredient'
            ? $this->accessibleIngredients()->find($this->listingSubjectId)
            : $this->packagingItems()->find($this->listingSubjectId);

        if (! $subject instanceof Ingredient && ! $subject instanceof UserPackagingItem) {
            $this->addError('listingSubjectId', 'Choose an accessible ingredient or packaging item.');

            return;
        }

        try {
            $saveSupplierListing->handle(
                actor: $this->user(),
                workspace: $this->workspace(),
                supplier: $this->supplier,
                subject: $subject,
                attributes: [
                    'purchase_format' => $this->purchaseFormat,
                    'net_quantity' => $this->netQuantity,
                    'net_unit' => $this->netUnit,
                    'price_basis' => ListingPriceBasis::from($this->priceBasis),
                    'price_amount' => $this->priceAmount,
                    'price_unit' => filled($this->priceUnit) ? $this->priceUnit : null,
                    'supplier_sku' => $this->supplierSku,
                    'supplier_name' => $this->supplierName,
                    'minimum_packs' => $this->minimumPacks,
                    'notes' => $this->listingNotes,
                    'is_active' => $this->listingIsActive,
                    'currency' => $this->supplier->default_currency,
                ],
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($this->listingErrorField($field), $messages[0]);
            }

            return;
        }

        $this->resetListingForm();
        $this->listingSavedMessage = 'Supplier listing saved.';
    }

    public function render(
        ProductionBenchAccess $access,
        SupplierListingPriceCalculator $priceCalculator,
    ): View {
        $workspace = $this->workspace();

        return view('livewire.production-bench.purchasing.supplier-detail', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'ingredients' => $this->accessibleIngredients()->orderBy('display_name')->get(),
            'packagingItems' => $this->packagingItems()->orderBy('name')->get(),
            'listings' => $this->supplier->listings()
                ->with(['ingredient.translations', 'packagingItem'])
                ->latest('id')
                ->get(),
            'pricePreview' => $this->pricePreview($priceCalculator),
            'workspace' => $workspace,
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function supplierRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'countryCode' => ['nullable', 'alpha:ascii', 'size:2'],
            'website' => ['nullable', 'url', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'defaultCurrency' => ['required', 'alpha:ascii', 'size:3'],
        ];
    }

    /** @return array<string, mixed> */
    private function supplierAttributes(): array
    {
        return [
            'name' => $this->name,
            'address_line_1' => $this->addressLine1,
            'address_line_2' => $this->addressLine2,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
            'website' => $this->website,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
            'default_currency' => $this->defaultCurrency,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
        ];
    }

    private function fillSupplierForm(): void
    {
        $this->fill([
            'name' => $this->supplier->name,
            'addressLine1' => $this->supplier->address_line_1 ?? '',
            'addressLine2' => $this->supplier->address_line_2 ?? '',
            'city' => $this->supplier->city ?? '',
            'region' => $this->supplier->region ?? '',
            'postalCode' => $this->supplier->postal_code ?? '',
            'countryCode' => $this->supplier->country_code ?? '',
            'website' => $this->supplier->website ?? '',
            'contactName' => $this->supplier->contact_name ?? '',
            'email' => $this->supplier->email ?? '',
            'phone' => $this->supplier->phone ?? '',
            'defaultCurrency' => $this->supplier->default_currency,
            'notes' => $this->supplier->notes ?? '',
            'isActive' => $this->supplier->is_active,
        ]);
    }

    private function resetListingForm(): void
    {
        $this->reset([
            'listingSubjectId', 'supplierSku', 'supplierName', 'purchaseFormat', 'netQuantity', 'priceAmount',
            'listingNotes',
        ]);
        $this->listingSubjectType = 'ingredient';
        $this->netUnit = 'kg';
        $this->priceBasis = ListingPriceBasis::PerUnit->value;
        $this->priceUnit = 'kg';
        $this->minimumPacks = 1;
        $this->listingIsActive = true;
    }

    /** @return Builder<Ingredient> */
    private function accessibleIngredients(): Builder
    {
        $user = $this->user();
        $workspace = $this->workspace();

        return Ingredient::query()
            ->accessibleTo($user)
            ->where('is_active', true)
            ->where(function ($query) use ($user, $workspace): void {
                $query->whereNull('owner_type')
                    ->orWhere('workspace_id', $workspace->id)
                    ->orWhere(function ($ownedQuery) use ($user): void {
                        $ownedQuery->where('owner_type', 'user')->where('owner_id', $user->id);
                    });
            });
    }

    /** @return Builder<UserPackagingItem> */
    private function packagingItems(): Builder
    {
        return UserPackagingItem::query()->where('user_id', $this->workspace()->owner_user_id);
    }

    /** @return array{total_price: string, unit_price: string, unit_label: string}|null */
    private function pricePreview(SupplierListingPriceCalculator $priceCalculator): ?array
    {
        if ($this->netQuantity === '' || $this->priceAmount === '') {
            return null;
        }

        try {
            $basis = ListingPriceBasis::from($this->priceBasis);
            $prices = $this->listingSubjectType === 'ingredient'
                ? $priceCalculator->forMass($this->netQuantity, $this->netUnit, $basis, $this->priceAmount, filled($this->priceUnit) ? $this->priceUnit : null)
                : $priceCalculator->forCount($this->netQuantity, $basis, $this->priceAmount);
        } catch (\Throwable) {
            return null;
        }

        return [
            'total_price' => $prices['total_price'],
            'unit_price' => $this->listingSubjectType === 'ingredient' ? $prices['price_per_kg'] : $prices['price_per_item'],
            'unit_label' => $this->listingSubjectType === 'ingredient' ? 'kg' : 'item',
        ];
    }

    private function listingErrorField(string $field): string
    {
        return match ($field) {
            'subject', 'packaging_item' => 'listingSubjectId',
            'purchase_format' => 'purchaseFormat',
            'net_quantity' => 'netQuantity',
            'net_unit' => 'netUnit',
            'price_basis' => 'priceBasis',
            'price_amount' => 'priceAmount',
            'price_unit' => 'priceUnit',
            'minimum_packs' => 'minimumPacks',
            default => $field,
        };
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
