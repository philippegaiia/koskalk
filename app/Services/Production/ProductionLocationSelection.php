<?php

namespace App\Services\Production;

use App\Models\ProductionLocation;
use App\Models\Recipe;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class ProductionLocationSelection
{
    public function resolve(Workspace $workspace, mixed $locationId): ?int
    {
        if (! $workspace->uses_production_locations || ! filled($locationId)) {
            return null;
        }
        if (filter_var($locationId, FILTER_VALIDATE_INT) === false
            || ! ProductionLocation::query()->where('workspace_id', $workspace->id)->where('is_active', true)->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['production_location_id' => __('locations.validation.production_location')]);
        }

        return (int) $locationId;
    }

    public function defaultFor(Workspace $workspace, Recipe $recipe): ?int
    {
        if (! $workspace->uses_production_locations || (int) $recipe->workspace_id !== (int) $workspace->id) {
            return null;
        }

        return ProductionLocation::query()->where('workspace_id', $workspace->id)->where('is_active', true)
            ->whereKey($recipe->default_production_location_id)->value('id');
    }
}
