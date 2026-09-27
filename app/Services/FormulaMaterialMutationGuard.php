<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCosting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FormulaMaterialMutationGuard
{
    public function __construct(private readonly RecipeEditingService $editing) {}

    /**
     * The caller keeps its material transaction open through the mutation, including
     * the workspace lock for company materials or the user lock for legacy materials.
     *
     * @param  Collection<int, int>  $versionIds
     */
    public function protect(User $user, Collection $versionIds, string $errorKey): void
    {
        $recipeIds = RecipeVersion::withoutGlobalScopes()->whereKey($versionIds->all())->pluck('recipe_id')->unique();
        $recipes = Recipe::withoutGlobalScopes()->whereKey($recipeIds->all())->orderBy('id')->lockForUpdate()->get();

        foreach ($recipes as $recipe) {
            $this->editing->authorize($recipe, $user);
            if ($recipe->isLocked()) {
                throw ValidationException::withMessages([$errorKey => __('editing.formula_locked')]);
            }
            if (DB::table('recipe_edit_leases')->where('recipe_id', $recipe->id)->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages([$errorKey => __('editing.reserved')]);
            }
        }

        RecipeVersion::withoutGlobalScopes()->whereKey($versionIds->all())->orderBy('id')->lockForUpdate()->get(['id']);
        $costingIds = RecipeVersionCosting::query()->whereIn('recipe_version_id', $versionIds->all())
            ->orderBy('id')->lockForUpdate()->pluck('id');

        Recipe::withoutGlobalScopes()->whereKey($recipeIds->all())->increment('edit_revision');
        RecipeVersionCosting::query()->whereKey($costingIds->all())->increment('edit_revision');
    }
}
