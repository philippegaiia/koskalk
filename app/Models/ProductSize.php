<?php

namespace App\Models;

use App\Enums\NominalContentUnit;
use App\Models\Concerns\HasPublicId;
use App\Support\NumberLocale;
use Database\Factories\ProductSizeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable presentation of a Product: a maker-declared content and its own
 * packaging. Every size of a Product shares the Product's formula.
 */
#[Fillable([
    'recipe_id',
    'name',
    'sku',
    'declared_content_value',
    'declared_content_unit',
    'position',
    'archived_at',
])]
class ProductSize extends Model
{
    /** @use HasFactory<ProductSizeFactory> */
    use HasFactory;

    use HasPublicId;

    public function label(?string $numberLocale = null): string
    {
        if (filled($this->name)) {
            return $this->name;
        }

        if ($this->declared_content_value !== null && $this->declared_content_unit !== null) {
            return NumberLocale::formatAdaptiveDecimal($this->declared_content_value, 0, 4, $numberLocale)
                .' '.__('workbench.packaging.sizes.units.'.$this->declared_content_unit->value);
        }

        return __('workbench.packaging.sizes.unnamed', ['number' => $this->position]);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function packagingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionPackagingItem::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'declared_content_value' => 'decimal:4',
            'declared_content_unit' => NominalContentUnit::class,
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
