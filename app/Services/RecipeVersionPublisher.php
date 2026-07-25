<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Publishes a recipe version by creating a published snapshot and a new current version.
 *
 * When publishing, the current version becomes a published snapshot and a fresh current
 * version is created for continued editing. The costing synchronizer copies pricing and
 * packaging data forward so the user does not lose their costing work.
 */
class RecipeVersionPublisher
{
    public function __construct(
        private readonly RecipeVersionRecordService $recipeVersionRecordService,
        private readonly RecipeVersionStructureSynchronizer $recipeVersionStructureSynchronizer,
        private readonly RecipeVersionCostingSynchronizer $recipeVersionCostingSynchronizer,
        private readonly RecipeSopSnapshotService $recipeSopSnapshotService,
        private readonly RecipeSopMediaAssetSynchronizer $recipeSopMediaAssetSynchronizer,
        private readonly RecipeMediaRollbackGuard $recipeMediaRollbackGuard,
        private readonly RecipeVersionDeletionService $recipeVersionDeletionService,
        private readonly EntitlementService $entitlementService,
    ) {}

    /**
     * Publish a recipe: turn the current state into a numbered published snapshot,
     * then create a fresh current version for continued editing.
     *
     * Costing is copied to both the published snapshot (for historical accuracy)
     * and the new current version (for continued editing). Hidden recovery snapshots are
     * pruned to the configured limit.
     *
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function publish(
        User $user,
        ProductFamily $productFamily,
        array $normalizedPayload,
        ?Recipe $recipe = null,
        ?Closure $preparePayloadForRecipe = null,
        ?Closure $onRecipeCreated = null,
    ): RecipeVersion {
        $isNewRecipe = ! $recipe instanceof Recipe;

        return $this->recipeMediaRollbackGuard->run(
            $isNewRecipe,
            function () use (&$recipe): ?Recipe {
                return $recipe;
            },
            function () use (&$recipe, $isNewRecipe, $normalizedPayload, $onRecipeCreated, $preparePayloadForRecipe, $productFamily, $user): RecipeVersion {
                return DB::transaction(function () use (&$recipe, $isNewRecipe, $normalizedPayload, $onRecipeCreated, $preparePayloadForRecipe, $productFamily, $user): RecipeVersion {
                    $recipe ??= $this->recipeVersionRecordService->createRecipe(
                        $user,
                        $productFamily,
                        $normalizedPayload['name'],
                        $normalizedPayload['product_type_id'] ?? null,
                    );

                    if ($isNewRecipe && $onRecipeCreated instanceof Closure) {
                        $onRecipeCreated($recipe);
                    }

                    if ($preparePayloadForRecipe instanceof Closure) {
                        $normalizedPayload = $preparePayloadForRecipe($recipe, $normalizedPayload);
                    }

                    $this->recipeSopMediaAssetSynchronizer->syncRecipeUsages(
                        $user,
                        $recipe,
                        $normalizedPayload['manufacturing_instructions'] ?? null,
                    );

                    $currentVersion = RecipeVersion::withoutGlobalScopes()
                        ->where('recipe_id', $recipe->id)
                        ->where('is_current', true)
                        ->first();

                    $publishedVersion = $currentVersion;

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
                    $this->recipeVersionCostingSynchronizer->reconcileExistingFormulaCosting($publishedVersion, $user);
                    $this->recipeSopSnapshotService->copySopMediaAssets($recipe, $publishedVersion);

                    $newCurrentVersion = new RecipeVersion;
                    $newCurrentVersion->recipe()->associate($recipe);
                    $newCurrentVersion->version_number = $this->recipeVersionRecordService->nextVersionNumber($recipe);
                    $this->recipeVersionRecordService->fillVersion(
                        $newCurrentVersion,
                        $recipe,
                        $user,
                        $normalizedPayload,
                        true,
                    );
                    $newCurrentVersion->save();
                    $this->recipeVersionStructureSynchronizer->sync($newCurrentVersion, $user, $normalizedPayload);
                    $this->recipeVersionCostingSynchronizer->copyToVersion($publishedVersion, $newCurrentVersion, $user);
                    $this->recipeSopSnapshotService->copySopMediaAssets($publishedVersion, $newCurrentVersion);
                    $this->recipeVersionDeletionService->pruneHiddenRecoverySnapshots(
                        $recipe,
                        $this->retainedPublishedVersionCountFor($user),
                    );

                    return $newCurrentVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
                });
            },
        );
    }

    /**
     * Restore a previous version by creating a new published snapshot from the given payload.
     *
     * Unlike publish(), this does not create a new current version — it only snapshots the
     * restored state as a published version. Costing is copied before retention pruning so
     * restoring the oldest retained snapshot cannot delete its costing before it is copied.
     *
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function restore(
        User $user,
        Recipe $recipe,
        array $normalizedPayload,
        RecipeVersion $sourceVersion,
    ): RecipeVersion {
        return DB::transaction(function () use ($normalizedPayload, $recipe, $sourceVersion, $user): RecipeVersion {
            $recipe = Recipe::withoutGlobalScopes()
                ->whereKey($recipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $sourceVersion = RecipeVersion::withoutGlobalScopes()
                ->whereKey($sourceVersion->id)
                ->where('recipe_id', $recipe->id)
                ->where('is_current', false)
                ->lockForUpdate()
                ->firstOrFail();

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
            $this->recipeVersionCostingSynchronizer->copyToVersion($sourceVersion, $publishedVersion, $user);
            $this->recipeSopSnapshotService->copySopMediaAssets($sourceVersion, $publishedVersion);
            $this->recipeVersionDeletionService->pruneHiddenRecoverySnapshots(
                $recipe,
                $this->retainedPublishedVersionCountFor($user),
            );

            return $publishedVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
        });
    }

    private function retainedPublishedVersionCountFor(User $user): int
    {
        return 1 + $this->entitlementService->savedFormulaHistoryLimitFor($user);
    }
}
