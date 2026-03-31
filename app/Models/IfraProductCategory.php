<?php

namespace App\Models;

use Database\Factories\IfraProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'short_name',
    'description',
    'is_active',
])]
class IfraProductCategory extends Model
{
    /** @use HasFactory<IfraProductCategoryFactory> */
    use HasFactory;

    public function certificateLimits(): HasMany
    {
        return $this->hasMany(IfraCertificateLimit::class);
    }

    public function productFamilyMappings(): HasMany
    {
        return $this->hasMany(ProductFamilyIfraCategory::class);
    }

    public function productFamilies(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductFamily::class,
            'product_family_ifra_categories'
        )->withPivot(['is_default', 'sort_order'])->withTimestamps();
    }

    public function optionLabel(): string
    {
        $label = $this->short_name ?: $this->name;

        return sprintf('%s - %s', $this->code, $label);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }
}
