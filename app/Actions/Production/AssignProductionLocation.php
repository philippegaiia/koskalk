<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionLocationSelection;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignProductionLocation
{
    public function __construct(private readonly ProductionBenchAccess $access, private readonly ProductionLocationSelection $locations) {}

    public function handle(User $actor, ProductionRun $production, ?int $locationId): ProductionRun
    {
        return DB::transaction(function () use ($actor, $production, $locationId): ProductionRun {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($production->workspace_id);
            $this->access->assertWritable($actor, $workspace);
            $current = ProductionRun::query()->where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($production->id);
            if (! $workspace->uses_production_locations || ! in_array($current->status, [ProductionRunStatus::Draft, ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages(['production_location_id' => __('locations.validation.production_assignment')]);
            }
            $current->update(['production_location_id' => $this->locations->resolve($workspace, $locationId)]);

            return $current->fresh();
        }, attempts: 5);
    }
}
