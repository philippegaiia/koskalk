<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'recipe_version_id',
    'user_packaging_item_id',
    'name',
    'components_per_unit',
    'notes',
    'position',
])]
/**
 * Captures the packaging plan that belongs to a recipe version.
 *
 * Packaging plan rows define the components needed for one finished unit. They
 * are recipe structure, while prices remain in the user's costing context.
 *
 * @property int $id
 * @property int $recipe_version_id
 * @property int|null $user_packaging_item_id
 * @property string $name
 * @property string $components_per_unit
 * @property string|null $notes
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RecipeVersion $recipeVersion
 * @property-read UserPackagingItem|null $packagingItem
 */
class RecipeVersionPackagingItem extends Model
{
    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(UserPackagingItem::class, 'user_packaging_item_id');
    }

    protected function casts(): array
    {
        return [
            'components_per_unit' => 'decimal:3',
            'position' => 'integer',
        ];
    }
}
