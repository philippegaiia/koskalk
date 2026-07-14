<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\WorkspaceMemberRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceProvisioner
{
    public function ensureOwnerWorkspace(User $user, ?string $workspaceName = null): Workspace
    {
        return DB::transaction(function () use ($user, $workspaceName): Workspace {
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

            $user->forgetAccessibleWorkspaceIds();

            return $workspace;
        });
    }
}
