<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedCatalogContent;
use Database\Factories\IfraProductCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'short_name',
    'description',
    'is_active',
    'translations',
])]
class IfraProductCategory extends Model
{
    /** @use HasFactory<IfraProductCategoryFactory> */
    use HasFactory;

    use HasLocalizedCatalogContent;

    public function certificateLimits(): HasMany
    {
        return $this->hasMany(IfraCertificateLimit::class);
    }

    public function productTypeMappings(): HasMany
    {
        return $this->hasMany(ProductTypeIfraCategory::class);
    }

    public function optionLabel(): string
    {
        $label = $this->localizedShortName() ?: $this->localizedName();

        return sprintf('%s - %s', $this->code, $label);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'translations' => 'array',
        ];
    }
}
