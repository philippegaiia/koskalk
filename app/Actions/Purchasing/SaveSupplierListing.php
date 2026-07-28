<?php

namespace App\Actions\Purchasing;

use App\ListingPriceBasis;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierListing;
use App\Models\User;
use App\Models\UserPackagingItem;
use App\Models\Workspace;
use App\OwnerType;
use App\Services\ProductionBenchAccess;
use App\Services\SupplierListingPriceCalculator;
use App\StockUnitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveSupplierListing
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
        private readonly SupplierListingPriceCalculator $priceCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        User $actor,
        Workspace $workspace,
        Supplier $supplier,
        Ingredient|UserPackagingItem $subject,
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

            $data = $this->validatedAttributes($attributes, $currentSupplier, $currentSubject);
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
                'user_packaging_item_id' => $currentSubject instanceof UserPackagingItem ? $currentSubject->id : null,
                ...$data,
                'unit_kind' => $currentSubject instanceof Ingredient ? StockUnitKind::Mass : StockUnitKind::Count,
                'canonical_quantity_per_purchase_format' => $prices['canonical_quantity'],
                'price_unit' => $subject instanceof Ingredient
                    ? $data['price_unit']
                    : ($data['price_basis'] === ListingPriceBasis::PerUnit ? 'count' : null),
                'total_price' => $prices['total_price'],
            ];

            if (! $currentListing instanceof SupplierListing) {
                return SupplierListing::query()->create([
                    'workspace_id' => $workspace->id,
                    ...$values,
                ]);
            }

            $currentListing->update($values);

            return $currentListing->refresh();
        }, attempts: 5);
    }

    private function existingSubject(Ingredient|UserPackagingItem $subject): Ingredient|UserPackagingItem
    {
        $currentSubject = $subject instanceof Ingredient
            ? Ingredient::query()->find($subject->id)
            : UserPackagingItem::query()->find($subject->id);

        if (! $currentSubject instanceof Ingredient && ! $currentSubject instanceof UserPackagingItem) {
            $this->invalid('subject', 'Choose an existing ingredient or packaging item.');
        }

        return $currentSubject;
    }

    private function assertSubjectIsAccessible(
        User $actor,
        Workspace $workspace,
        Ingredient|UserPackagingItem $subject,
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

        if ($subject instanceof UserPackagingItem && $subject->user_id !== $workspace->owner_user_id) {
            $this->invalid('packaging_item', 'The packaging item must belong to the workspace owner.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validatedAttributes(
        array $attributes,
        Supplier $supplier,
        Ingredient|UserPackagingItem $subject,
    ): array {
        $data = $this->normalizeStrings($attributes);
        $data['currency'] = strtoupper((string) ($data['currency'] ?? $supplier->default_currency));

        $validated = Validator::make($data, [
            'purchase_format' => ['required', 'string', 'max:255'],
            'net_quantity' => ['required', 'string', 'max:255'],
            'net_unit' => ['required', 'string', 'max:24'],
            'price_amount' => ['required', 'string', 'max:255'],
            'price_unit' => ['nullable', 'string', 'max:24'],
            'supplier_sku' => ['nullable', 'string', 'max:255'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'container' => ['nullable', 'string', 'max:255'],
            'minimum_packs' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'currency' => ['required', 'alpha:ascii', 'size:3'],
            'price_recorded_at' => ['nullable', 'date'],
        ])->validate();

        $basis = $attributes['price_basis'] ?? null;

        if (! $basis instanceof ListingPriceBasis) {
            $this->invalid('price_basis', 'Choose a supported price basis.');
        }

        $validated['price_basis'] = $basis;
        $validated['price_recorded_at'] ??= now();
        $validated['net_unit'] = strtolower($validated['net_unit']);
        $priceUnit = $validated['price_unit'] ?? null;
        $validated['price_unit'] = $priceUnit === null
            ? null
            : strtolower($priceUnit);

        if ($subject instanceof UserPackagingItem) {
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

    private function hasExactSubject(SupplierListing $listing, Ingredient|UserPackagingItem $subject): bool
    {
        return $subject instanceof Ingredient
            ? $listing->ingredient_id === $subject->id && $listing->user_packaging_item_id === null
            : $listing->user_packaging_item_id === $subject->id && $listing->ingredient_id === null;
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
