<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductionTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'production_run_id',
    'production_task_set_id',
    'production_task_set_item_id',
    'employee_id',
    'name_snapshot',
    'days_after_production',
    'duration_minutes',
    'scheduled_for',
    'scheduling_mode',
    'completed_at',
])]
class ProductionTask extends Model
{
    /** @use HasFactory<ProductionTaskFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function productionRun(): BelongsTo
    {
        return $this->belongsTo(ProductionRun::class);
    }

    public function taskSet(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskSet::class, 'production_task_set_id');
    }

    public function taskSetItem(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskSetItem::class, 'production_task_set_item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function casts(): array
    {
        return [
            'days_after_production' => 'integer',
            'duration_minutes' => 'integer',
            'scheduled_for' => 'date',
            'completed_at' => 'datetime',
        ];
    }
}
