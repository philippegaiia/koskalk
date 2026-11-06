<?php

namespace App\Support;

class ProductTaxonomyTranslations
{
    /** @var array<string, array<string, array<string, array{name: string}>>>|null */
    private ?array $catalogue = null;

    /** @return array<string, array{name: string}> */
    public function namesFor(string $slug, string $table): array
    {
        $this->catalogue ??= json_decode(
            file_get_contents(database_path('seeders/data/product-taxonomy-translations-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $this->catalogue[$table][$slug] ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public function mergeMissing(?array $existing, string $slug, string $table): array
    {
        $merged = $existing ?? [];
        foreach ($this->namesFor($slug, $table) as $locale => $content) {
            $current = data_get($merged, $locale.'.name');
            if ((is_string($current) && trim($current) !== '') || ($current !== null && ! is_string($current))) {
                continue;
            }
            if (isset($merged[$locale]) && ! is_array($merged[$locale])) {
                continue;
            }
            $merged[$locale] = [...($merged[$locale] ?? []), 'name' => $content['name']];
        }

        return $merged;
    }
}
