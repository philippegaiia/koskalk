<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'recipe_version_costing_id',
    'user_packaging_item_id',
    'name',
    'unit_cost',
    'quantity',
])]
/**
 * Captures packaging rows copied into a formula costing, independently from the catalog.
 *
 * Name and unit_cost are snapshotted so the costing remains readable even if the
 * user later edits or deletes the source catalog item. The optional user_packaging_item_id
 * link lets the UI suggest updates but is not required for the costing to display correctly.
 *
 * @property int $id
 * @property int $recipe_version_costing_id
 * @property int|null $user_packaging_item_id
 * @property string $name
 * @property string $unit_cost
 * @property string $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read RecipeVersionCosting $costing
 * @property-read UserPackagingItem|null $packagingItem
 */
class RecipeVersionCostingPackagingItem extends Model
{
    /** The parent costing session this packaging row belongs to. */
    public function costing(): BelongsTo
    {
        return $this->belongsTo(RecipeVersionCosting::class, 'recipe_version_costing_id');
    }

    /** The reusable catalog item this row was sourced from, if any. */
    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(UserPackagingItem::class, 'user_packaging_item_id');
    }

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:4',
            'quantity' => 'decimal:3',
        ];
    }
}
