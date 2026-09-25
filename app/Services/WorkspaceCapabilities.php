<?php

namespace App\Services;

use App\Enums\ProductionBenchEntitlementStatus;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Support\Facades\DB;

class WorkspaceCapabilities
{
    public function __construct(private readonly EntitlementService $entitlementService) {}

    public function allowsCollaboration(Workspace $workspace): bool
    {
        return $this->entitlementService->planForWorkspace($workspace)?->allows_collaboration === true;
    }

    public function canProvisionProductionBench(Workspace $workspace): bool
    {
        return $this->entitlementService->planForWorkspace($workspace)?->allows_production_bench === true;
    }

    public function provisionProductionBench(Workspace $workspace): ?WorkspaceProductionEntitlement
    {
        return DB::transaction(function () use ($workspace): ?WorkspaceProductionEntitlement {
            $lockedWorkspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($workspace->id);
            $grant = WorkspaceProductionEntitlement::query()
                ->whereBelongsTo($lockedWorkspace)
                ->lockForUpdate()
                ->first();

            if ($grant instanceof WorkspaceProductionEntitlement) {
                return $grant;
            }

            if (! $this->canProvisionProductionBench($lockedWorkspace)) {
                return null;
            }

            return WorkspaceProductionEntitlement::query()->create([
                'workspace_id' => $lockedWorkspace->id,
                'status' => ProductionBenchEntitlementStatus::Active,
                'activated_at' => now(),
            ]);
        }, attempts: 5);
    }
}
