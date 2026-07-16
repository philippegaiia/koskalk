<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceProvisioner
{
    public function ensureCompanyWorkspace(User $user, ?string $workspaceName = null): Workspace
    {
        $workspace = $user->company();

        if ($workspace instanceof Workspace) {
            return $workspace;
        }

        return $this->ensureOwnerWorkspace($user, $workspaceName);
    }

    public function ensureOwnerWorkspace(User $user, ?string $workspaceName = null): Workspace
    {
        $workspace = DB::transaction(function () use ($user, $workspaceName): Workspace {
            $workspace = Workspace::withoutGlobalScopes()
                ->where('owner_user_id', $user->id)
                ->first();

            if (! $workspace instanceof Workspace) {
                $name = filled($workspaceName)
                    ? trim((string) $workspaceName)
                    : explode(' ', trim($user->name ?: 'My Company'))[0]."'s Company";

                $workspace = Workspace::withoutGlobalScopes()->create([
                    'owner_user_id' => $user->id,
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                    'default_currency' => 'EUR',
                ]);
            }

            WorkspaceMember::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                ['role' => WorkspaceMemberRole::Owner->value],
            );

            return $workspace;
        });

        if ($user->active_workspace_id === null) {
            $this->activateWorkspace($user, $workspace);
        } else {
            $user->forgetAccessibleWorkspaceIds();
        }

        return $workspace;
    }

    public function activateWorkspace(User $user, Workspace $workspace): void
    {
        if (! $workspace->hasMember($user)) {
            throw new InvalidArgumentException('The user cannot activate a workspace they do not belong to.');
        }

        $user->forceFill(['active_workspace_id' => $workspace->id])->save();
        $user->forgetAccessibleWorkspaceIds();
    }
}
