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
            // .ai/rules/app.md: re-assert access inside the transaction. The action
            // checks before calling, but the entitlement can be cancelled between
            // that check and this write. ProductionBenchAccess reads entitlement
            // rows rather than workspace attributes, so asserting here sees the
            // current state. No workspace row lock is taken: this is a single-row
            // upsert already serialised by lockForUpdate() below, and locking the
            // workspace would serialise every buffer edit in the workspace.
            $this->access->assertWritable($actor, $workspace);

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
