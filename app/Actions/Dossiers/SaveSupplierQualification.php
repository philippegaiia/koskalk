<?php

namespace App\Actions\Dossiers;

use App\Enums\SupplierQualificationStatus;
use App\Enums\WorkspaceModule;
use App\Models\Ingredient;
use App\Models\Supplier;
use App\Models\SupplierQualification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveSupplierQualification
{
    public function __construct(private readonly WorkspaceAuthorization $authorization) {}

    /**
     * Creates or updates the qualification identified by ingredient, supplier and supplier reference.
     *
     * @param  array{ingredient_id: int, supplier_id: int, supplier_reference?: string|null, status: string, notes?: string|null}  $attributes
     */
    public function handle(User $actor, Workspace $workspace, array $attributes): SupplierQualification
    {
        $data = Validator::make($attributes, [
            'ingredient_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'supplier_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(SupplierQualificationStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $workspace, $data): SupplierQualification {
            Workspace::withoutGlobalScopes()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $freshActor = $actor->fresh();

            if (! $freshActor instanceof User
                || ! $this->authorization->canEditModule($freshActor, $workspace->id, WorkspaceModule::ProductDossiers)) {
                throw new AuthorizationException;
            }

            $supplierBelongs = Supplier::query()->where('workspace_id', $workspace->id)->whereKey($data['supplier_id'])->exists();
            $ingredient = Ingredient::query()->find($data['ingredient_id']);
            $ingredientUsable = $ingredient instanceof Ingredient
                && ($ingredient->workspace_id === null || $ingredient->workspace_id === $workspace->id);

            if (! $supplierBelongs || ! $ingredientUsable) {
                throw ValidationException::withMessages(array_filter([
                    'supplier_id' => $supplierBelongs ? null : __('dossiers.qualification.supplier_not_in_workspace'),
                    'ingredient_id' => $ingredientUsable ? null : __('dossiers.qualification.ingredient_not_usable'),
                ]));
            }

            $status = SupplierQualificationStatus::from($data['status']);
            $identity = [
                'workspace_id' => $workspace->id,
                'ingredient_id' => (int) $data['ingredient_id'],
                'supplier_id' => (int) $data['supplier_id'],
                'supplier_reference' => trim((string) ($data['supplier_reference'] ?? '')),
            ];
            $existing = SupplierQualification::query()->where($identity)->lockForUpdate()->first();
            $isValid = $status === SupplierQualificationStatus::Valide;
            $becomesValid = $isValid && $existing?->status !== SupplierQualificationStatus::Valide;

            return SupplierQualification::query()->updateOrCreate($identity, [
                'status' => $status,
                'notes' => $data['notes'] ?? $existing?->notes,
                'validated_by_user_id' => $isValid ? ($becomesValid ? $freshActor->id : $existing?->validated_by_user_id) : null,
                'validated_at' => $isValid ? ($becomesValid ? now() : $existing?->validated_at) : null,
            ]);
        }, attempts: 5);
    }
}
