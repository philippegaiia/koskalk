<?php

namespace App\Models;

use App\SoapSap;
use Database\Factories\ProductFamilyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'name',
    'slug',
    'calculation_basis',
    'cured_water_percentage',
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

    /**
     * Residual water used for new formulas of this family; saved versions keep
     * the value they were calculated with.
     */
    public function curedWaterPercentage(): float
    {
        return SoapSap::curedWaterPercentage($this->cured_water_percentage);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'cured_water_percentage' => 'decimal:2',
        ];
    }
}
