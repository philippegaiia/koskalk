<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedCatalogContent;
use Database\Factories\ProductAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'sort_order',
    'is_active',
    'description',
    'translations',
])]
class ProductArea extends Model
{
    /** @use HasFactory<ProductAreaFactory> */
    use HasFactory;

    use HasLocalizedCatalogContent;

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
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
