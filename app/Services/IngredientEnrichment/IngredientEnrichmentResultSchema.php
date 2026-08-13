<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientCategory;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSubcategory;

class IngredientEnrichmentResultSchema
{
    /**
     * @param  array<string, mixed>|null  $record
     * @return array<string, mixed>
     */
    public function build(?array $record = null): array
    {
        $source = $this->sourceProperties();
        $cosingKeys = collect(data_get($record, 'vocabulary.cosing_functions', []))
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->values()
            ->all();

        return $this->object([
            'format' => $this->string(enum: [(string) config('ingredient-enrichment.result_format')]),
            'schema_version' => [
                'type' => 'integer',
                'enum' => [(int) config('ingredient-enrichment.schema_version')],
            ],
            'catalog_key' => $this->string(minLength: 1),
            'source_fingerprint' => $this->string(pattern: '^[a-f0-9]{64}$'),
            'proposal' => $this->object([
                'display_name' => $this->string(minLength: 1),
                'inci_name' => $this->string(minLength: 1),
                'category' => $this->string(enum: collect(IngredientCategory::cases())->map->value->all()),
                'subcategory' => $this->nullableString(enum: [
                    ...collect(IngredientSubcategory::cases())->map->value->all(),
                    null,
                ]),
                'saponification_name' => $this->nullableString(),
                'info_markdown' => $this->string(minLength: 1),
                'soapmaking_relevant' => ['type' => 'boolean'],
                'identifiers' => $this->array($this->object([
                    'scheme' => $this->string(enum: collect(IngredientIdentifierScheme::cases())->map->value->all()),
                    'value' => $this->string(minLength: 1),
                    'is_primary' => ['type' => 'boolean'],
                    ...$source,
                ])),
                'cosing_functions' => $this->array($this->object([
                    'key' => $this->string(enum: $cosingKeys),
                    ...$source,
                ])),
                'translations' => $this->array($this->object([
                    'locale' => $this->string(enum: array_values(config('interface-translations.catalogue_locales', []))),
                    'display_name' => $this->string(minLength: 1),
                    'saponification_name' => $this->nullableString(),
                    'info_markdown' => $this->string(minLength: 1),
                ])),
                'market_labels' => $this->array($this->object([
                    'market_code' => $this->string(enum: collect(IngredientLabelMarket::cases())->map->value->all()),
                    'declaration_name' => $this->string(minLength: 1),
                    'source_name' => $this->string(minLength: 1),
                    'source_url' => $this->string(format: 'uri'),
                    'reviewed_at' => $this->nullableString(format: 'date'),
                    'effective_from' => $this->nullableString(format: 'date'),
                    'effective_until' => $this->nullableString(format: 'date'),
                ])),
            ]),
            'evidence' => $this->array($this->object([
                'field' => $this->string(minLength: 1),
                ...$source,
            ])),
            'confidence' => $this->string(enum: ['low', 'medium', 'high']),
            'warnings' => $this->array($this->string()),
            'unresolved_questions' => $this->array($this->string()),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceProperties(): array
    {
        return [
            'source_name' => $this->string(minLength: 1),
            'source_url' => $this->string(format: 'uri'),
            'checked_at' => $this->string(format: 'date'),
        ];
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
    private function string(?array $enum = null, ?string $format = null, ?string $pattern = null, ?int $minLength = null): array
    {
        return array_filter([
            'type' => 'string',
            'enum' => $enum,
            'format' => $format,
            'pattern' => $pattern,
            'minLength' => $minLength,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<string|null>|null  $enum
     * @return array<string, mixed>
     */
    private function nullableString(?array $enum = null, ?string $format = null): array
    {
        return array_filter([
            'type' => ['string', 'null'],
            'enum' => $enum,
            'format' => $format,
        ], fn (mixed $value): bool => $value !== null);
    }
}
