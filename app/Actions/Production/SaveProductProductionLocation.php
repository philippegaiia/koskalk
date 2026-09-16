<?php

namespace App\Actions\Production;

use App\Models\Recipe;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionLocationSelection;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveProductProductionLocation
{
    public function __construct(private readonly ProductionBenchAccess $access, private readonly ProductionLocationSelection $locations) {}

    public function handle(User $actor, Workspace $workspace, Recipe $recipe, ?int $locationId): Recipe
    {
        return DB::transaction(function () use ($actor, $workspace, $recipe, $locationId): Recipe {
            $locked = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $locked);
            $current = Recipe::withoutGlobalScopes()->where('workspace_id', $locked->id)->lockForUpdate()->findOrFail($recipe->id);
            Gate::forUser($actor)->authorize('update', $current);
            if (! $locked->uses_production_locations) {
                throw ValidationException::withMessages(['production_location_id' => __('locations.validation.disabled')]);
            }
            $current->update(['default_production_location_id' => $this->locations->resolve($locked, $locationId)]);

            return $current->fresh();
        }, attempts: 5);
    }
}
