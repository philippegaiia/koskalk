<?php

namespace App\Models\Scopes;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\OwnerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class OwnedByCurrentTenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($model instanceof Workspace) {
            $builder->where(function (Builder $query) use ($user): void {
                $query
                    ->where('owner_user_id', $user->id)
                    ->orWhereIn('id', $user->accessibleWorkspaceIds());
            });

            return;
        }

        if ($model instanceof WorkspaceMember) {
            $builder->where(function (Builder $query) use ($user): void {
                $query
                    ->where('user_id', $user->id)
                    ->orWhereIn('workspace_id', $user->accessibleWorkspaceIds());
            });

            return;
        }

        $accessibleWorkspaceIds = $user->accessibleWorkspaceIds();

        $builder->where(function (Builder $query) use ($accessibleWorkspaceIds, $user): void {
            $query->where(function (Builder $ownedByUserQuery) use ($user): void {
                $ownedByUserQuery
                    ->where('owner_type', OwnerType::User->value)
                    ->where('owner_id', $user->id);
            });

            if ($accessibleWorkspaceIds !== []) {
                $query
                    ->orWhere(function (Builder $ownedByWorkspaceQuery) use ($accessibleWorkspaceIds): void {
                        $ownedByWorkspaceQuery
                            ->where('owner_type', OwnerType::Workspace->value)
                            ->whereIn('owner_id', $accessibleWorkspaceIds);
                    })
                    ->orWhereIn('workspace_id', $accessibleWorkspaceIds);
            }
        });
    }
}
