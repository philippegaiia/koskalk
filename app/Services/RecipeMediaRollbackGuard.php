<?php

namespace App\Services;

use App\Models\Recipe;
use Closure;
use Throwable;

class RecipeMediaRollbackGuard
{
    public function run(bool $isNewRecipe, Closure $recipeResolver, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            $recipe = $recipeResolver();

            if ($isNewRecipe && $recipe instanceof Recipe) {
                MediaStorage::deleteRecipeDirectory($recipe);
            }

            throw $exception;
        }
    }
}
