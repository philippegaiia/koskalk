<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use Closure;
use Illuminate\Validation\ValidationException;

class RecipeMutationGuard
{
    public function __construct(private readonly RecipeEditingService $editing) {}

    /** The callback runs under workspace, recipe, version and costing row locks. */
    public function run(
        Recipe $recipe,
        User $user,
        string $token,
        int $expectedRecipeRevision,
        ?int $expectedVersionId,
        Closure $callback,
        ?int $expectedCostingRevision = null,
        bool $allowLocked = false,
    ): mixed {
        return $this->editing->withLockedRecipe($recipe, function (Recipe $locked) use ($user, $token, $expectedRecipeRevision, $expectedVersionId, $callback, $expectedCostingRevision, $allowLocked): mixed {
            $actor = $this->editing->authorize($locked, $user);
            $this->editing->assertLease($locked, $actor, $token);
            if (! $allowLocked && $locked->locked_at !== null) {
                throw ValidationException::withMessages(['editing_lease' => __('editing.formula_locked')]);
            }
            $version = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $locked->id)->where('is_current', true)->lockForUpdate()->first();
            if ((int) $locked->edit_revision !== $expectedRecipeRevision || $version?->id !== $expectedVersionId) {
                $this->conflict();
            }
            $costings = $version === null ? collect() : RecipeVersionCosting::query()->where('recipe_version_id', $version->id)->lockForUpdate()->get();
            if ($costings->count() > 1) {
                $this->conflict();
            }
            $costing = $costings->first();
            if ($expectedCostingRevision !== null && (int) ($costing?->edit_revision ?? 0) !== $expectedCostingRevision) {
                $this->conflict();
            }

            $result = $callback($locked, $version, $costing);

            $actor = $this->editing->authorize($locked, $user);
            $this->editing->assertLease($locked, $actor, $token);
            $locked->increment('edit_revision');
            if ($expectedCostingRevision !== null && $version !== null) {
                RecipeVersionCosting::query()->where('recipe_version_id', $version->id)->increment('edit_revision');
            }

            return $result;
        });
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['edit_revision' => __('editing.validation.revision_changed')]);
    }
}
