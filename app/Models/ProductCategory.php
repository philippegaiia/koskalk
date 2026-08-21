<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedCatalogContent;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_area_id',
    'name',
    'slug',
    'sort_order',
    'is_active',
    'description',
    'translations',
])]
class ProductCategory extends Model
{
    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory;

    use HasLocalizedCatalogContent;

    public function productArea(): BelongsTo
    {
        return $this->belongsTo(ProductArea::class);
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(ProductType::class);
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'bool',
            'translations' => 'array',
        ];
    }
}
