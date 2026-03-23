<?php

namespace App\Models;

use Database\Factories\ProductFamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'calculation_basis',
    'is_active',
    'description',
])]
class ProductFamily extends Model
{
    /** @use HasFactory<ProductFamilyFactory> */
    use HasFactory;

    public function ifraCategoryMappings(): HasMany
    {
        return $this->hasMany(ProductFamilyIfraCategory::class);
    }

    public function ifraProductCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            IfraProductCategory::class,
            'product_family_ifra_categories'
        )->withPivot(['is_default', 'sort_order'])->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }
}
