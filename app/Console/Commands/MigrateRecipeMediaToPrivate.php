<?php

namespace App\Console\Commands;

use App\Models\Recipe;
use App\Services\MediaStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('app:migrate-recipe-media-to-private')]
#[Description('Move legacy formula media into private, recipe-specific storage and update references')]
class MigrateRecipeMediaToPrivate extends Command
{
    public function handle(): int
    {
        $recipes = Recipe::withoutGlobalScopes()->orderBy('id')->get();
        $mappings = [];

        foreach ($recipes as $recipe) {
            foreach ($recipe->mediaPaths() as $sourcePath) {
                if (MediaStorage::isRecipePath($recipe, $sourcePath)) {
                    continue;
                }

                $directory = str_contains($sourcePath, '/rich-content/')
                    || str_starts_with($sourcePath, 'recipes/rich-content/')
                    ? 'rich-content'
                    : 'featured-images';
                $targetPath = MediaStorage::recipeDirectory($recipe, $directory).'/'.basename($sourcePath);
                $mappings[] = [
                    'recipe' => $recipe,
                    'source' => $sourcePath,
                    'target' => $targetPath,
                ];
            }
        }

        try {
            $this->assertSafeDiskConfiguration();
            $this->assertNoTargetCollisions($mappings);
            $this->copyAndVerify($mappings);
            $this->updateReferences($mappings);
            $this->deleteLegacySources($mappings);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(count($mappings).' recipe media reference(s) migrated to private storage.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{recipe: Recipe, source: string, target: string}>  $mappings
     */
    private function copyAndVerify(array $mappings): void
    {
        $publicDisk = Storage::disk(MediaStorage::publicDisk());
        $privateDisk = Storage::disk(MediaStorage::recipeDisk());

        foreach ($mappings as $mapping) {
            $sourceDisk = $publicDisk->exists($mapping['source'])
                ? $publicDisk
                : ($privateDisk->exists($mapping['source']) ? $privateDisk : null);

            if ($sourceDisk === null) {
                throw new RuntimeException("Missing recipe media: {$mapping['source']}. No database references were changed.");
            }

            $contents = $sourceDisk->get($mapping['source']);

            if ($privateDisk->exists($mapping['target'])) {
                if (hash('sha256', $privateDisk->get($mapping['target'])) !== hash('sha256', $contents)) {
                    throw new RuntimeException("Private recipe media target contains different data: {$mapping['target']}.");
                }
            } else {
                $privateDisk->put(
                    $mapping['target'],
                    $contents,
                    MediaStorage::writeOptions(MediaStorage::recipeDisk(), MediaStorage::recipeVisibility()),
                );
            }

            if (! $privateDisk->exists($mapping['target'])
                || hash('sha256', $privateDisk->get($mapping['target'])) !== hash('sha256', $contents)) {
                throw new RuntimeException("Failed to verify private recipe media copy: {$mapping['target']}.");
            }
        }
    }

    /**
     * @param  array<int, array{recipe: Recipe, source: string, target: string}>  $mappings
     */
    private function updateReferences(array $mappings): void
    {
        DB::transaction(function () use ($mappings): void {
            collect($mappings)
                ->groupBy(fn (array $mapping): int => $mapping['recipe']->id)
                ->each(function ($recipeMappings): void {
                    /** @var Recipe $recipe */
                    $recipe = $recipeMappings->first()['recipe'];
                    $replacements = $recipeMappings
                        ->mapWithKeys(fn (array $mapping): array => [$mapping['source'] => $mapping['target']])
                        ->all();

                    $recipe->featured_image_path = $recipe->featured_image_path !== null
                        ? ($replacements[$recipe->featured_image_path] ?? $recipe->featured_image_path)
                        : null;
                    $recipe->description = is_string($recipe->description)
                        ? str_replace(array_keys($replacements), array_values($replacements), $recipe->description)
                        : null;
                    $recipe->manufacturing_instructions = is_string($recipe->manufacturing_instructions)
                        ? str_replace(array_keys($replacements), array_values($replacements), $recipe->manufacturing_instructions)
                        : null;
                    $recipe->save();
                });
        });
    }

    /**
     * @param  array<int, array{recipe: Recipe, source: string, target: string}>  $mappings
     */
    private function deleteLegacySources(array $mappings): void
    {
        $publicDisk = Storage::disk(MediaStorage::publicDisk());
        $privateDisk = Storage::disk(MediaStorage::recipeDisk());
        $protectedPrivatePaths = Recipe::withoutGlobalScopes()
            ->get()
            ->flatMap(fn (Recipe $recipe) => $recipe->mediaPaths())
            ->unique();
        $privatePathsToDelete = collect($mappings)
            ->pluck('source')
            ->merge($privateDisk->allFiles('recipes/featured-images'))
            ->merge($privateDisk->allFiles('recipes/rich-content'))
            ->unique()
            ->diff($protectedPrivatePaths)
            ->values();

        $publicDisk->delete($publicDisk->allFiles('recipes'));
        $privateDisk->delete($privatePathsToDelete->all());

        if ($publicDisk->allFiles('recipes') !== []) {
            throw new RuntimeException('Public recipe media remains after migration.');
        }

        $remainingPrivateLegacyPaths = collect($privateDisk->allFiles('recipes/featured-images'))
            ->merge($privateDisk->allFiles('recipes/rich-content'));

        if ($remainingPrivateLegacyPaths->isNotEmpty()) {
            throw new RuntimeException('Legacy unnamespaced recipe media remains after migration.');
        }
    }

    private function assertSafeDiskConfiguration(): void
    {
        if (MediaStorage::publicDisk() === MediaStorage::recipeDisk()) {
            throw new RuntimeException('Public and private recipe media disks must be different before migration.');
        }
    }

    /**
     * @param  array<int, array{recipe: Recipe, source: string, target: string}>  $mappings
     */
    private function assertNoTargetCollisions(array $mappings): void
    {
        $collision = collect($mappings)
            ->groupBy('target')
            ->first(fn ($targetMappings): bool => $targetMappings->pluck('source')->unique()->count() > 1);

        if ($collision !== null) {
            throw new RuntimeException('Multiple legacy recipe files resolve to the same private target. Rename them before migrating.');
        }
    }
}
