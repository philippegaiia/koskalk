<?php

namespace App\Livewire\ProductionBench\Purchasing;

use App\Actions\Purchasing\SaveSupplier;
use App\Actions\Purchasing\SaveSupplierListing;
use App\DecimalStringFormatter;
use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\Services\MassConverter;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\Services\SupplierListingPricePresentation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierDetail extends Component
{
    use WithPagination;

    private const int SubjectSearchLimit = 50;

    private const array ALLOWED_PER_PAGE = [25, 50, 100];

    public string|Supplier $supplier;

    public string $name = '';

    public string $code = '';

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

    public string $listingSubjectSearch = '';

    public string $listingStatus = 'active';

    public int $perPage = 25;

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
        $this->priceUnit = $this->defaultPriceUnit();
    }

    public function updatedListingSubjectType(): void
    {
        $this->listingSubjectId = null;
        $this->netUnit = $this->listingSubjectType === 'packaging' ? 'count' : 'kg';
        $this->priceUnit = $this->priceBasis === ListingPriceBasis::TotalPurchaseFormat->value
            ? ''
            : $this->defaultPriceUnit();
    }

    public function updatedPriceBasis(): void
    {
        $this->priceUnit = $this->priceBasis === ListingPriceBasis::TotalPurchaseFormat->value
            ? ''
            : $this->defaultPriceUnit();
    }

    public function updatedListingStatus(): void
    {
        $this->resetPage('supplier-listings');
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->normalizedPerPage();
        $this->resetPage('supplier-listings');
    }

    public function saveSupplier(SaveSupplier $saveSupplier): void
    {
        $this->code = Str::upper(trim($this->code));

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
                    'price_unit' => $this->priceBasis === ListingPriceBasis::TotalPurchaseFormat->value
                        ? null
                        : (filled($this->priceUnit) ? $this->priceUnit : null),
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
        $this->resetPage('supplier-listings');
        $this->listingSavedMessage = 'Supplier listing saved.';
    }

    public function render(
        DecimalStringFormatter $decimalStringFormatter,
        ProductionBenchAccess $access,
        MassConverter $massConverter,
        SupplierListingPriceCalculator $priceCalculator,
        SupplierListingPricePresentation $pricePresentation,
    ): View {
        $workspace = $this->workspace();

        return view('livewire.production-bench.purchasing.supplier-detail', [
            'isBenchActive' => $access->isActive($workspace),
            'isReadOnly' => $access->isReadOnly($workspace),
            'ingredients' => $this->availableIngredients(),
            'packagingItems' => $this->availablePackagingItems(),
            'listingRows' => $this->supplier->listings()
                ->with(['ingredient.translations', 'packagingItem'])
                ->when($this->listingStatus === 'active', fn (Builder $query) => $query->where('is_active', true))
                ->latest('id')
                ->paginate($this->normalizedPerPage(), ['*'], 'supplier-listings')
                ->through(fn ($listing): array => [
                    'listing' => $listing,
                    'price' => $pricePresentation->present($listing, $workspace),
                ]),
            'pricePreview' => $this->pricePreview($decimalStringFormatter, $priceCalculator, $massConverter),
            'workspace' => $workspace,
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function supplierRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
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
            'code' => $this->code,
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
            'code' => $this->supplier->code,
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
            'listingNotes', 'listingSubjectSearch',
        ]);
        $this->listingSubjectType = 'ingredient';
        $this->netUnit = 'kg';
        $this->priceBasis = ListingPriceBasis::PerUnit->value;
        $this->priceUnit = $this->defaultPriceUnit();
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
    private function pricePreview(
        DecimalStringFormatter $decimalStringFormatter,
        SupplierListingPriceCalculator $priceCalculator,
        MassConverter $massConverter,
    ): ?array {
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

        $workspace = $this->workspace();
        $displayUnit = $workspace->mass_display_system->priceUnit()->value;

        return [
            'total_price' => $decimalStringFormatter->toFixed($prices['total_price']),
            'unit_price' => $decimalStringFormatter->toFixed($this->listingSubjectType === 'ingredient'
                ? bcmul($prices['price_per_canonical_unit'], $massConverter->toGrams('1', $displayUnit), 9)
                : $prices['price_per_item']),
            'unit_label' => $this->listingSubjectType === 'ingredient' ? $displayUnit : 'item',
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

    private function defaultPriceUnit(): string
    {
        return $this->listingSubjectType === 'packaging'
            ? 'count'
            : $this->workspace()->mass_display_system->priceUnit()->value;
    }

    private function normalizedPerPage(): int
    {
        return in_array($this->perPage, self::ALLOWED_PER_PAGE, true) ? $this->perPage : 25;
    }

    /** @return Collection<int, Ingredient> */
    private function availableIngredients(): Collection
    {
        $ingredients = $this->accessibleIngredients()
            ->with('translations')
            ->when(filled($this->listingSubjectSearch), function (Builder $query): void {
                $this->applyIngredientSearch($query, $this->listingSubjectSearch);
            })
            ->orderBy('display_name')
            ->limit(self::SubjectSearchLimit)
            ->get();

        if ($this->listingSubjectType !== 'ingredient' || $this->listingSubjectId === null || $ingredients->contains('id', $this->listingSubjectId)) {
            return $ingredients;
        }

        $selectedIngredient = $this->accessibleIngredients()
            ->with('translations')
            ->find($this->listingSubjectId);

        if ($selectedIngredient instanceof Ingredient) {
            $ingredients->prepend($selectedIngredient);
        }

        return $ingredients;
    }

    /** @return Collection<int, UserPackagingItem> */
    private function availablePackagingItems(): Collection
    {
        $packagingItems = $this->packagingItems()
            ->when(filled($this->listingSubjectSearch), function (Builder $query): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($this->listingSubjectSearch)).'%']);
            })
            ->orderBy('name')
            ->limit(self::SubjectSearchLimit)
            ->get();

        if ($this->listingSubjectType !== 'packaging' || $this->listingSubjectId === null || $packagingItems->contains('id', $this->listingSubjectId)) {
            return $packagingItems;
        }

        $selectedPackagingItem = $this->packagingItems()->find($this->listingSubjectId);

        if ($selectedPackagingItem instanceof UserPackagingItem) {
            $packagingItems->prepend($selectedPackagingItem);
        }

        return $packagingItems;
    }

    private function applyIngredientSearch(Builder $query, string $search): void
    {
        $searchTerm = '%'.mb_strtolower(trim($search)).'%';
        $translationLocales = Ingredient::translationLocaleCandidates();

        $query->where(function (Builder $ingredientQuery) use ($searchTerm, $translationLocales): void {
            $ingredientQuery->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);

            if ($translationLocales !== []) {
                $ingredientQuery->orWhereHas('translations', function (Builder $translationQuery) use ($searchTerm, $translationLocales): void {
                    $translationQuery
                        ->whereIn('locale', $translationLocales)
                        ->whereRaw('LOWER(display_name) LIKE ?', [$searchTerm]);
                });
            }
        });
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
