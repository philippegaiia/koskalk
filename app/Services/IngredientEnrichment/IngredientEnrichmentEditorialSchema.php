<?php

namespace App\Services\IngredientEnrichment;

class IngredientEnrichmentEditorialSchema
{
    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function build(array $facts): array
    {
        $locales = collect(data_get($facts, 'vocabulary.locales', []))
            ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
            ->values()
            ->all();
        $categories = collect(data_get($facts, 'vocabulary.category.allowed', []))
            ->filter(fn (mixed $category): bool => is_string($category) && $category !== '')
            ->values()
            ->all();
        $subcategories = collect(data_get($facts, 'vocabulary.subcategories', []))
            ->filter(fn (mixed $subcategory): bool => is_string($subcategory) && $subcategory !== '')
            ->values()
            ->all();

        return $this->object([
            'display_name' => $this->string(),
            'category' => $this->nullableString(enum: $categories),
            'subcategory' => $this->nullableString(enum: $subcategories),
            'saponification_name' => $this->nullableString(),
            'soap_inci_naoh_name' => $this->nullableString(),
            'soap_inci_koh_name' => $this->nullableString(),
            'info_markdown' => $this->string(),
            'soapmaking_relevant' => ['type' => 'boolean'],
            'translations' => $this->array($this->object([
                'locale' => $this->string(enum: $locales),
                'display_name' => $this->string(),
                'saponification_name' => $this->nullableString(),
                'info_markdown' => $this->string(),
            ])),
            'warnings' => $this->array($this->string()),
            'unresolved_questions' => $this->array($this->string()),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function array(array $items): array
    {
        return [
            'type' => 'array',
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>|null  $enum
     * @return array<string, mixed>
     */
    private function string(?array $enum = null): array
    {
        return array_filter([
            'type' => 'string',
            'enum' => $enum,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function nullableString(?array $enum = null): array
    {
        return array_filter([
            'type' => ['string', 'null'],
            'enum' => $enum === null ? null : [...$enum, null],
        ], fn (mixed $value): bool => $value !== null);
    }
}
