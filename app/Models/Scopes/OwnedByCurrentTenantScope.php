<?php

namespace App\Models\Scopes;

use App\Enums\OwnerType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OwnedByCurrentTenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($model instanceof Workspace) {
            $builder->whereIn('id', $user->accessibleWorkspaceIds());

            return;
        }

        if ($model instanceof WorkspaceMember) {
            $builder->whereIn('workspace_id', $user->accessibleWorkspaceIds());

            return;
        }

        $ownedWorkspaceIds = $user->accessibleWorkspaceIds();

        $builder->where(function (Builder $query) use ($ownedWorkspaceIds, $user): void {
            $query->where(function (Builder $ownedByUserQuery) use ($user): void {
                $ownedByUserQuery
                    ->whereNull('workspace_id')
                    ->where('owner_type', OwnerType::User->value)
                    ->where('owner_id', $user->id);
            });

            if ($ownedWorkspaceIds !== []) {
                $query
                    ->orWhere(function (Builder $ownedByWorkspaceQuery) use ($ownedWorkspaceIds): void {
                        $ownedByWorkspaceQuery
                            ->whereNull('workspace_id')
                            ->where('owner_type', OwnerType::Workspace->value)
                            ->whereIn('owner_id', $ownedWorkspaceIds);
                    })
                    ->orWhereIn('workspace_id', $ownedWorkspaceIds);
            }
        });
    }
}
