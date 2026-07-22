<?php

namespace App\Services;

use App\Models\Recipe;
use Closure;
use Throwable;

class RecipeMediaRollbackGuard
{
    public function run(Recipe $recipe, bool $isNewRecipe, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if ($isNewRecipe) {
                MediaStorage::deleteRecipeDirectory($recipe);
            }

            throw $exception;
        }
    }
}
