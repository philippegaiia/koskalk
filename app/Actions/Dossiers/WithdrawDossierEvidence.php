<?php

namespace App\Actions\Dossiers;

use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawDossierEvidence
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    /**
     * Retires a current document without a replacement (e.g. uploaded by mistake). Record and file are kept.
     */
    public function handle(User $actor, Workspace $workspace, DossierEvidenceItem $item, string $reason): DossierEvidenceItem
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['withdrawn_reason' => __('dossiers.evidence.withdrawal_reason_required')]);
        }

        return DB::transaction(function () use ($actor, $workspace, $item, $reason): DossierEvidenceItem {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $current = DossierEvidenceItem::query()->where('workspace_id', $workspace->id)->whereKey($item->getKey())->lockForUpdate()->first();

            if (! $current instanceof DossierEvidenceItem || ! $current->isActive()) {
                throw ValidationException::withMessages(['evidence' => __('dossiers.evidence.not_withdrawable')]);
            }

            $current->update([
                'retired_at' => now(),
                'withdrawn_reason' => trim($reason),
                'withdrawn_by_user_id' => $freshActor->id,
            ]);

            return $current;
        }, attempts: 5);
    }
}
