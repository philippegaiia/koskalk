<?php

namespace App\Models;

use App\Models\Concerns\HasTenantOwnership;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\OwnerType;
use App\Visibility;
use Database\Factories\RecipeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'recipe_version_id',
    'recipe_phase_id',
    'ingredient_id',
    'ingredient_version_id',
    'owner_type',
    'owner_id',
    'workspace_id',
    'visibility',
    'position',
    'percentage',
    'weight',
    'note',
])]
class RecipeItem extends Model
{
    /** @use HasFactory<RecipeItemFactory> */
    use HasFactory;

    use HasTenantOwnership;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByCurrentTenantScope);
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function recipePhase(): BelongsTo
    {
        return $this->belongsTo(RecipePhase::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function ingredientVersion(): BelongsTo
    {
        return $this->belongsTo(IngredientVersion::class);
    }

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'percentage' => 'decimal:4',
            'weight' => 'decimal:4',
        ];
    }
}
