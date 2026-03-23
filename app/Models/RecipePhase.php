<?php

namespace App\Models;

use App\Models\Concerns\HasTenantOwnership;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\OwnerType;
use App\Visibility;
use Database\Factories\RecipePhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'recipe_version_id',
    'owner_type',
    'owner_id',
    'workspace_id',
    'visibility',
    'name',
    'slug',
    'phase_type',
    'sort_order',
    'is_system',
])]
class RecipePhase extends Model
{
    /** @use HasFactory<RecipePhaseFactory> */
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

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'is_system' => 'bool',
        ];
    }
}
