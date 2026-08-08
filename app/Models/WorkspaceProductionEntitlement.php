<?php

namespace App\Models;

use App\Enums\ProductionBenchEntitlementStatus;
use Database\Factories\WorkspaceProductionEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'status',
    'activated_at',
    'cancelled_at',
    'archive_eligible_at',
])]
class WorkspaceProductionEntitlement extends Model
{
    /** @use HasFactory<WorkspaceProductionEntitlementFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => ProductionBenchEntitlementStatus::Active->value,
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    protected function casts(): array
    {
        return [
            'status' => ProductionBenchEntitlementStatus::class,
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archive_eligible_at' => 'datetime',
        ];
    }
}
