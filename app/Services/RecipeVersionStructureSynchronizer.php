<?php

namespace App\Services;

use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\OwnerType;
use App\Visibility;

class RecipeVersionStructureSynchronizer
{
    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function sync(RecipeVersion $recipeVersion, User $user, array $normalizedPayload): void
    {
        RecipeItem::withoutGlobalScopes()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        RecipePhase::withoutGlobalScopes()
            ->where('recipe_version_id', $recipeVersion->id)
            ->delete();

        foreach ($normalizedPayload['phases'] as $phaseIndex => $phasePayload) {
            $phase = new RecipePhase([
                'owner_type' => OwnerType::User,
                'owner_id' => $user->id,
                'workspace_id' => null,
                'visibility' => Visibility::Private,
                'name' => $phasePayload['name'],
                'slug' => $phasePayload['key'],
                'phase_type' => $phasePayload['phase_type'],
                'sort_order' => $phaseIndex + 1,
                'is_system' => $phasePayload['is_system'],
            ]);

            $phase->recipeVersion()->associate($recipeVersion);
            $phase->save();

            foreach ($phasePayload['items'] as $itemIndex => $itemPayload) {
                $recipeItem = new RecipeItem([
                    'ingredient_id' => $itemPayload['ingredient_id'],
                    'owner_type' => OwnerType::User,
                    'owner_id' => $user->id,
                    'workspace_id' => null,
                    'visibility' => Visibility::Private,
                    'position' => $itemIndex + 1,
                    'percentage' => $itemPayload['percentage'],
                    'weight' => $itemPayload['weight'],
                    'note' => $itemPayload['note'],
                ]);

                $recipeItem->recipeVersion()->associate($recipeVersion);
                $recipeItem->recipePhase()->associate($phase);
                $recipeItem->save();
            }
        }
    }
}
