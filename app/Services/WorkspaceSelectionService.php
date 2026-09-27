<?php

namespace App\Services;

use App\Enums\WorkspaceMemberRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserEntitlement;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceSelectionService
{
    /** @return LengthAwarePaginator<Workspace> */
    public function accessibleWorkspaces(User $user, string $search = '', int $page = 1): LengthAwarePaginator
    {
        abort_unless(config('workspaces.collaboration_enabled'), 404);
        $query = $this->accessibleQuery($user);
        $search = mb_substr(trim($search), 0, 120);
        if ($search !== '') {
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%']);
        }

        return $query->orderBy('name')->orderBy('id')->paginate(
            25, ['id', 'public_id', 'name'], 'workspace-selection-page', max(1, $page),
        );
    }

    public function select(User $user, string $workspacePublicId): Workspace
    {
        abort_unless(config('workspaces.collaboration_enabled'), 404);

        $workspace = DB::transaction(function () use ($user, $workspacePublicId): Workspace {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $workspace = Workspace::withoutGlobalScopes()->where('public_id', $workspacePublicId)->lockForUpdate()->firstOrFail();
            Gate::forUser($lockedUser)->authorize('select', $workspace);
            $lockedUser->forceFill(['active_workspace_id' => $workspace->id])->save();

            return $workspace;
        }, attempts: 5);
        $user->forceFill(['active_workspace_id' => $workspace->id]);
        $user->forgetAccessibleWorkspaceIds();

        return $workspace;
    }

    public function canSelect(User $user, Workspace $workspace): bool
    {
        return (bool) config('workspaces.collaboration_enabled')
            && $this->accessibleQuery($user)->whereKey($workspace->id)->exists();
    }

    /** @return Builder<Workspace> */
    private function accessibleQuery(User $user): Builder
    {
        $latestOwnerPlan = UserEntitlement::query()
            ->select('plan_id')
            ->whereColumn('user_id', 'workspaces.owner_user_id')
            ->active()
            ->orderByRaw('starts_at IS NULL')
            ->latest('starts_at')
            ->latest('id')
            ->limit(1);
        $allowsCollaboration = Plan::query()->select('allows_collaboration')->where('id', $latestOwnerPlan)->limit(1);

        return Workspace::withoutGlobalScopes()->where(function (Builder $query) use ($user, $allowsCollaboration): void {
            $query->where('owner_user_id', $user->id)
                ->orWhere(function (Builder $memberQuery) use ($user, $allowsCollaboration): void {
                    $memberQuery->whereIn('id', WorkspaceMember::withoutGlobalScopes()
                        ->select('workspace_id')
                        ->where('user_id', $user->id)
                        ->whereIn('role', [WorkspaceMemberRole::Admin, WorkspaceMemberRole::Editor, WorkspaceMemberRole::Viewer]))
                        ->where($allowsCollaboration, true);
                });
        });
    }
}
