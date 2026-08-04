<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductionTaskSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'is_active'])]
class ProductionTaskSet extends Model
{
    /** @use HasFactory<ProductionTaskSetFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductionTaskSetItem::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    public function defaultRecipes(): HasMany
    {
        return $this->hasMany(Recipe::class, 'default_production_task_set_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
