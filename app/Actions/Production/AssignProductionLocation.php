<?php

namespace App\Actions\Production;

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionLocationSelection;
use App\Services\Production\ProductionMutationResult;
use App\Services\Production\ProductionMutationScope;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AssignProductionLocation
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access, private readonly ProductionLocationSelection $locations) {}

    public function handle(User $actor, ProductionRun $production, ?int $locationId,
        ?ProductionEditingContext $editing = null,
    ): ProductionRun {
        $this->access->assertWritable($actor, $production->workspace);

        return $this->guard->run($actor, [$production->id], $editing, function (User $actor, Workspace $lockedWorkspace, Collection $productions, ProductionMutationScope $scope) use ($production, $locationId): ProductionMutationResult {
            $workspace = $lockedWorkspace;
            $this->access->assertWritable($actor, $workspace);
            $current = $productions[$production->id];
            if (! $workspace->uses_production_locations || ! in_array($current->status, [ProductionRunStatus::Draft, ProductionRunStatus::Scheduled, ProductionRunStatus::Reserved], true)) {
                throw ValidationException::withMessages(['production_location_id' => __('locations.validation.production_assignment')]);
            }
            $resolved = $this->locations->resolve($workspace, $locationId);
            $changed = $current->production_location_id !== $resolved;
            if ($changed) {
                $current->update(['production_location_id' => $resolved]);
            }

            return new ProductionMutationResult($current->fresh(), $changed ? [$current->id] : []);
        });
    }
}
