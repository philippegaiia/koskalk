<?php

namespace App\Actions\Inventory;

use App\Models\ProductionDocument;
use App\Models\ProductionRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Production\ProductionEditingContext;
use App\Services\Production\ProductionMutationResult;
use App\Services\ProductionBenchAccess;
use App\Services\ProductionMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DetachProductionDocument
{
    public function __construct(private readonly ProductionMutationGuard $guard, private readonly ProductionBenchAccess $access) {}

    public function handle(User $actor, ProductionDocument $document, ?ProductionEditingContext $editing = null): void
    {
        $workspace = Workspace::withoutGlobalScopes()->find($document->workspace_id);

        abort_unless($workspace instanceof Workspace, 404);

        $this->access->assertCanConfigure($actor, $workspace);

        $reference = ProductionDocument::query()->where('workspace_id', $workspace->id)->findOrFail($document->id);
        if ($reference->documentable_type === (new ProductionRun)->getMorphClass()) {
            $parentId = (int) $reference->documentable_id;
            $this->guard->run($actor, [$parentId], $editing,
                function (User $actor, Workspace $workspace, Collection $productions) use ($document, $parentId): ProductionMutationResult {
                    $this->access->assertCanConfigure($actor, $workspace);
                    $current = ProductionDocument::query()->where('workspace_id', $workspace->id)
                        ->where('documentable_type', $productions[$parentId]->getMorphClass())
                        ->where('documentable_id', $parentId)->lockForUpdate()->findOrFail($document->id);
                    $current->delete();

                    return new ProductionMutationResult(null, [$parentId]);
                });

            return;
        }

        DB::transaction(function () use ($actor, $workspace, $document): void {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $this->access->assertCanConfigure($actor, $lockedWorkspace);
            ProductionDocument::query()->where('workspace_id', $lockedWorkspace->id)->lockForUpdate()->findOrFail($document->id)->delete();
        }, attempts: 5);
    }
}
