<?php

namespace App\Models;

use App\MassUnit;
use App\Models\Concerns\HasPublicId;
use App\ProductionBasisKind;
use App\ProductionRunSource;
use App\ProductionRunStatus;
use Database\Factories\ProductionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'workspace_id',
    'recipe_id',
    'recipe_version_id',
    'production_task_set_id',
    'status',
    'source',
    'planned_for',
    'basis_kind',
    'basis_quantity_grams',
    'basis_input_value',
    'basis_input_unit',
    'expected_units',
    'notes',
    'idempotency_key',
    'created_by_user_id',
    'cancelled_at',
    'cancelled_by_user_id',
    'cancellation_reason',
])]
class ProductionRun extends Model
{
    /** @use HasFactory<ProductionRunFactory> */
    use HasFactory;

    use HasPublicId;

    protected $attributes = [
        'status' => ProductionRunStatus::Draft->value,
        'source' => ProductionRunSource::Direct->value,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withoutGlobalScopes();
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class)->withoutGlobalScopes();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProductionRequirement::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function taskSet(): BelongsTo
    {
        return $this->belongsTo(ProductionTaskSet::class, 'production_task_set_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class)
            ->orderBy('scheduled_for')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'status' => ProductionRunStatus::class,
            'source' => ProductionRunSource::class,
            'planned_for' => 'date',
            'basis_kind' => ProductionBasisKind::class,
            'basis_quantity_grams' => 'decimal:9',
            'basis_input_value' => 'decimal:9',
            'basis_input_unit' => MassUnit::class,
            'expected_units' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }
}
