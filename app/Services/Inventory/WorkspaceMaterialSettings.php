<?php

namespace App\Services\Inventory;

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMaterialSetting;
use App\Services\ProductionBenchAccess;
use Illuminate\Support\Facades\DB;

class WorkspaceMaterialSettings
{
    public function __construct(
        private readonly ProductionBenchAccess $access,
    ) {}

    public function synchronize(
        User $actor,
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        ?string $bufferQuantity,
    ): ?WorkspaceMaterialSetting {
        $keys = [
            'workspace_id' => $workspace->id,
            'ingredient_id' => $subject instanceof Ingredient ? $subject->id : null,
            'packaging_item_id' => $subject instanceof PackagingItem ? $subject->id : null,
        ];

        return DB::transaction(function () use ($actor, $workspace, $bufferQuantity, $keys): ?WorkspaceMaterialSetting {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);

            // Re-assert against the row locked by this transaction. Production
            // Bench entitlement changes take the same workspace lock first, so a
            // cancellation and a buffer write cannot pass authorization concurrently.
            $this->access->assertWritable($actor, $lockedWorkspace);

            $existing = WorkspaceMaterialSetting::query()
                ->withoutGlobalScopes()
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
