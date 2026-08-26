<?php

namespace App\Services;

use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceIngredientCodeService
{
    public function synchronize(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $materialCode,
    ): ?WorkspaceIngredientCode {
        $this->assertWritable($actor, $workspace, $ingredient);
        $normalizedCode = $this->normalize($materialCode);

        if ($normalizedCode === null) {
            return DB::transaction(function () use ($ingredient, $workspace): ?WorkspaceIngredientCode {
                $existing = WorkspaceIngredientCode::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('ingredient_id', $ingredient->id)
                    ->lockForUpdate()
                    ->first();

                $existing?->delete();

                return null;
            }, attempts: 5);
        }

        $this->validateFormat($normalizedCode);

        $duplicateExists = WorkspaceIngredientCode::query()
            ->where('workspace_id', $workspace->id)
            ->where('material_code', $normalizedCode)
            ->where('ingredient_id', '!=', $ingredient->id)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'material_code' => __('ingredients.editor.validation.material_code_unique'),
            ]);
        }

        try {
            return DB::transaction(function () use ($ingredient, $normalizedCode, $workspace): WorkspaceIngredientCode {
                return WorkspaceIngredientCode::query()->updateOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'ingredient_id' => $ingredient->id,
                    ],
                    ['material_code' => $normalizedCode],
                );
            }, attempts: 5);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'material_code' => __('ingredients.editor.validation.material_code_unique'),
            ]);
        }
    }

    public function codeFor(Workspace $workspace, Ingredient $ingredient): ?string
    {
        return WorkspaceIngredientCode::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->value('material_code');
    }

    /**
     * @param  list<int>  $ingredientIds
     * @return Collection<int, string>
     */
    public function codesFor(Workspace $workspace, array $ingredientIds): Collection
    {
        return WorkspaceIngredientCode::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('ingredient_id', array_values(array_unique($ingredientIds)))
            ->pluck('material_code', 'ingredient_id');
    }

    private function assertWritable(User $actor, Workspace $workspace, Ingredient $ingredient): void
    {
        if (! in_array($workspace->roleFor($actor), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ], true)) {
            throw new AuthorizationException;
        }

        $isPlatformIngredient = $ingredient->owner_type === null
            && $ingredient->workspace_id === null;
        $isWorkspaceIngredient = (int) $ingredient->workspace_id === (int) $workspace->id;
        $isLegacyUserIngredient = $ingredient->isOwnedBy($actor);

        if (($isPlatformIngredient && ! $ingredient->is_active)
            || (! $isPlatformIngredient && ! $isWorkspaceIngredient && ! $isLegacyUserIngredient)) {
            throw ValidationException::withMessages([
                'material_code' => __('ingredients.editor.validation.material_code_forbidden'),
            ]);
        }
    }

    private function normalize(?string $materialCode): ?string
    {
        $normalized = Str::upper(trim((string) $materialCode));

        return $normalized === '' ? null : $normalized;
    }

    private function validateFormat(string $materialCode): void
    {
        if (preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{0,63}\z/', $materialCode) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'material_code' => __('ingredients.editor.validation.material_code_format'),
        ]);
    }
}
