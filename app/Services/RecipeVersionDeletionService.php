<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\RichContentAttachmentPaths;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecipeVersionDeletionService
{
    public function __construct(private readonly RecipeMediaReferenceService $recipeMediaReferenceService) {}

    /**
     * @return array{deleted_current: bool, last_published_deleted: bool}
     */
    public function delete(Recipe $recipe, RecipeVersion $version): array
    {
        return DB::transaction(function () use ($recipe, $version): array {
            $lockedRecipe = Recipe::withoutGlobalScopes()
                ->whereKey($recipe->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedVersion = RecipeVersion::withoutGlobalScopes()
                ->whereKey($version->id)
                ->where('recipe_id', $lockedRecipe->id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasCurrent = $lockedVersion->is_current;
            $wasPublished = ! $wasCurrent;
            $candidatePaths = RichContentAttachmentPaths::extract($lockedVersion->manufacturing_instructions);

            $lockedVersion->delete();

            $hasPublishedVersions = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $lockedRecipe->id)
                ->where('is_current', false)
                ->exists();

            $this->deleteCandidatesAfterCommit($lockedRecipe, $candidatePaths);

            return [
                'deleted_current' => $wasCurrent,
                'last_published_deleted' => $wasPublished && ! $hasPublishedVersions,
            ];
        });
    }

    public function pruneHiddenRecoverySnapshots(Recipe $recipe, int $retainedVersionCount): void
    {
        DB::transaction(function () use ($recipe, $retainedVersionCount): void {
            $lockedRecipe = Recipe::withoutGlobalScopes()
                ->whereKey($recipe->id)
                ->lockForUpdate()
                ->firstOrFail();
            $versionsToDelete = RecipeVersion::withoutGlobalScopes()
                ->where('recipe_id', $lockedRecipe->id)
                ->where('is_current', false)
                ->orderByDesc('version_number')
                ->lockForUpdate()
                ->get()
                ->slice(max(0, $retainedVersionCount));
            $candidatePaths = $versionsToDelete
                ->flatMap(fn (RecipeVersion $version): Collection => RichContentAttachmentPaths::extract(
                    $version->manufacturing_instructions,
                ))
                ->unique()
                ->values();

            $versionsToDelete->each(function (RecipeVersion $version): void {
                $version->delete();
            });

            $this->deleteCandidatesAfterCommit($lockedRecipe, $candidatePaths);
        });
    }

    /** @param Collection<int, string> $candidatePaths */
    private function deleteCandidatesAfterCommit(Recipe $recipe, Collection $candidatePaths): void
    {
        if ($candidatePaths->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($candidatePaths, $recipe): void {
            $this->recipeMediaReferenceService->deleteIfUnreferenced($recipe, $candidatePaths);
        });
    }
}
