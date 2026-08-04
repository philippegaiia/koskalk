<?php

namespace App\Models;

use Database\Factories\ProductionTaskSetItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'production_task_set_id',
    'production_task_type_id',
    'position',
    'days_after_production',
    'duration_minutes',
])]
class ProductionTaskSetItem extends Model
{
    /** @use HasFactory<ProductionTaskSetItemFactory> */
    use HasFactory;

    public function taskSet(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskSet::class, 'production_task_set_id');
    }

    public function taskType(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskType::class, 'production_task_type_id');
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'days_after_production' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }
}
