<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'recipe_version_id',
    'user_id',
    'oil_weight_for_costing',
    'oil_unit_for_costing',
    'units_produced',
    'currency',
])]
/**
 * Holds the saved costing context for one user on one formula version.
 *
 * Created lazily when the user first opens the Costing tab. Stores the batch size
 * override, units produced, and currency — everything needed to run costing math
 * independently from the formula settings.
 *
 * @property int $id
 * @property int $recipe_version_id
 * @property int $user_id
 * @property string|null $oil_weight_for_costing
 * @property string $oil_unit_for_costing
 * @property int|null $units_produced
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RecipeVersionCostingItem> $items
 * @property-read Collection<int, RecipeVersionCostingPackagingItem> $packagingItems
 * @property-read RecipeVersion $recipeVersion
 * @property-read User $user
 */
class RecipeVersionCosting extends Model
{
    /** The recipe version this costing belongs to. */
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    /** The user who owns this costing. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ingredient price rows currently saved in this costing, ordered by phase then position. */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingItem::class)->orderBy('phase_key')->orderBy('position');
    }

    /** Packaging rows attached to this costing, ordered by creation order. */
    public function packagingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingPackagingItem::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'oil_weight_for_costing' => 'decimal:3',
            'units_produced' => 'integer',
        ];
    }
}
