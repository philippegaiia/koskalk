<?php

namespace App\Actions\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\Inventory\StorageLocationSelection;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveMaterialStorageLocation
{
    public function __construct(private readonly ProductionBenchAccess $access, private readonly StorageLocationSelection $locations) {}

    public function handle(User $actor, Workspace $workspace, Ingredient|PackagingItem $subject, ?int $locationId): ?WorkspaceMaterialSetting
    {
        $this->access->assertWritable($actor, $workspace);

        return DB::transaction(function () use ($actor, $workspace, $subject, $locationId): ?WorkspaceMaterialSetting {
            $locked = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertWritable($actor, $locked);
            if (! $locked->uses_storage_locations) {
                throw ValidationException::withMessages(['storage_location_id' => __('locations.validation.disabled')]);
            }
            $subject = ($subject instanceof Ingredient ? Ingredient::withoutGlobalScopes() : PackagingItem::query())
                ->lockForUpdate()->findOrFail($subject->id);
            $allowed = $subject instanceof PackagingItem
                ? (int) $subject->workspace_id === (int) $locked->id
                : (($subject->owner_type === null && $subject->workspace_id === null && $subject->is_active)
                    || (int) $subject->workspace_id === (int) $locked->id || $subject->isOwnedBy($actor));
            if (! $allowed) {
                throw ValidationException::withMessages(['storage_location_id' => __('locations.validation.material')]);
            }
            $id = $this->locations->validate($locked, $locationId);
            $keys = ['workspace_id' => $locked->id, 'ingredient_id' => $subject instanceof Ingredient ? $subject->id : null,
                'packaging_item_id' => $subject instanceof PackagingItem ? $subject->id : null];
            $existing = WorkspaceMaterialSetting::query()->where($keys)->lockForUpdate()->first();
            if ($id === null && $existing?->buffer_quantity === null) {
                $existing?->delete();

                return null;
            }

            return WorkspaceMaterialSetting::query()->updateOrCreate($keys, ['default_storage_location_id' => $id]);
        }, attempts: 5);
    }
}
