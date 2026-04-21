<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'user_id',
    'name',
    'unit_cost',
    'currency',
    'notes',
])]
/**
 * Stores reusable packaging items that can be pulled into a formula costing.
 *
 * Each user builds their own catalog of packaging materials (boxes, labels, jars, etc.)
 * with an effective unit price. When a packaging item is added to a recipe costing, its
 * name and price are snapshotted into the costing row so historical costings stay accurate.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $unit_cost
 * @property string $currency
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, RecipeVersionCostingPackagingItem> $costingItems
 * @property-read Collection<int, RecipeVersionPackagingItem> $recipeVersionPackagingItems
 */
class UserPackagingItem extends Model
{
    /** The user who owns this packaging catalog item. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** All costing rows that reference this catalog item. */
    public function costingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionCostingPackagingItem::class);
    }

    /** Recipe-version packaging plan rows that reference this catalog item. */
    public function recipeVersionPackagingItems(): HasMany
    {
        return $this->hasMany(RecipeVersionPackagingItem::class);
    }

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:4',
        ];
    }
}
