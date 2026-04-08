<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecipeVersionPublisher
{
    private const MAX_HIDDEN_RECOVERY_SNAPSHOTS = 3;

    public function __construct(
        private readonly RecipeVersionRecordService $recipeVersionRecordService,
        private readonly RecipeVersionStructureSynchronizer $recipeVersionStructureSynchronizer,
        private readonly RecipeVersionCostingSynchronizer $recipeVersionCostingSynchronizer,
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function publish(User $user, ProductFamily $productFamily, array $normalizedPayload, ?Recipe $recipe = null): RecipeVersion
    {
        return DB::transaction(function () use ($normalizedPayload, $productFamily, $recipe, $user): RecipeVersion {
            $recipe ??= $this->recipeVersionRecordService->createRecipe(
                $user,
                $productFamily,
                $normalizedPayload['name'],
            );

            $draftVersion = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $recipe->id)
                ->where('is_draft', true)
                ->first();

            $publishedVersion = $draftVersion;

            if (! $publishedVersion instanceof RecipeVersion) {
                $publishedVersion = new RecipeVersion;
                $publishedVersion->recipe()->associate($recipe);
                $publishedVersion->version_number = $this->recipeVersionRecordService->nextVersionNumber($recipe);
            }

            $this->recipeVersionRecordService->fillVersion(
                $publishedVersion,
                $recipe,
                $user,
                $normalizedPayload,
                false,
            );
            $publishedVersion->save();
            $this->recipeVersionStructureSynchronizer->sync($publishedVersion, $user, $normalizedPayload);

            $nextDraftVersionNumber = $publishedVersion->version_number + 1;

            $newDraftVersion = new RecipeVersion;
            $newDraftVersion->recipe()->associate($recipe);
            $newDraftVersion->version_number = $nextDraftVersionNumber;
            $this->recipeVersionRecordService->fillVersion(
                $newDraftVersion,
                $recipe,
                $user,
                $normalizedPayload,
                true,
            );
            $newDraftVersion->save();
            $this->recipeVersionStructureSynchronizer->sync($newDraftVersion, $user, $normalizedPayload);
            $this->recipeVersionCostingSynchronizer->copyToVersion($publishedVersion, $newDraftVersion, $user);
            $this->pruneHiddenRecoverySnapshots($recipe);

            return $newDraftVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
        });
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function restore(User $user, Recipe $recipe, array $normalizedPayload): RecipeVersion
    {
        return DB::transaction(function () use ($normalizedPayload, $recipe, $user): RecipeVersion {
            $publishedVersion = new RecipeVersion;
            $publishedVersion->recipe()->associate($recipe);
            $publishedVersion->version_number = $this->recipeVersionRecordService->nextVersionNumber($recipe);

            $this->recipeVersionRecordService->fillVersion(
                $publishedVersion,
                $recipe,
                $user,
                $normalizedPayload,
                false,
            );
            $publishedVersion->save();
            $this->recipeVersionStructureSynchronizer->sync($publishedVersion, $user, $normalizedPayload);
            $this->pruneHiddenRecoverySnapshots($recipe);

            return $publishedVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
        });
    }

    private function pruneHiddenRecoverySnapshots(Recipe $recipe): void
    {
        RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->orderByDesc('version_number')
            ->get()
            ->slice(self::MAX_HIDDEN_RECOVERY_SNAPSHOTS + 1)
            ->each
            ->delete();
    }
}
