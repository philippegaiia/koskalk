<?php

namespace App\Services;

use App\Enums\OwnerType;
use App\Enums\WorkspaceMemberRole;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIngredientGuidance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkspaceIngredientGuidanceService
{
    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly WorkspaceIngredientGuidanceContent $content,
    ) {}

    public function recordFor(
        Workspace $workspace,
        Ingredient $ingredient,
    ): ?WorkspaceIngredientGuidance {
        return WorkspaceIngredientGuidance::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->first();
    }

    public function platformHtml(?string $markdown): ?string
    {
        return $this->content->fromPlatformMarkdown($markdown);
    }

    public function effectiveHtml(
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $locale = null,
    ): ?string {
        $record = $this->recordFor($workspace, $ingredient);

        if ($record instanceof WorkspaceIngredientGuidance && $record->is_active) {
            return $record->guidance_html;
        }

        if (! $this->isPlatformIngredient($ingredient)) {
            return null;
        }

        return $this->content->fromPlatformMarkdown(
            $ingredient->localizedInfoMarkdown($locale),
        );
    }

    public function editableHtml(
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $locale = null,
    ): ?string {
        $record = $this->recordFor($workspace, $ingredient);

        if ($record instanceof WorkspaceIngredientGuidance) {
            return $record->guidance_html;
        }

        return $this->isPlatformIngredient($ingredient)
            ? $this->content->fromPlatformMarkdown($ingredient->localizedInfoMarkdown($locale))
            : null;
    }

    public function save(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $html,
    ): WorkspaceIngredientGuidance {
        $this->assertWritable($actor, $workspace, $ingredient);
        $normalizedHtml = $this->content->sanitize($html);

        if ($normalizedHtml === null) {
            throw ValidationException::withMessages([
                'guidance_html' => __('ingredients.editor.validation.workspace_guidance_required'),
            ]);
        }

        if ($this->content->length($normalizedHtml) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'guidance_html' => __('ingredients.editor.validation.workspace_guidance_max', [
                    'max' => self::MAX_LENGTH,
                ]),
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $ingredient,
            $normalizedHtml,
            $workspace,
        ): WorkspaceIngredientGuidance {
            $guidance = WorkspaceIngredientGuidance::query()
                ->where('workspace_id', $workspace->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            if (! $guidance instanceof WorkspaceIngredientGuidance) {
                $guidance = new WorkspaceIngredientGuidance([
                    'workspace_id' => $workspace->id,
                    'ingredient_id' => $ingredient->id,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $guidance->guidance_html = $normalizedHtml;
            $guidance->is_active = true;
            $guidance->updated_by_user_id = $actor->id;
            $guidance->save();

            return $guidance;
        }, attempts: 5);
    }

    public function clearWorkspaceOwned(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
    ): void {
        $this->assertWritable($actor, $workspace, $ingredient);
        $this->assertWorkspaceOwned($workspace, $ingredient);

        DB::transaction(function () use ($ingredient, $workspace): void {
            $guidance = WorkspaceIngredientGuidance::query()
                ->where('workspace_id', $workspace->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            $guidance?->delete();
        }, attempts: 5);
    }

    public function usePlatform(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
    ): void {
        $this->assertWritable($actor, $workspace, $ingredient);
        $this->assertPlatform($ingredient);

        DB::transaction(function () use ($actor, $ingredient, $workspace): void {
            $guidance = WorkspaceIngredientGuidance::query()
                ->where('workspace_id', $workspace->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            if (! $guidance instanceof WorkspaceIngredientGuidance) {
                return;
            }

            $guidance->is_active = false;
            $guidance->updated_by_user_id = $actor->id;
            $guidance->save();
        }, attempts: 5);
    }

    public function useWorkspace(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
    ): WorkspaceIngredientGuidance {
        $this->assertWritable($actor, $workspace, $ingredient);
        $this->assertPlatform($ingredient);

        return DB::transaction(function () use ($actor, $ingredient, $workspace): WorkspaceIngredientGuidance {
            $guidance = WorkspaceIngredientGuidance::query()
                ->where('workspace_id', $workspace->id)
                ->where('ingredient_id', $ingredient->id)
                ->lockForUpdate()
                ->first();

            if (! $guidance instanceof WorkspaceIngredientGuidance) {
                throw ValidationException::withMessages([
                    'guidance_html' => __('ingredients.editor.validation.workspace_guidance_missing'),
                ]);
            }

            $guidance->is_active = true;
            $guidance->updated_by_user_id = $actor->id;
            $guidance->save();

            return $guidance;
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

        if ($this->isPlatformIngredient($ingredient)) {
            if (! $ingredient->is_active) {
                throw ValidationException::withMessages([
                    'guidance_html' => __('ingredients.editor.validation.workspace_guidance_forbidden'),
                ]);
            }

            return;
        }

        if (
            $ingredient->owner_type !== OwnerType::Workspace
            || (int) $ingredient->workspace_id !== (int) $workspace->id
            || ! $ingredient->is_active
        ) {
            throw ValidationException::withMessages([
                'guidance_html' => __('ingredients.editor.validation.workspace_guidance_forbidden'),
            ]);
        }
    }

    private function assertPlatform(Ingredient $ingredient): void
    {
        if (! $this->isPlatformIngredient($ingredient)) {
            throw ValidationException::withMessages([
                'guidance_html' => __('ingredients.editor.validation.workspace_guidance_forbidden'),
            ]);
        }
    }

    private function assertWorkspaceOwned(Workspace $workspace, Ingredient $ingredient): void
    {
        if (
            $ingredient->owner_type !== OwnerType::Workspace
            || (int) $ingredient->workspace_id !== (int) $workspace->id
        ) {
            throw ValidationException::withMessages([
                'guidance_html' => __('ingredients.editor.validation.workspace_guidance_forbidden'),
            ]);
        }
    }

    private function isPlatformIngredient(Ingredient $ingredient): bool
    {
        return $ingredient->owner_type === null
            && $ingredient->owner_id === null
            && $ingredient->workspace_id === null;
    }
}
