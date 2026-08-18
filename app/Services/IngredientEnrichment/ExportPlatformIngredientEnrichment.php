<?php

namespace App\Services\IngredientEnrichment;

use App\Models\Ingredient;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExportPlatformIngredientEnrichment
{
    public function __construct(
        private readonly IngredientEnrichmentSnapshotBuilder $snapshotBuilder,
        private readonly IngredientEnrichmentInputBuilder $inputBuilder,
    ) {}

    /**
     * @param  list<string>  $catalogKeys
     * @return array{path: string, records: int, count: int, sha256: string}
     */
    public function handle(?string $requestedPath = null, array $catalogKeys = [], bool $includeComplete = false): array
    {
        $path = $this->resolvePath($requestedPath);
        $catalogKeys = collect($catalogKeys)
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (string $key): string => trim($key))
            ->unique()
            ->values()
            ->all();

        $query = Ingredient::query()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->with([
                'identifiers',
                'aliases',
                'functions',
                'translations',
                'marketLabels',
                'sapProfile',
                'fattyAcidEntries.fattyAcid',
            ])
            ->orderBy('catalog_key');

        if ($catalogKeys !== []) {
            $available = Ingredient::query()
                ->withoutGlobalScopes()
                ->whereIn('catalog_key', $catalogKeys)
                ->pluck('catalog_key')
                ->all();
            $unknown = array_values(array_diff($catalogKeys, $available));

            if ($unknown !== []) {
                throw new RuntimeException('Unknown ingredient catalog key(s): '.implode(', ', $unknown));
            }

            $query->whereIn('catalog_key', $catalogKeys);
        }

        $records = $query
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $includeComplete
                || $catalogKeys !== []
                || $this->snapshotBuilder->isIncomplete($ingredient))
            ->sortBy('catalog_key')
            ->values()
            ->map(fn (Ingredient $ingredient): array => $this->inputBuilder->build($ingredient))
            ->all();

        $contents = collect($records)
            ->map(fn (array $record): string => json_encode(
                $record,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ))
            ->implode(PHP_EOL);
        $contents = $contents === '' ? '' : $contents.PHP_EOL;

        File::ensureDirectoryExists(dirname($path));
        $temporaryPath = $path.'.tmp.'.Str::random(12);

        try {
            $writtenBytes = File::put($temporaryPath, $contents);

            if ($writtenBytes !== strlen($contents) || ! File::move($temporaryPath, $path)) {
                throw new RuntimeException("The enrichment export could not replace [{$path}].");
            }
        } catch (Throwable $exception) {
            File::delete($temporaryPath);

            throw $exception;
        }

        $sha256 = hash_file('sha256', $path);

        if ($sha256 === false) {
            throw new RuntimeException("The enrichment export checksum could not be calculated for [{$path}].");
        }

        return [
            'path' => $path,
            'records' => count($records),
            'count' => count($records),
            'sha256' => $sha256,
        ];
    }

    private function resolvePath(?string $requestedPath): string
    {
        if ($requestedPath === null || trim($requestedPath) === '') {
            return base_path((string) config('ingredient-enrichment.default_export_path'));
        }

        return str_starts_with($requestedPath, DIRECTORY_SEPARATOR)
            ? $requestedPath
            : base_path($requestedPath);
    }
}
