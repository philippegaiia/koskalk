<?php

namespace App\Models;

use App\Enums\SoapProcess;
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
    'soap_process',
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

    /**
     * Liquid soap paste families open their own bench, never the soap or
     * cosmetic workbench.
     */
    public function usesLiquidSoapPasteBench(): bool
    {
        return $this->soap_process === SoapProcess::LiquidPaste;
    }

    /**
     * Soap behaviour (alkali costing, save checks, soap help) has always keyed
     * on the soap family; liquid soap paste families share it.
     */
    public function usesSoapCalculation(): bool
    {
        return $this->slug === 'soap' || $this->usesLiquidSoapPasteBench();
    }

    /**
     * A liquid soap paste family opens its formula view only once active,
     * so the bench stays hidden until it is released.
     */
    public function opensInWorkbench(): bool
    {
        return ! $this->usesLiquidSoapPasteBench() || $this->is_active;
    }

    protected function casts(): array
    {
        return [
            'soap_process' => SoapProcess::class,
            'is_active' => 'bool',
            'cured_water_percentage' => 'decimal:2',
        ];
    }
}
