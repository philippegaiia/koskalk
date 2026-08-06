<?php

namespace App\Models;

use App\ProductionRequirementKind;
use Database\Factories\ProductionRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'production_run_id',
    'ingredient_id',
    'packaging_item_id',
    'recipe_item_id',
    'recipe_version_packaging_item_id',
    'kind',
    'required_mass_grams',
    'required_units',
    'subject_name_snapshot',
    'phase_key_snapshot',
    'phase_name_snapshot',
    'percentage_snapshot',
    'components_per_unit_snapshot',
    'unit_snapshot',
    'note_snapshot',
    'sort_order',
])]
class ProductionRequirement extends Model
{
    /** @use HasFactory<ProductionRequirementFactory> */
    use HasFactory;

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class)->withoutGlobalScopes();
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(PackagingItem::class);
    }

    public function recipeItem(): BelongsTo
    {
        return $this->belongsTo(RecipeItem::class)->withoutGlobalScopes();
    }

    public function recipeVersionPackagingItem(): BelongsTo
    {
        return $this->belongsTo(RecipeVersionPackagingItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    protected function casts(): array
    {
        return [
            'kind' => ProductionRequirementKind::class,
            'required_mass_grams' => 'decimal:9',
            'required_units' => 'integer',
            'percentage_snapshot' => 'decimal:9',
            'components_per_unit_snapshot' => 'decimal:9',
            'sort_order' => 'integer',
        ];
    }
}
