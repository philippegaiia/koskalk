<?php

namespace App\Services;

use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
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
    /** Maximum number of older published versions to keep as hidden recovery snapshots. */
    private const MAX_HIDDEN_RECOVERY_SNAPSHOTS = 3;

    public function __construct(
        private readonly RecipeVersionRecordService $recipeVersionRecordService,
        private readonly RecipeVersionStructureSynchronizer $recipeVersionStructureSynchronizer,
        private readonly RecipeVersionCostingSynchronizer $recipeVersionCostingSynchronizer,
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
    public function publish(User $user, ProductFamily $productFamily, array $normalizedPayload, ?Recipe $recipe = null): RecipeVersion
    {
        return DB::transaction(function () use ($normalizedPayload, $productFamily, $recipe, $user): RecipeVersion {
            $recipe ??= $this->recipeVersionRecordService->createRecipe(
                $user,
                $productFamily,
                $normalizedPayload['name'],
                $normalizedPayload['product_type_id'] ?? null,
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
            $this->pruneHiddenRecoverySnapshots($recipe);

            return $newCurrentVersion->fresh($this->recipeVersionRecordService->freshWorkbenchRelations());
        });
    }

    /**
     * Restore a previous version by creating a new published snapshot from the given payload.
     *
     * Unlike publish(), this does not create a new current version — it only snapshots the
     * restored state as a published version.
     *
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

    /** Remove older published versions beyond the recovery snapshot limit. */
    private function pruneHiddenRecoverySnapshots(Recipe $recipe): void
    {
        RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_current', false)
            ->orderByDesc('version_number')
            ->get()
            ->slice(self::MAX_HIDDEN_RECOVERY_SNAPSHOTS + 1)
            ->each
            ->delete();
    }
}
