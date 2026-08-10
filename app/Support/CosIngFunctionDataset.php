<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\IngredientFunction;
use Carbon\CarbonImmutable;
use RuntimeException;

class CosIngFunctionDataset
{
    public const DEFAULT_PATH = 'database/seeders/data/cosing_ingredient_functions.json';

    /**
     * @return list<array{catalog_key:string, inci_name:string, cosing_reference:string, source_url:string, verified_at:string, function_keys:list<string>}>
     */
    public function all(?string $path = null): array
    {
        $resolvedPath = $path ?? base_path(self::DEFAULT_PATH);
        $contents = @file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read CosIng assignment dataset at [{$resolvedPath}].");
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("CosIng assignment dataset is invalid JSON: {$exception->getMessage()}", previous: $exception);
        }

        if (! is_array($payload) || ($payload['format'] ?? null) !== 'soapkraft-cosing-ingredient-functions' || ($payload['version'] ?? null) !== 1 || ! is_array($payload['assignments'] ?? null)) {
            throw new RuntimeException('CosIng assignment dataset has an invalid top-level structure.');
        }

        $knownFunctionKeys = IngredientFunction::query()
            ->where('is_active', true)
            ->pluck('key')
            ->all();
        $assignments = [];

        foreach ($payload['assignments'] as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("CosIng assignment row [{$index}] must be an object.");
            }

            foreach (['catalog_key', 'inci_name', 'cosing_reference', 'source_url', 'verified_at'] as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    throw new RuntimeException("CosIng assignment row [{$index}] has an invalid [{$field}].");
                }
            }

            if (! filter_var($row['source_url'], FILTER_VALIDATE_URL) || ! str_starts_with($row['source_url'], 'https://single-market-economy.ec.europa.eu/')) {
                throw new RuntimeException("CosIng assignment row [{$index}] must reference an official Commission URL.");
            }

            try {
                $verifiedAt = CarbonImmutable::createFromFormat('!Y-m-d', $row['verified_at']);
            } catch (\Throwable) {
                throw new RuntimeException("CosIng assignment row [{$index}] has an invalid verified_at date.");
            }

            if ($verifiedAt === false || $verifiedAt->format('Y-m-d') !== $row['verified_at']) {
                throw new RuntimeException("CosIng assignment row [{$index}] has an invalid verified_at date.");
            }

            if (! is_array($row['function_keys'] ?? null) || $row['function_keys'] === []) {
                throw new RuntimeException("CosIng assignment row [{$index}] must include at least one function key.");
            }

            $functionKeys = collect($row['function_keys'])
                ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
                ->map(fn (string $key): string => trim($key))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($functionKeys !== $row['function_keys']) {
                throw new RuntimeException("CosIng assignment row [{$index}] function_keys must be sorted and unique.");
            }

            $unknownKeys = array_diff($functionKeys, $knownFunctionKeys);

            if ($unknownKeys !== []) {
                throw new RuntimeException("CosIng assignment row [{$index}] references unknown or inactive function keys: ".implode(', ', $unknownKeys).'.');
            }

            $assignments[] = [
                'catalog_key' => trim($row['catalog_key']),
                'inci_name' => trim($row['inci_name']),
                'cosing_reference' => trim($row['cosing_reference']),
                'source_url' => trim($row['source_url']),
                'verified_at' => trim($row['verified_at']),
                'function_keys' => $functionKeys,
            ];
        }

        $catalogKeys = array_column($assignments, 'catalog_key');

        if (count($catalogKeys) !== count(array_unique($catalogKeys))) {
            throw new RuntimeException('CosIng assignment dataset contains duplicate catalog keys.');
        }

        $cosIngReferences = array_column($assignments, 'cosing_reference');

        if (count($cosIngReferences) !== count(array_unique($cosIngReferences))) {
            throw new RuntimeException('CosIng assignment dataset contains duplicate CosIng references.');
        }

        return $assignments;
    }

    /**
     * @param  list<array{catalog_key:string, inci_name:string, cosing_reference:string, source_url:string, verified_at:string, function_keys:list<string>}>  $assignments
     * @return list<array{catalog_key:string, inci_name:string, cosing_reference:string, source_url:string, verified_at:string, function_keys:list<string>}>
     */
    public function validateAgainstCatalog(array $assignments): array
    {
        $ingredients = Ingredient::query()
            ->whereNull('owner_type')
            ->whereIn('catalog_key', array_column($assignments, 'catalog_key'))
            ->get()
            ->keyBy('catalog_key');

        foreach ($assignments as $assignment) {
            $ingredient = $ingredients->get($assignment['catalog_key']);

            if (! $ingredient instanceof Ingredient) {
                throw new RuntimeException("CosIng assignment [{$assignment['catalog_key']}] does not match an existing platform ingredient.");
            }

            if (InciName::normalize($ingredient->inci_name) !== InciName::normalize($assignment['inci_name'])) {
                throw new RuntimeException("CosIng assignment [{$assignment['catalog_key']}] does not exactly match catalog INCI.");
            }
        }

        return $assignments;
    }
}
