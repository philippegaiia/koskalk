<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'normalized_name', 'is_active'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'department_employee')
            ->withTimestamps();
    }

    public function productionTaskTypes(): HasMany
    {
        return $this->hasMany(ProductionTaskType::class);
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
