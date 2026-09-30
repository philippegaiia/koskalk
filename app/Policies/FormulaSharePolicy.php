<?php

namespace App\Policies;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use App\Models\User;
use App\Services\WorkspaceAuthorization;

class FormulaSharePolicy
{
    public function view(User $user, FormulaShare $share): bool
    {
        return $this->manages($user, $share, 'source_workspace_id') || $this->manages($user, $share, 'recipient_workspace_id');
    }

    public function accept(User $user, FormulaShare $share): bool
    {
        $fresh = $share->fresh();

        return $fresh !== null && $this->manages($user, $fresh, 'recipient_workspace_id')
            && ($fresh->status === FormulaShareStatus::Accepted || ($fresh->source_workspace_id !== null && $fresh->isPending()));
    }

    public function decline(User $user, FormulaShare $share): bool
    {
        return $share->fresh()?->isPending() === true && $this->manages($user, $share, 'recipient_workspace_id');
    }

    public function revoke(User $user, FormulaShare $share): bool
    {
        return $share->fresh()?->isPending() === true && $this->manages($user, $share, 'source_workspace_id');
    }

    private function manages(User $user, FormulaShare $share, string $workspaceField): bool
    {
        $actor = User::withoutGlobalScopes()->find($user->id);
        $workspaceId = $share->fresh()?->{$workspaceField};

        return config('workspaces.formula_sharing.enabled', false) && $actor !== null && $workspaceId !== null
            && app(WorkspaceAuthorization::class)->canManage($actor, $workspaceId);
    }
}
