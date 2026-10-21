<?php

namespace App\Services;

use App\Enums\WorkspaceModule;
use App\Models\User;
use App\Models\Workspace;

/**
 * One supplier directory per workspace, shared by Production Bench and the DIP module.
 * Purchasing (listings, prices, orders, stock) stays behind Production Bench.
 */
class SupplierDirectoryAccess
{
    public function __construct(
        private readonly ProductionBenchAccess $productionBench,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function canManage(User $actor, Workspace $workspace): bool
    {
        return $this->authorization->canEditModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)
            || $this->productionBench->canWrite($actor, $workspace);
    }

    /**
     * Passes for DIP editors; otherwise defers to Production Bench so its messages and exceptions are unchanged.
     */
    public function assertCanManage(User $actor, Workspace $workspace): void
    {
        if ($this->authorization->canEditModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)) {
            return;
        }

        $this->productionBench->assertWritable($actor, $workspace);
    }
}
