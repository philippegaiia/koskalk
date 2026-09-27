<?php

namespace App\Services;

use App\Enums\WorkspaceMemberRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceMembershipService
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly WorkspaceCapabilities $capabilities) {}

    public function updateRole(User $actor, WorkspaceMember $member, WorkspaceMemberRole $role): void
    {
        DB::transaction(function () use ($actor, $member, $role): void {
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($member->workspace_id);
            $member = WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->findOrFail($member->id);
            $actor = User::query()->findOrFail($actor->id);
            $this->assertEnabled($workspace);
            Gate::forUser($actor)->authorize('update', [$member, $role]);
            $member->update(['role' => $role]);
        }, attempts: 5);
    }

    public function remove(User $actor, WorkspaceMember $member): void
    {
        DB::transaction(function () use ($actor, $member): void {
            $user = User::query()->lockForUpdate()->findOrFail($member->user_id);
            $workspace = Workspace::withoutGlobalScopes()->lockForUpdate()->findOrFail($member->workspace_id);
            $member = WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->findOrFail($member->id);
            $this->assertEnabled($workspace);
            Gate::forUser(User::query()->findOrFail($actor->id))->authorize('delete', $member);
            $member->delete();
            if ($user->active_workspace_id === $workspace->id) {
                $user->forceFill(['active_workspace_id' => $user->company(fresh: true)?->id])->save();
            }
        }, attempts: 5);
    }

    public function assertEnabled(Workspace $workspace): void
    {
        abort_unless(config('workspaces.collaboration_enabled') && $this->capabilities->allowsCollaboration($workspace), 403);
    }

    /** @return array{members: int, pending: int, used: int, limit: ?int, remaining: ?int} */
    public function seatUsage(Workspace $workspace): array
    {
        $workspace = Workspace::withoutGlobalScopes()->findOrFail($workspace->id);
        $members = WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('user_id', '!=', $workspace->owner_user_id)->distinct()->count('user_id') + 1;
        $memberEmails = User::query()->where(function (Builder $query) use ($workspace): void {
            $query->whereKey($workspace->owner_user_id)->orWhereIn('id', WorkspaceMember::withoutGlobalScopes()->where('workspace_id', $workspace->id)->select('user_id'));
        })->pluck('email')->map(fn (string $email): string => mb_strtolower(trim($email)));
        $pending = WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->whereNotIn('email', $memberEmails)->distinct()->count('email');
        $plan = $this->entitlements->planForWorkspace($workspace);
        $record = $plan?->limits()->where('key', 'workspace_members')->first();
        $limit = $plan?->allows_collaboration === true && $record !== null ? $record->value : 1;
        $used = $members + $pending;

        return ['members' => $members, 'pending' => $pending, 'used' => $used, 'limit' => $limit, 'remaining' => $limit === null ? null : max(0, $limit - $used)];
    }
}
