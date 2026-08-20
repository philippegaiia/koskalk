<?php

namespace App\Models;

use Database\Factories\ProductFamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function productTypes(): BelongsToMany
    {
        return $this->belongsToMany(ProductType::class)->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
        ];
    }
}
