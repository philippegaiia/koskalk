<?php

namespace App\Support;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use JsonException;
use RuntimeException;

class IngredientCatalogTaxonomyDataset
{
    public const DEFAULT_PATH = 'database/seeders/data/ingredient_catalog_taxonomy.json';

    /**
     * @return list<array{catalog_key:string,category:IngredientCategory,subcategory:?IngredientSubcategory,is_soap_saponification_trusted:bool,requires_aromatic_compliance:bool}>
     */
    public function all(?string $path = null): array
    {
        $resolvedPath = $path ?? base_path(self::DEFAULT_PATH);
        $contents = @file_get_contents($resolvedPath);

        if ($contents === false) {
            throw new RuntimeException("Unable to read ingredient taxonomy dataset at [{$resolvedPath}].");
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Ingredient taxonomy dataset is invalid JSON: {$exception->getMessage()}", previous: $exception);
        }

        if (! is_array($payload)
            || ($payload['format'] ?? null) !== 'soapkraft-ingredient-catalog-taxonomy'
            || ($payload['version'] ?? null) !== 2
            || ! is_array($payload['assignments'] ?? null)) {
            throw new RuntimeException('Ingredient taxonomy dataset has an invalid top-level structure.');
        }

        $assignments = [];

        foreach ($payload['assignments'] as $index => $row) {
            if (! is_array($row) || array_keys($row) !== [
                'catalog_key',
                'category',
                'subcategory',
                'is_soap_saponification_trusted',
                'requires_aromatic_compliance',
            ]) {
                throw new RuntimeException("Ingredient taxonomy assignment [{$index}] has an invalid structure.");
            }

            $catalogKey = is_string($row['catalog_key']) ? trim($row['catalog_key']) : '';
            $category = is_string($row['category']) ? IngredientCategory::tryFrom($row['category']) : null;
            $subcategory = is_string($row['subcategory'] ?? null)
                ? IngredientSubcategory::tryFrom($row['subcategory'])
                : null;

            if ($catalogKey === '') {
                throw new RuntimeException("Ingredient taxonomy assignment [{$index}] has no catalog key.");
            }

            if (! $category instanceof IngredientCategory) {
                throw new RuntimeException("Ingredient taxonomy assignment [{$catalogKey}] references a non-canonical category.");
            }

            if ($category !== IngredientCategory::Other && ! $subcategory instanceof IngredientSubcategory) {
                throw new RuntimeException("Ingredient taxonomy assignment [{$catalogKey}] requires a subcategory.");
            }

            if ($subcategory instanceof IngredientSubcategory && $subcategory->category() !== $category) {
                throw new RuntimeException("Ingredient taxonomy assignment [{$catalogKey}] references an incompatible subcategory.");
            }

            if (! is_bool($row['is_soap_saponification_trusted']) || ! is_bool($row['requires_aromatic_compliance'])) {
                throw new RuntimeException("Ingredient taxonomy assignment [{$catalogKey}] has invalid capability flags.");
            }

            $assignments[] = [
                'catalog_key' => $catalogKey,
                'category' => $category,
                'subcategory' => $subcategory,
                'is_soap_saponification_trusted' => $row['is_soap_saponification_trusted'],
                'requires_aromatic_compliance' => $row['requires_aromatic_compliance'],
            ];
        }

        $catalogKeys = array_column($assignments, 'catalog_key');

        if ($catalogKeys !== array_values(array_unique($catalogKeys))) {
            throw new RuntimeException('Ingredient taxonomy dataset contains duplicate catalog keys.');
        }

        $sortedCatalogKeys = $catalogKeys;
        sort($sortedCatalogKeys, SORT_STRING);

        if ($catalogKeys !== $sortedCatalogKeys) {
            throw new RuntimeException('Ingredient taxonomy assignments must be sorted by catalog key.');
        }

        return $assignments;
    }

    /**
     * @return array{catalog_key:string,category:IngredientCategory,subcategory:?IngredientSubcategory,is_soap_saponification_trusted:bool,requires_aromatic_compliance:bool}|null
     */
    public function assignmentFor(Ingredient|string $ingredient, ?string $path = null): ?array
    {
        $catalogKey = $ingredient instanceof Ingredient
            ? (string) $ingredient->catalog_key
            : $ingredient;

        foreach ($this->all($path) as $assignment) {
            if ($assignment['catalog_key'] === $catalogKey) {
                return $assignment;
            }
        }

        return null;
    }
}
