<?php

namespace App\Support;

use JsonException;
use RuntimeException;

class IngredientCatalogConsolidationDataset
{
    public const DEFAULT_PATH = 'database/seeders/data/ingredient_catalog_consolidation.json';

    /**
     * @return list<array{action:string,source_catalog_key:string,target_catalog_key:?string,reason:string}>
     */
    public function all(?string $path = null): array
    {
        $resolvedPath = $path ?? base_path(self::DEFAULT_PATH);
        $contents = @file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read ingredient consolidation dataset at [{$resolvedPath}].");
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Ingredient consolidation dataset is invalid JSON: {$exception->getMessage()}", previous: $exception);
        }

        if (! is_array($payload)
            || ($payload['format'] ?? null) !== 'soapkraft-ingredient-catalog-consolidation'
            || ($payload['version'] ?? null) !== 1
            || ! is_array($payload['decisions'] ?? null)) {
            throw new RuntimeException('Ingredient consolidation dataset has an invalid top-level structure.');
        }

        $decisions = [];

        foreach ($payload['decisions'] as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Ingredient consolidation decision [{$index}] must be an object.");
            }

            $action = is_string($row['action'] ?? null) ? $row['action'] : '';
            $sourceCatalogKey = is_string($row['source_catalog_key'] ?? null) ? trim($row['source_catalog_key']) : '';
            $targetCatalogKey = is_string($row['target_catalog_key'] ?? null) ? trim($row['target_catalog_key']) : null;
            $reason = is_string($row['reason'] ?? null) ? trim($row['reason']) : '';

            if (! in_array($action, ['review', 'keep', 'merge_into', 'remove'], true)) {
                throw new RuntimeException("Ingredient consolidation decision [{$index}] has an invalid action.");
            }

            if ($sourceCatalogKey === '' || $reason === '') {
                throw new RuntimeException("Ingredient consolidation decision [{$index}] requires a source key and reason.");
            }

            if ($action === 'merge_into' && ($targetCatalogKey === null || $targetCatalogKey === $sourceCatalogKey)) {
                throw new RuntimeException("Ingredient consolidation decision [{$sourceCatalogKey}] requires a different target key.");
            }

            if ($action !== 'merge_into' && $targetCatalogKey !== null && $action !== 'review') {
                throw new RuntimeException("Ingredient consolidation decision [{$sourceCatalogKey}] has an unexpected target key.");
            }

            $decisions[] = [
                'action' => $action,
                'source_catalog_key' => $sourceCatalogKey,
                'target_catalog_key' => $targetCatalogKey,
                'reason' => $reason,
            ];
        }

        $sourceKeys = array_column($decisions, 'source_catalog_key');

        if ($sourceKeys !== array_values(array_unique($sourceKeys))) {
            throw new RuntimeException('Ingredient consolidation dataset contains duplicate source keys.');
        }

        return $decisions;
    }
}
