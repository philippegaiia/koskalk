<?php

namespace App\Models;

use App\Casts\OriginalFilename;
use App\Enums\ProductTypeIllustration;
use App\Models\Concerns\HasLocalizedCatalogContent;
use App\Services\MediaStorage;
use Database\Factories\ProductTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_category_id',
    'name',
    'slug',
    'fallback_image_path',
    'fallback_image_original_name',
    'sort_order',
    'is_active',
    'description',
    'translations',
])]
class ProductType extends Model
{
    /** @use HasFactory<ProductTypeFactory> */
    use HasFactory;

    use HasLocalizedCatalogContent;

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function productFamilies(): BelongsToMany
    {
        return $this->belongsToMany(ProductFamily::class)->withTimestamps();
    }

    public function ifraCategoryMappings(): HasMany
    {
        return $this->hasMany(ProductTypeIfraCategory::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function fallbackImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->fallback_image_path);
    }

    /**
     * Reads the category and area through their relations, so callers listing
     * many types should eager-load productCategory.productArea.
     */
    public function illustration(): ProductTypeIllustration
    {
        return ProductTypeIllustration::resolve(
            $this->slug,
            $this->productCategory?->slug,
            $this->productCategory?->productArea?->slug,
        );
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'fallback_image_original_name' => OriginalFilename::class,
            'translations' => 'array',
        ];
    }
}
