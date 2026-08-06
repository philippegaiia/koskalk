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
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'workspace_id',
    'recipe_id',
    'recipe_version_id',
    'recipe_name_snapshot',
    'source_formula_version_number',
    'formula_context_snapshot',
    'formula_snapshot_completed_at',
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
    'planning_batch_number',
    'batch_number',
    'batch_number_serial',
    'batch_number_assigned_at',
    'batch_number_assigned_by_user_id',
    'started_at',
    'started_by_user_id',
    'completed_at',
    'completed_by_user_id',
    'aborted_at',
    'aborted_by_user_id',
    'abort_reason',
    'manufacture_date',
    'actual_output_units',
    'actual_output_mass_grams',
    'cost_currency',
    'actual_ingredient_total',
    'actual_packaging_total',
    'actual_total_cost',
    'actual_cost_per_unit',
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

    public function batchNumberAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'batch_number_assigned_by_user_id');
    }

    public function displayIdentifier(): string
    {
        return (string) ($this->batch_number ?? $this->planning_batch_number);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(ProductionRequirement::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function formulaLines(): HasMany
    {
        return $this->hasMany(ProductionFormulaLine::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function displayRecipeName(): string
    {
        return $this->recipe_name_snapshot
            ?? $this->recipe?->name
            ?? __('production_bench.production.unknown_product');
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

    public function consumption(): HasMany
    {
        return $this->hasMany(ProductionConsumption::class)
            ->orderBy('id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(ProductionJournalEntry::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function outputLot(): HasOne
    {
        return $this->hasOne(StockLot::class, 'production_run_id');
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
            'source_formula_version_number' => 'integer',
            'formula_context_snapshot' => 'array',
            'formula_snapshot_completed_at' => 'datetime',
            'batch_number_serial' => 'integer',
            'batch_number_assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'aborted_at' => 'datetime',
            'manufacture_date' => 'date',
            'actual_output_units' => 'integer',
            'actual_output_mass_grams' => 'decimal:9',
            'actual_ingredient_total' => 'decimal:9',
            'actual_packaging_total' => 'decimal:9',
            'actual_total_cost' => 'decimal:9',
            'actual_cost_per_unit' => 'decimal:9',
            'cancelled_at' => 'datetime',
        ];
    }
}
