<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeControlMutationGuard
{
    public function __construct(private readonly RecipeEditingService $editing) {}

    public function run(Recipe $recipe, User $user, int $expectedRevision, string $ability, Closure $callback, bool $invalidateLease = false, bool $allowLocked = false): mixed
    {
        return $this->editing->withLockedRecipe($recipe, function (Recipe $locked) use ($user, $expectedRevision, $ability, $callback, $invalidateLease, $allowLocked): mixed {
            $this->editing->authorize($locked, $user, $ability);
            if ((int) $locked->edit_revision !== $expectedRevision) {
                throw ValidationException::withMessages(['expected_revision' => __('editing.changed')]);
            }
            if (! $allowLocked && $locked->isLocked()) {
                throw ValidationException::withMessages(['expected_revision' => __('editing.formula_locked')]);
            }
            if (! $invalidateLease && DB::table('recipe_edit_leases')->where('recipe_id', $locked->id)->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['expected_revision' => __('editing.reserved')]);
            }

            $result = $callback($locked);
            $this->editing->authorize($locked, $user, $ability);
            if ($invalidateLease) {
                DB::table('recipe_edit_leases')->where('recipe_id', $locked->id)->delete();
            }
            Recipe::withoutGlobalScopes()->whereKey($locked->id)->increment('edit_revision');

            return $result;
        });
    }
}
