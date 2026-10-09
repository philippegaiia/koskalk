<?php

namespace App\Actions\FormulaSharing;

use App\Enums\FormulaShareStatus;
use App\Models\FormulaShare;
use App\Models\User;
use App\Services\FormulaShareTransaction;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class CloseFormulaShare
{
    public function __construct(private readonly FormulaShareTransaction $transactions, private readonly WorkspaceAuthorization $authorization) {}

    public function handle(User $actor, FormulaShare $share, FormulaShareStatus $status): void
    {
        if (! in_array($status, [FormulaShareStatus::Revoked, FormulaShareStatus::Declined], true)) {
            $this->invalid();
        }
        $hint = FormulaShare::query()->findOrFail($share->id);
        $workspaceId = $status === FormulaShareStatus::Revoked ? $hint->source_workspace_id : $hint->recipient_workspace_id;
        if (! config('workspaces.formula_sharing.enabled', false) || $workspaceId === null || ! $this->authorization->canManage($actor, $workspaceId)) {
            throw new AuthorizationException;
        }
        $workspaceIds = array_values(array_filter([$hint->source_workspace_id, $hint->recipient_workspace_id]));
        $this->transactions->run($actor, $workspaceIds, function (User $fresh) use ($hint, $status): void {
            $share = FormulaShare::query()->lockForUpdate()->findOrFail($hint->id);
            $workspaceId = $status === FormulaShareStatus::Revoked ? $share->source_workspace_id : $share->recipient_workspace_id;
            if ($workspaceId === null || ! $this->authorization->canManage($fresh, $workspaceId)) {
                throw new AuthorizationException;
            }
            if ($share->status === $status) {
                return;
            }
            if (! $share->isPending()) {
                $this->invalid();
            }
            $share->forceFill(['status' => $status, 'closed_at' => now()])->save();
        });
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['sharing' => __('sharing.validation.unavailable')]);
    }
}
