<?php

namespace App\Actions\Inventory;

use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveStorageLocation
{
    public function __construct(private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, Workspace $workspace, string $name, bool $isActive = true, ?StorageLocation $location = null): StorageLocation
    {
        $this->access->assertWritable($actor, $workspace);
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
        validator(['name' => $name], ['name' => ['required', 'string', 'max:120']])->validate();

        return DB::transaction(function () use ($actor, $workspace, $name, $isActive, $location): StorageLocation {
            $locked = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $locked);
            if (! $locked->uses_storage_locations) {
                throw ValidationException::withMessages(['location' => __('locations.validation.disabled')]);
            }
            $current = $location === null ? null : StorageLocation::query()->where('workspace_id', $locked->id)->lockForUpdate()->findOrFail($location->id);
            Gate::forUser($actor)->authorize($current ? 'update' : 'create', $current ?? [StorageLocation::class, $locked]);
            $normalized = mb_strtolower($name);
            if (StorageLocation::query()->where('workspace_id', $locked->id)->where('normalized_name', $normalized)
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                throw ValidationException::withMessages(['name' => __('locations.validation.duplicate')]);
            }
            $values = ['workspace_id' => $locked->id, 'name' => $name, 'normalized_name' => $normalized, 'is_active' => $isActive];
            if ($current !== null) {
                $current->update($values);

                return $current->fresh();
            }

            return StorageLocation::query()->create($values);
        }, attempts: 5);
    }
}
