<?php

namespace App\Models;

use App\Models\Concerns\HasTenantOwnership;
use App\Models\Scopes\OwnedByCurrentTenantScope;
use App\OwnerType;
use App\Visibility;
use Database\Factories\RecipeVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'recipe_id',
    'owner_type',
    'owner_id',
    'workspace_id',
    'visibility',
    'version_number',
    'is_draft',
    'name',
    'batch_size',
    'batch_unit',
    'manufacturing_mode',
    'exposure_mode',
    'regulatory_regime',
    'ifra_product_category_id',
    'notes',
    'water_settings',
    'calculation_context',
    'saved_at',
    'catalog_reviewed_at',
    'archived_at',
])]
class RecipeVersion extends Model
{
    /** @use HasFactory<RecipeVersionFactory> */
    use HasFactory;

    use HasTenantOwnership;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByCurrentTenantScope);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ifraProductCategory(): BelongsTo
    {
        return $this->belongsTo(IfraProductCategory::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(RecipePhase::class)->orderBy('sort_order');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class)->orderBy('position');
    }

    public function costings(): HasMany
    {
        return $this->hasMany(RecipeVersionCosting::class);
    }

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'visibility' => Visibility::class,
            'is_draft' => 'bool',
            'batch_size' => 'decimal:3',
            'water_settings' => 'array',
            'calculation_context' => 'array',
            'saved_at' => 'datetime',
            'catalog_reviewed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
