<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\StorageLocation;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use Illuminate\Validation\ValidationException;

class StorageLocationSelection
{
    public function defaultFor(Workspace $workspace, Ingredient|PackagingItem $subject): ?int
    {
        if (! $workspace->uses_storage_locations) {
            return null;
        }
        $id = WorkspaceMaterialSetting::query()->where('workspace_id', $workspace->id)
            ->where($subject instanceof Ingredient ? 'ingredient_id' : 'packaging_item_id', $subject->id)
            ->value('default_storage_location_id');

        return StorageLocation::query()->where('workspace_id', $workspace->id)->where('is_active', true)->whereKey($id)->value('id');
    }

    /** @param array{storage_location_id?: int|string|null} $input */
    public function resolve(Workspace $workspace, Ingredient|PackagingItem $subject, array $input): ?int
    {
        if (! $workspace->uses_storage_locations) {
            return null;
        }

        return array_key_exists('storage_location_id', $input)
            ? $this->validate($workspace, $input['storage_location_id'])
            : $this->defaultFor($workspace, $subject);
    }

    public function validate(Workspace $workspace, mixed $locationId): ?int
    {
        if (! filled($locationId)) {
            return null;
        }
        if (filter_var($locationId, FILTER_VALIDATE_INT) === false
            || ! StorageLocation::query()->where('workspace_id', $workspace->id)->where('is_active', true)->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['storage_location_id' => __('locations.validation.storage_location')]);
        }

        return (int) $locationId;
    }
}
