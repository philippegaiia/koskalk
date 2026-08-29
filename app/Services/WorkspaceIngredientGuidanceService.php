<?php

namespace App\Services;

use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkspaceIngredientGuidanceService
{
    public const MAX_LENGTH = 2000;

    public function overrideFor(
        Workspace $workspace,
        Ingredient $ingredient,
    ): ?WorkspaceIngredientGuidance {
        return WorkspaceIngredientGuidance::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->first();
    }

    public function effectiveGuidance(
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $locale = null,
    ): ?string {
        if ($this->isPlatformIngredient($ingredient)) {
            return $this->overrideFor($workspace, $ingredient)?->guidance_markdown
                ?? $ingredient->localizedInfoMarkdown($locale);
        }

        return $ingredient->localizedInfoMarkdown($locale);
    }

    public function save(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $guidanceMarkdown,
    ): WorkspaceIngredientGuidance {
        $this->assertWritable($actor, $workspace, $ingredient);
        $normalizedGuidance = trim((string) $guidanceMarkdown);
        $validated = $this->validateGuidance($normalizedGuidance);

        return DB::transaction(function () use (
            $actor,
            $validated,
            $workspace,
            $ingredient,
        ): WorkspaceIngredientGuidance {
            $override = WorkspaceIngredientGuidance::query()
                ->where('workspace_id', $workspace->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            if (! $override instanceof WorkspaceIngredientGuidance) {
                $override = new WorkspaceIngredientGuidance([
                    'workspace_id' => $workspace->id,
                    'ingredient_id' => $ingredient->id,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $override->guidance_markdown = $validated['guidance_markdown'];
            $override->updated_by_user_id = $actor->id;
            $override->save();

            return $override;
        }, attempts: 5);
    }

    public function reset(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
    ): void {
        $this->assertWritable($actor, $workspace, $ingredient);

        DB::transaction(function () use ($ingredient, $workspace): void {
            $override = WorkspaceIngredientGuidance::query()
                ->where('workspace_id', $workspace->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            $override?->delete();
        }, attempts: 5);
    }

    private function assertWritable(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
    ): void {
        if (! in_array($workspace->roleFor($actor), [
            WorkspaceMemberRole::Owner,
            WorkspaceMemberRole::Admin,
            WorkspaceMemberRole::Editor,
        ], true)) {
            throw new AuthorizationException;
        }

        if (! $this->isPlatformIngredient($ingredient) || ! $ingredient->is_active) {
            throw ValidationException::withMessages([
                'guidance_markdown' => __('ingredients.editor.validation.workspace_guidance_forbidden'),
            ]);
        }
    }

    private function isPlatformIngredient(Ingredient $ingredient): bool
    {
        return $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;
    }

    /**
     * @return array{guidance_markdown: string}
     */
    private function validateGuidance(string $guidanceMarkdown): array
    {
        return validator(
            ['guidance_markdown' => $guidanceMarkdown],
            ['guidance_markdown' => [
                'required',
                'string',
                'max:'.self::MAX_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strip_tags($value) !== $value) {
                        $fail(__('ingredients.editor.validation.workspace_guidance_html'));
                    }
                },
            ]],
            [
                'guidance_markdown.required' => __('ingredients.editor.validation.workspace_guidance_required'),
                'guidance_markdown.max' => __('ingredients.editor.validation.workspace_guidance_max', [
                    'max' => self::MAX_LENGTH,
                ]),
            ],
        )->validate();
    }
}
