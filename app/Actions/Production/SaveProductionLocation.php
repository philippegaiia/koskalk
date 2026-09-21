<?php

namespace App\Actions\Production;

use App\Models\ProductionLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveProductionLocation
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, string $name, int|string $dailyProductionLimit, bool $isActive = true, ?ProductionLocation $location = null): ProductionLocation
    {
        $this->access->assertWritable($actor, $workspace);
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:50']], ['name.max' => __('production_bench.validation.location_name_max')])->validate();
        validator(['daily_production_limit' => $dailyProductionLimit], ['daily_production_limit' => ['required', 'integer', 'min:1', 'max:1000']])->validate();

        return DB::transaction(function () use ($actor, $workspace, $name, $isActive, $location, $dailyProductionLimit): ProductionLocation {
            $locked = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $locked);
            if (! $locked->uses_production_locations) {
                throw ValidationException::withMessages(['location' => __('locations.validation.disabled')]);
            }
            $current = $location === null ? null : ProductionLocation::query()->where('workspace_id', $locked->id)->lockForUpdate()->findOrFail($location->id);
            Gate::forUser($actor)->authorize($current ? 'update' : 'create', $current ?? [ProductionLocation::class, $locked]);
            $normalized = mb_strtolower($name);
            if (ProductionLocation::query()->where('workspace_id', $locked->id)->where('normalized_name', $normalized)
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                throw ValidationException::withMessages(['name' => __('locations.validation.duplicate')]);
            }
            $values = ['workspace_id' => $locked->id, 'name' => $name, 'normalized_name' => $normalized, 'is_active' => $isActive, 'daily_production_limit' => (int) $dailyProductionLimit];
            if ($current !== null) {
                $current->update($values);

                return $current->fresh();
            }

            return ProductionLocation::query()->create($values);
        }, attempts: 5);
    }
}
