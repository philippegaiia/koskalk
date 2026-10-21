<?php

namespace App\Actions\Dossiers;

use App\Enums\EvidenceLevel;
use App\Enums\EvidenceType;
use App\Enums\WorkspaceModule;
use App\Models\DossierEvidenceItem;
use App\Models\Recipe;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Dossiers\DossierEvidenceStorage;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreDossierEvidence
{
    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly DossierEvidenceStorage $storage,
    ) {}

    /**
     * @param  array{type: string, issued_on?: string|null, revision_due_on?: string|null, notes?: string|null,
     *               supplier_qualification_id?: int|null, recipe_ids?: list<int>}  $attributes
     */
    public function handle(User $actor, Workspace $workspace, UploadedFile $file, array $attributes): DossierEvidenceItem
    {
        $data = Validator::make($attributes, [
            'type' => ['required', Rule::enum(EvidenceType::class)],
            'issued_on' => ['nullable', 'date'],
            'revision_due_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'supplier_qualification_id' => ['nullable', 'integer'],
            'recipe_ids' => ['nullable', 'array'],
            'recipe_ids.*' => ['integer'],
        ])->validate();
        $this->storage->assertAcceptable($file);

        return DB::transaction(function () use ($actor, $workspace, $file, $data): DossierEvidenceItem {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $type = EvidenceType::from($data['type']);
            $recipeIds = collect($data['recipe_ids'] ?? [])->map(fn (mixed $id): int => (int) $id)->unique()->values();
            $this->assertAttachment($workspace, $type, $data['supplier_qualification_id'] ?? null, $recipeIds->all());

            $item = DossierEvidenceItem::query()->create([
                'workspace_id' => $workspace->id,
                'dossier_evidence_file_id' => $this->storage->store($workspace, $file, $freshActor)->id,
                'type' => $type,
                'supplier_qualification_id' => $type->level() === EvidenceLevel::SupplierQualification ? $data['supplier_qualification_id'] : null,
                'issued_on' => $data['issued_on'] ?? null,
                'revision_due_on' => $data['revision_due_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $freshActor->id,
            ]);

            if ($type->level() === EvidenceLevel::Product) {
                $item->recipes()->sync($recipeIds->all());
            }

            return $item->load(['file', 'recipes']);
        }, attempts: 5);
    }

    /**
     * @param  list<int>  $recipeIds
     */
    private function assertAttachment(Workspace $workspace, EvidenceType $type, mixed $qualificationId, array $recipeIds): void
    {
        $error = match ($type->level()) {
            EvidenceLevel::SupplierQualification => $qualificationId !== null
                && SupplierQualification::query()->where('workspace_id', $workspace->id)->whereKey($qualificationId)->exists()
                    ? null : ['supplier_qualification_id', __('dossiers.evidence.needs_qualification')],
            EvidenceLevel::Product => $recipeIds !== []
                && Recipe::withoutGlobalScopes()->where('workspace_id', $workspace->id)->whereIn('id', $recipeIds)->count() === count($recipeIds)
                    ? null : ['recipe_ids', __('dossiers.evidence.needs_products')],
            EvidenceLevel::Workspace => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages([$error[0] => $error[1]]);
        }
    }
}
