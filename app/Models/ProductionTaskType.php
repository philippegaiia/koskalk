<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\ProductionTaskTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['workspace_id', 'name', 'default_duration_minutes', 'colour', 'department_id', 'is_active'])]
class ProductionTaskType extends Model
{
    /** @use HasFactory<ProductionTaskTypeFactory> */
    use HasFactory;

    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function taskSetItems(): HasMany
    {
        return $this->hasMany(ProductionTaskSetItem::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
