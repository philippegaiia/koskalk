<?php

namespace App\Console\Commands;

use App\Models\Recipe;
use App\Services\MediaStorage;
use App\Services\RecipeMediaReferenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('media:prune-orphaned-recipe {--age=24 : Minimum object age in hours}')]
#[Description('Delete old unreferenced media from recipe-specific storage')]
class PruneOrphanedRecipeMedia extends Command
{
    public function handle(RecipeMediaReferenceService $recipeMediaReferenceService): int
    {
        $age = filter_var($this->option('age'), FILTER_VALIDATE_INT);

        if (! is_int($age) || $age < 1) {
            $this->components->error('The --age option must be an integer of at least 1 hour.');

            return self::FAILURE;
        }

        $disk = Storage::disk(MediaStorage::recipeDisk());
        $paths = $this->recipeMediaPaths($disk);
        $cutoff = now()->subHours($age)->getTimestamp();
        $eligiblePaths = $paths
            ->filter(fn (string $path): bool => $disk->lastModified($path) < $cutoff)
            ->values();
        $recipesByPublicId = Recipe::withoutGlobalScopes()
            ->whereIn('public_id', $eligiblePaths->map(fn (string $path): string => $this->recipePublicId($path))->unique())
            ->get()
            ->keyBy(fn (Recipe $recipe): string => (string) $recipe->public_id);
        $referencedPathsByRecipe = collect();
        $deletedCount = 0;

        foreach ($eligiblePaths as $path) {
            $recipePublicId = $this->recipePublicId($path);
            $recipe = $recipesByPublicId->get($recipePublicId);

            if ($recipe instanceof Recipe) {
                $referencedPaths = $referencedPathsByRecipe->get($recipePublicId);

                if (! $referencedPaths instanceof Collection) {
                    $referencedPaths = $recipeMediaReferenceService
                        ->allReferencedPaths($recipe)
                        ->push($recipe->featured_image_path)
                        ->filter(fn (mixed $referencedPath): bool => is_string($referencedPath) && $referencedPath !== '')
                        ->unique()
                        ->values();
                    $referencedPathsByRecipe->put($recipePublicId, $referencedPaths);
                }

                if ($referencedPaths->contains($path)) {
                    continue;
                }
            }

            if ($disk->delete($path)) {
                $deletedCount++;
            }
        }

        $this->components->info('Scanned: '.$paths->count().'; Deleted: '.$deletedCount.'; Preserved: '.($paths->count() - $deletedCount).'.');

        return self::SUCCESS;
    }

    /** @return Collection<int, string> */
    private function recipeMediaPaths(FilesystemAdapter $disk): Collection
    {
        return collect($disk->directories('recipes'))
            ->filter(fn (string $recipeDirectory): bool => Str::isUuid(Str::afterLast($recipeDirectory, '/')))
            ->flatMap(fn (string $recipeDirectory): array => [
                ...$disk->allFiles($recipeDirectory.'/featured-images'),
                ...$disk->allFiles($recipeDirectory.'/rich-content'),
            ])
            ->filter(fn (string $path): bool => preg_match('#^recipes/[^/]+/(?:featured-images|rich-content)/.+$#', $path) === 1)
            ->unique()
            ->values();
    }

    private function recipePublicId(string $path): string
    {
        return explode('/', $path, 3)[1];
    }
}
