<?php

namespace App\Models;

use App\Services\MediaStorage;
use Database\Factories\ProductTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_family_id',
    'default_ifra_product_category_id',
    'name',
    'slug',
    'fallback_image_path',
    'sort_order',
    'is_active',
    'description',
])]
class ProductType extends Model
{
    /** @use HasFactory<ProductTypeFactory> */
    use HasFactory;

    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
    }

    public function defaultIfraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class, 'default_ifra_product_category_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function fallbackImageUrl(): ?string
    {
        return MediaStorage::publicUrl($this->fallback_image_path);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }
}
