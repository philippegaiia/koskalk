<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use Illuminate\Support\Facades\DB;

class WorkspaceMaterialSettings
{
    public function synchronize(
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        ?string $bufferQuantity,
    ): ?WorkspaceMaterialSetting {
        $keys = [
            'workspace_id' => $workspace->id,
            'ingredient_id' => $subject instanceof Ingredient ? $subject->id : null,
            'packaging_item_id' => $subject instanceof PackagingItem ? $subject->id : null,
        ];

        return DB::transaction(function () use ($bufferQuantity, $keys): ?WorkspaceMaterialSetting {
            $existing = WorkspaceMaterialSetting::query()
                ->where($keys)
                ->lockForUpdate()
                ->first();

            if ($bufferQuantity === null) {
                $existing?->delete();

                return null;
            }

            return WorkspaceMaterialSetting::query()->updateOrCreate(
                $keys,
                ['buffer_quantity' => bcadd($bufferQuantity, '0', 9)],
            );
        }, attempts: 5);
    }
}
