<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\UserPackagingItem;
use App\Services\MediaStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('app:migrate-user-media-to-private')]
#[Description('Move personal ingredient and packaging media into private, record-specific storage')]
class MigrateUserMediaToPrivate extends Command
{
    public function handle(): int
    {
        $mappings = $this->mappings();

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

        $this->components->info(count($mappings).' user media reference(s) migrated to private storage.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{record: Model, field: string, source: string, target: string}>
     */
    private function mappings(): array
    {
        $mappings = [];

        Ingredient::query()
            ->whereNotNull('owner_type')
            ->orderBy('id')
            ->each(function (Ingredient $ingredient) use (&$mappings): void {
                foreach ([
                    'featured_image_path' => 'featured-images',
                    'icon_image_path' => 'icons',
                ] as $field => $directory) {
                    $source = $ingredient->getAttribute($field);

                    if (! is_string($source) || blank($source) || MediaStorage::isIngredientPath($ingredient, $source)) {
                        continue;
                    }

                    $mappings[] = [
                        'record' => $ingredient,
                        'field' => $field,
                        'source' => $source,
                        'target' => MediaStorage::ingredientDirectory($ingredient, $directory).'/'.basename($source),
                    ];
                }
            });

        UserPackagingItem::query()
            ->orderBy('id')
            ->each(function (UserPackagingItem $packagingItem) use (&$mappings): void {
                $source = $packagingItem->featured_image_path;

                if (! is_string($source) || blank($source) || MediaStorage::isPackagingItemPath($packagingItem, $source)) {
                    return;
                }

                $mappings[] = [
                    'record' => $packagingItem,
                    'field' => 'featured_image_path',
                    'source' => $source,
                    'target' => MediaStorage::packagingItemDirectory($packagingItem, 'featured-images').'/'.basename($source),
                ];
            });

        return $mappings;
    }

    /**
     * @param  array<int, array{record: Model, field: string, source: string, target: string}>  $mappings
     */
    private function copyAndVerify(array $mappings): void
    {
        $publicDisk = Storage::disk(MediaStorage::publicDisk());
        $privateDisk = Storage::disk(MediaStorage::userDisk());

        foreach ($mappings as $mapping) {
            $sourceDisk = $publicDisk->exists($mapping['source'])
                ? $publicDisk
                : ($privateDisk->exists($mapping['source']) ? $privateDisk : null);

            if ($sourceDisk === null) {
                throw new RuntimeException("Missing user media: {$mapping['source']}. No database references were changed.");
            }

            $contents = $sourceDisk->get($mapping['source']);

            if ($privateDisk->exists($mapping['target'])) {
                if (hash('sha256', $privateDisk->get($mapping['target'])) !== hash('sha256', $contents)) {
                    throw new RuntimeException("Private user media target contains different data: {$mapping['target']}.");
                }
            } else {
                $privateDisk->put(
                    $mapping['target'],
                    $contents,
                    MediaStorage::writeOptions(MediaStorage::userDisk(), MediaStorage::userVisibility()),
                );
            }

            if (! $privateDisk->exists($mapping['target'])
                || hash('sha256', $privateDisk->get($mapping['target'])) !== hash('sha256', $contents)) {
                throw new RuntimeException("Failed to verify private user media copy: {$mapping['target']}.");
            }
        }
    }

    /**
     * @param  array<int, array{record: Model, field: string, source: string, target: string}>  $mappings
     */
    private function updateReferences(array $mappings): void
    {
        DB::transaction(function () use ($mappings): void {
            foreach ($mappings as $mapping) {
                $mapping['record']->setAttribute($mapping['field'], $mapping['target']);
                $mapping['record']->save();
            }
        });
    }

    /**
     * @param  array<int, array{record: Model, field: string, source: string, target: string}>  $mappings
     */
    private function deleteLegacySources(array $mappings): void
    {
        $protectedPaths = Ingredient::query()
            ->get(['featured_image_path', 'icon_image_path'])
            ->flatMap(fn (Ingredient $ingredient): array => [
                $ingredient->featured_image_path,
                $ingredient->icon_image_path,
            ])
            ->merge(UserPackagingItem::query()->pluck('featured_image_path'))
            ->filter()
            ->unique();
        $pathsToDelete = collect($mappings)
            ->pluck('source')
            ->unique()
            ->diff($protectedPaths)
            ->values()
            ->all();

        Storage::disk(MediaStorage::publicDisk())->delete($pathsToDelete);
        Storage::disk(MediaStorage::userDisk())->delete($pathsToDelete);
    }

    private function assertSafeDiskConfiguration(): void
    {
        if (MediaStorage::publicDisk() === MediaStorage::userDisk()) {
            throw new RuntimeException('Public and private user media disks must be different before migration.');
        }
    }

    /**
     * @param  array<int, array{record: Model, field: string, source: string, target: string}>  $mappings
     */
    private function assertNoTargetCollisions(array $mappings): void
    {
        $collision = collect($mappings)
            ->groupBy('target')
            ->first(fn ($targetMappings): bool => $targetMappings->pluck('source')->unique()->count() > 1);

        if ($collision !== null) {
            throw new RuntimeException('Multiple legacy user files resolve to the same private target. Rename them before migrating.');
        }
    }
}
