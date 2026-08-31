<?php

namespace App\Services;

use App\Enums\ProductionBenchEntitlementStatus;
use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceProductionEntitlement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionBenchAccess
{
    public function activate(User $actor, Workspace $workspace): WorkspaceProductionEntitlement
    {
        return $this->writeStatus($actor, $workspace, ProductionBenchEntitlementStatus::Active);
    }

    public function cancel(User $actor, Workspace $workspace): WorkspaceProductionEntitlement
    {
        return $this->writeStatus($actor, $workspace, ProductionBenchEntitlementStatus::Cancelled);
    }

    public function resume(User $actor, Workspace $workspace): WorkspaceProductionEntitlement
    {
        return $this->writeStatus($actor, $workspace, ProductionBenchEntitlementStatus::Active);
    }

    public function isActive(Workspace $workspace): bool
    {
        return WorkspaceProductionEntitlement::query()
            ->whereBelongsTo($workspace)
            ->where('status', ProductionBenchEntitlementStatus::Active)
            ->exists();
    }

    public function isReadOnly(Workspace $workspace): bool
    {
        return WorkspaceProductionEntitlement::query()
            ->whereBelongsTo($workspace)
            ->where('status', ProductionBenchEntitlementStatus::Cancelled)
            ->exists();
    }

    public function canWrite(User $actor, Workspace $workspace): bool
    {
        return $this->hasManageRole($actor, $workspace) && $this->isActive($workspace);
    }

    public function assertWritable(User $actor, Workspace $workspace): void
    {
        $this->assertCanManage($actor, $workspace);

        if ($this->isActive($workspace)) {
            return;
        }

        $message = $this->isReadOnly($workspace)
            ? __('production_bench.access.read_only')
            : __('production_bench.access.inactive');

        throw ValidationException::withMessages([
            'production_bench' => $message,
        ]);
    }

    /**
     * Read authorization for Production Bench data is workspace membership.
     *
     * Any role that can reach the workspace may read: owner, admin, editor, and
     * viewer all pass. A cancelled/read-only workspace keeps its members, so it
     * remains browsable. Only a user with no membership in the workspace is
     * rejected. Mutation gates stay owned by assertWritable()/assertCanConfigure().
     */
    public function assertReadable(User $actor, Workspace $workspace): void
    {
        if ($workspace->roleFor($actor) === null) {
            throw new AuthorizationException;
        }
    }

    public function assertCanConfigure(User $actor, Workspace $workspace): void
    {
        $this->assertWritable($actor, $workspace);

        if (! in_array($workspace->roleFor($actor), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
        ], true)) {
            throw new AuthorizationException;
        }
    }

    private function writeStatus(
        User $actor,
        Workspace $workspace,
        ProductionBenchEntitlementStatus $status,
    ): WorkspaceProductionEntitlement {
        $this->assertCanManage($actor, $workspace);

        return DB::transaction(function () use ($actor, $status, $workspace): WorkspaceProductionEntitlement {
            $lockedWorkspace = Workspace::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($workspace->id);

            $this->assertCanManage($actor, $lockedWorkspace);

            $entitlement = WorkspaceProductionEntitlement::query()
                ->whereBelongsTo($lockedWorkspace)
                ->lockForUpdate()
                ->first();

            $values = $status === ProductionBenchEntitlementStatus::Active
                ? [
                    'status' => $status,
                    'activated_at' => now(),
                    'cancelled_at' => null,
                    'archive_eligible_at' => null,
                ]
                : [
                    'status' => $status,
                    'cancelled_at' => now(),
                    'archive_eligible_at' => now()->addMonthsNoOverflow(48),
                ];

            if (! $entitlement instanceof WorkspaceProductionEntitlement) {
                return WorkspaceProductionEntitlement::query()->create([
                    'workspace_id' => $lockedWorkspace->id,
                    ...$values,
                ]);
            }

            $entitlement->update($values);

            return $entitlement->refresh();
        }, attempts: 5);
    }

    private function assertCanManage(User $actor, Workspace $workspace): void
    {
        if (! $this->hasManageRole($actor, $workspace)) {
            throw new AuthorizationException;
        }
    }

    private function hasManageRole(User $actor, Workspace $workspace): bool
    {
        return in_array($workspace->roleFor($actor), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ], true);
    }
}
