<?php

namespace App\Actions\Purchasing;

use App\ListingPriceBasis;
use App\MaterialPriceSource;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\Workspace;
use App\OrganicStatus;
use App\OwnerType;
use App\Services\CurrencyCatalog;
use App\Services\CurrentMaterialPriceService;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\StockUnitKind;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveSupplierListing
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly SupplierListingPriceCalculator $priceCalculator,
        private readonly CurrencyCatalog $currencyCatalog,
        private readonly CurrentMaterialPriceService $currentMaterialPriceService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        Supplier $supplier,
        Ingredient|PackagingItem $subject,
        array $attributes,
        ?SupplierListing $listing = null,
    ): SupplierListing {
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $attributes, $listing, $subject, $supplier, $workspace): SupplierListing {
            Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $currentSubject = $this->existingSubject($subject);
            $currentSupplier = Supplier::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->find($supplier->id);

            if (! $currentSupplier instanceof Supplier) {
                $this->invalid('supplier', 'The supplier does not belong to this workspace.');
            }

            $this->assertSubjectIsAccessible($actor, $workspace, $currentSubject);

            $currentListing = $listing instanceof SupplierListing
                ? SupplierListing::query()
                    ->where('workspace_id', $workspace->id)
                    ->lockForUpdate()
                    ->find($listing->id)
                : null;

            if ($listing instanceof SupplierListing && ! $currentListing instanceof SupplierListing) {
                $this->invalid('listing', 'The supplier listing does not belong to this workspace.');
            }

            if ($currentListing instanceof SupplierListing && ! $this->hasExactSubject($currentListing, $currentSubject)) {
                $this->invalid('subject', 'A supplier listing cannot be moved to a different subject.');
            }

            $data = $this->validatedAttributes($attributes, $currentSupplier, $currentSubject, $currentListing);

            if ($currentSubject instanceof Ingredient) {
                $data['net_quantity'] = $this->priceCalculator->normalizeMassQuantity(
                    $data['net_quantity'],
                );
            }

            $prices = $currentSubject instanceof Ingredient
                ? $this->priceCalculator->forMass(
                    $data['net_quantity'],
                    $data['net_unit'],
                    $data['price_basis'],
                    $data['price_amount'],
                    $data['price_unit'],
                )
                : $this->priceCalculator->forCount(
                    $data['net_quantity'],
                    $data['price_basis'],
                    $data['price_amount'],
                );

            $values = [
                'supplier_id' => $currentSupplier->id,
                'ingredient_id' => $currentSubject instanceof Ingredient ? $currentSubject->id : null,
                'packaging_item_id' => $currentSubject instanceof PackagingItem ? $currentSubject->id : null,
                ...$data,
                'unit_kind' => $currentSubject instanceof Ingredient ? StockUnitKind::Mass : StockUnitKind::Count,
                'canonical_quantity_per_purchase_format' => $prices['canonical_quantity'],
                'price_unit' => $subject instanceof Ingredient
                    ? $data['price_unit']
                    : ($data['price_basis'] === ListingPriceBasis::PerUnit ? 'count' : null),
                'total_price' => $prices['total_price'],
            ];

            if (! $currentListing instanceof SupplierListing) {
                $currentListing = SupplierListing::query()->create([
                    'workspace_id' => $workspace->id,
                    ...$values,
                ]);
            } else {
                $currentListing->update($values);
            }

            if ($currentSubject instanceof Ingredient) {
                $this->currentMaterialPriceService->rememberIngredient(
                    workspace: $workspace,
                    ingredient: $currentSubject,
                    pricePerMassUnit: $prices['price_per_canonical_unit'],
                    massUnit: 'g',
                    currency: $currentListing->currency,
                    source: MaterialPriceSource::SupplierListing,
                    sourceId: $currentListing->id,
                    actor: $actor,
                    recordedAt: $currentListing->price_recorded_at,
                );
            } else {
                $this->currentMaterialPriceService->rememberPackaging(
                    workspace: $workspace,
                    packagingItem: $currentSubject,
                    pricePerItem: $prices['price_per_canonical_unit'],
                    currency: $currentListing->currency,
                    source: MaterialPriceSource::SupplierListing,
                    sourceId: $currentListing->id,
                    actor: $actor,
                    recordedAt: $currentListing->price_recorded_at,
                );
            }

            return $currentListing;
        }, attempts: 5);
    }

    private function existingSubject(Ingredient|PackagingItem $subject): Ingredient|PackagingItem
    {
        $currentSubject = $subject instanceof Ingredient
            ? Ingredient::query()->find($subject->id)
            : PackagingItem::query()->find($subject->id);

        if (! $currentSubject instanceof Ingredient && ! $currentSubject instanceof PackagingItem) {
            $this->invalid('subject', 'Choose an existing ingredient or packaging item.');
        }

        return $currentSubject;
    }

    private function assertSubjectIsAccessible(
        User $actor,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
    ): void {
        if ($subject instanceof Ingredient) {
            $ingredientWorkspaceId = $subject->tenantWorkspaceId();

            if ($ingredientWorkspaceId === null && $subject->tenantOwnerType() === OwnerType::Workspace) {
                $ingredientWorkspaceId = $subject->tenantOwnerId();
            }

            if (
                ! $subject->isAccessibleBy($actor)
                || (
                    ! $subject->isPublicCatalog()
                    && $ingredientWorkspaceId !== null
                    && $ingredientWorkspaceId !== $workspace->id
                )
            ) {
                $this->invalid('subject', 'The ingredient is not accessible in this workspace.');
            }
        }

        if ($subject instanceof PackagingItem && $subject->workspace_id !== $workspace->id) {
            $this->invalid('packaging_item', 'The packaging item must belong to this workspace.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validatedAttributes(
        array $attributes,
        Supplier $supplier,
        Ingredient|PackagingItem $subject,
        ?SupplierListing $listing,
    ): array {
        $data = $this->normalizeStrings($attributes);
        $data['currency'] = strtoupper((string) ($data['currency'] ?? $listing?->currency ?? $supplier->default_currency));
        $allowedCurrencies = $this->currencyCatalog->selectableCodes();

        if ($listing instanceof SupplierListing && $this->currencyCatalog->isKnown($listing->currency)) {
            $allowedCurrencies[] = strtoupper($listing->currency);
        }

        $validated = Validator::make($data, [
            'purchase_format' => ['required', 'string', 'max:255'],
            'net_quantity' => ['required', 'string', 'max:255'],
            'net_unit' => ['required', 'string', 'max:24'],
            'price_amount' => ['required', 'string', 'max:255'],
            'price_unit' => ['nullable', 'string', 'max:24'],
            'supplier_sku' => ['nullable', 'string', 'max:255'],
            'supplier_item_name' => ['nullable', 'string', 'max:255'],
            'container' => ['nullable', 'string', 'max:255'],
            'minimum_packs' => ['required', 'integer', 'min:1'],
            'organic_status' => ['nullable', Rule::enum(OrganicStatus::class)],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::in(array_unique($allowedCurrencies)),
                function (string $attribute, mixed $value, Closure $fail) use ($listing, $supplier): void {
                    $currency = strtoupper((string) $value);

                    if ($listing instanceof SupplierListing && strtoupper($listing->currency) === $currency) {
                        return;
                    }

                    if ($currency !== strtoupper($supplier->default_currency)) {
                        $fail('The currency must match the supplier currency.');
                    }
                },
            ],
            'price_recorded_at' => ['nullable', 'date'],
        ])->validate();

        $basis = $attributes['price_basis'] ?? null;

        if (! $basis instanceof ListingPriceBasis) {
            $this->invalid('price_basis', 'Choose a supported price basis.');
        }

        $validated['price_basis'] = $basis;
        $organicStatus = $validated['organic_status'] ?? null;
        $validated['organic_status'] = $subject instanceof Ingredient
            ? ($organicStatus instanceof OrganicStatus
                ? $organicStatus
                : OrganicStatus::tryFrom((string) $organicStatus) ?? OrganicStatus::Unknown)
            : OrganicStatus::Unknown;
        $validated['price_recorded_at'] ??= now();
        $validated['net_unit'] = strtolower($validated['net_unit']);
        $priceUnit = $validated['price_unit'] ?? null;
        $validated['price_unit'] = $priceUnit === null
            ? null
            : strtolower($priceUnit);

        if ($subject instanceof PackagingItem) {
            if ($validated['net_unit'] !== 'count') {
                $this->invalid('net_unit', 'Packaging listings must use count units.');
            }

            if (
                $basis === ListingPriceBasis::TotalPurchaseFormat
                && $validated['price_unit'] !== null
            ) {
                $this->invalid('price_unit', 'A total purchase-format price cannot have a price unit.');
            }

            if (
                $basis === ListingPriceBasis::PerUnit
                && ! in_array($validated['price_unit'], [null, 'count'], true)
            ) {
                $this->invalid('price_unit', 'Packaging per-unit prices must use count.');
            }
        }

        return $validated;
    }

    private function hasExactSubject(SupplierListing $listing, Ingredient|PackagingItem $subject): bool
    {
        return $subject instanceof Ingredient
            ? $listing->ingredient_id === $subject->id && $listing->packaging_item_id === null
            : $listing->packaging_item_id === $subject->id && $listing->ingredient_id === null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeStrings(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $attributes[$key] = filled(trim($value)) ? trim($value) : null;
            }
        }

        return $attributes;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
