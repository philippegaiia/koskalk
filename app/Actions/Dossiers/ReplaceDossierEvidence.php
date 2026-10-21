<?php

namespace App\Actions\Dossiers;

use App\Models\DossierEvidenceItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplaceDossierEvidence
{
    public function __construct(private readonly StoreDossierEvidence $store) {}

    /**
     * Stores a newer version with the same type and attachments, then retires the old item and links it forward.
     *
     * @param  array{issued_on?: string|null, revision_due_on?: string|null, notes?: string|null}  $attributes
     */
    public function handle(User $actor, Workspace $workspace, DossierEvidenceItem $item, UploadedFile $file, array $attributes): DossierEvidenceItem
    {
        return DB::transaction(function () use ($actor, $workspace, $item, $file, $attributes): DossierEvidenceItem {
            $current = DossierEvidenceItem::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->first();

            if (! $current instanceof DossierEvidenceItem || ! $current->isActive()) {
                throw ValidationException::withMessages(['evidence' => __('dossiers.evidence.not_replaceable')]);
            }

            $replacement = $this->store->handle($actor, $workspace, $file, [
                ...$attributes,
                'type' => $current->type->value,
                'supplier_qualification_id' => $current->supplier_qualification_id,
                'recipe_ids' => $current->recipes()->pluck('recipes.id')->all(),
            ]);

            $current->update(['retired_at' => now(), 'replaced_by_id' => $replacement->id]);

            return $replacement;
        }, attempts: 5);
    }
}
