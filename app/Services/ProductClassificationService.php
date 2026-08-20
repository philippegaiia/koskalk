<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Models\Recipe;
use Illuminate\Validation\ValidationException;

final class ProductClassificationService
{
    public function resolveForSave(
        ProductFamily $family,
        ?int $productTypeId,
        ?Recipe $product,
    ): ?ProductType {
        if ($product instanceof Recipe && $product->product_family_id !== $family->id) {
            throw ValidationException::withMessages([
                'product_type_id' => 'The formula engine cannot be changed for an existing Product.',
            ]);
        }

        if ($productTypeId === null) {
            if ($product instanceof Recipe && $product->product_type_id === null) {
                return null;
            }

            throw ValidationException::withMessages([
                'product_type_id' => $product instanceof Recipe
                    ? 'The Product Type cannot be cleared after it has been selected.'
                    : 'Choose a Product Type before saving the Product.',
            ]);
        }

        $productType = ProductType::query()
            ->with('productFamilies:id')
            ->find($productTypeId);

        if (! $productType instanceof ProductType) {
            throw ValidationException::withMessages([
                'product_type_id' => 'Choose a valid Product Type.',
            ]);
        }

        if (! $productType->is_active
            && (! $product instanceof Recipe || $product->product_type_id !== $productType->id)) {
            throw ValidationException::withMessages([
                'product_type_id' => 'Choose an active Product Type.',
            ]);
        }

        if (! $productType->productFamilies->contains('id', $family->id)) {
            throw ValidationException::withMessages([
                'product_type_id' => 'The selected Product Type is not compatible with this formula engine.',
            ]);
        }

        if ($product instanceof Recipe
            && $product->product_type_id !== null
            && $product->product_type_id !== $productType->id
            && $product->hasSavedFormula()) {
            throw ValidationException::withMessages([
                'product_type_id' => 'The Product Type cannot be changed after the first Saved Formula.',
            ]);
        }

        return $productType;
    }
}
