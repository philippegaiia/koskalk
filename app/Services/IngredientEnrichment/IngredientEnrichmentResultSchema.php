<?php

namespace App\Services\IngredientEnrichment;

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientSubcategory;
use App\Enums\IngredientValueProvenance;

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
            'subject_type' => $this->string(enum: ['ingredient', 'intake']),
            'subject_public_id' => $this->string(),
            'catalog_key' => $this->nullableString(),
            'source_fingerprint' => $this->string(pattern: '^[a-f0-9]{64}$'),
            'proposal' => $this->object([
                'display_name' => $this->string(),
                'inci_name' => $this->string(),
                'category' => $this->nullableString(enum: [
                    ...collect(IngredientCategory::cases())->map->value->all(),
                    null,
                ]),
                'subcategory' => $this->nullableString(enum: [
                    ...collect(IngredientSubcategory::cases())->map->value->all(),
                    null,
                ]),
                'saponification_name' => $this->nullableString(),
                'soap_inci_naoh_name' => $this->nullableString(),
                'soap_inci_koh_name' => $this->nullableString(),
                'info_markdown' => $this->string(),
                'soapmaking_relevant' => ['type' => 'boolean'],
                'aliases' => $this->array($this->object([
                    'locale' => $this->string(),
                    'name' => $this->string(),
                    'kind' => $this->string(enum: collect(IngredientAliasKind::cases())->map->value->all()),
                    ...$source,
                ])),
                'identifiers' => $this->array($this->object([
                    'scheme' => $this->string(enum: collect(IngredientIdentifierScheme::cases())->map->value->all()),
                    'value' => $this->string(),
                    'is_primary' => ['type' => 'boolean'],
                    ...$source,
                ])),
                'cosing_functions' => $this->array($this->object([
                    'key' => $this->string(enum: $cosingKeys),
                    ...$source,
                ])),
                'translations' => $this->array($this->object([
                    'locale' => $this->string(enum: array_values(config('interface-translations.catalogue_locales', []))),
                    'display_name' => $this->string(),
                    'saponification_name' => $this->nullableString(),
                    'info_markdown' => $this->string(),
                ])),
                'market_labels' => $this->array($this->object([
                    'market_code' => $this->string(enum: collect(IngredientLabelMarket::cases())->map->value->all()),
                    'declaration_name' => $this->string(),
                    'reviewed_at' => $this->nullableString(format: 'date'),
                    'effective_from' => $this->nullableString(format: 'date'),
                    'effective_until' => $this->nullableString(format: 'date'),
                    ...$source,
                ])),
            ]),
            'field_confidence' => $this->array($this->object([
                'field' => $this->string(),
                'confidence' => $this->string(enum: collect(IngredientEvidenceConfidence::cases())->map->value->all()),
            ])),
            'value_provenance' => $this->array($this->object([
                'field' => $this->string(),
                'kind' => $this->string(enum: collect(IngredientValueProvenance::cases())->map->value->all()),
                'reasoning' => $this->string(),
                'source_urls' => $this->array($this->string()),
            ])),
            'evidence' => $this->array($this->object([
                'field' => $this->string(),
                ...$source,
            ])),
            'guidance_evidence' => $this->array($this->object([
                'source_name' => $this->string(),
                'source_url' => $this->string(),
                'summary' => $this->string(),
                'source_tier' => $this->string(enum: ['editorial']),
                'retrieved_at' => $this->string(format: 'date-time'),
            ])),
            'regulatory_findings' => $this->array($this->object([
                'market_code' => $this->string(enum: collect(IngredientLabelMarket::cases())->map->value->all()),
                'finding' => $this->string(),
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
            'source_name' => $this->string(),
            'source_url' => $this->string(),
            'source_tier' => $this->string(enum: collect(IngredientSourceTier::cases())->map->value->all()),
            'confidence' => $this->string(enum: collect(IngredientEvidenceConfidence::cases())->map->value->all()),
            'source_version' => $this->nullableString(),
            'source_updated_at' => $this->nullableString(format: 'date'),
            'retrieved_at' => $this->string(format: 'date-time'),
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
    private function string(?array $enum = null, ?string $format = null, ?string $pattern = null): array
    {
        return array_filter([
            'type' => 'string',
            'enum' => $enum,
            'format' => $format,
            'pattern' => $pattern,
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
