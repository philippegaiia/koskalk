<?php

use App\Enums\IngredientAliasKind;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEvidenceConfidence;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSourceTier;
use App\Enums\IngredientSubcategory;
use App\Enums\IngredientValueProvenance;
use App\Services\IngredientEnrichment\IngredientEnrichmentResultSchema;

it('describes the exact enrichment validator contract as a strict json schema', function (): void {
    config()->set('interface-translations.catalogue_locales', ['de', 'fr', 'pt_BR']);

    $schema = app(IngredientEnrichmentResultSchema::class)->build();

    expect($schema)->toMatchArray([
        'type' => 'object',
        'additionalProperties' => false,
    ])
        ->and($schema['required'])->toBe([
            'format',
            'schema_version',
            'subject_type',
            'subject_public_id',
            'catalog_key',
            'source_fingerprint',
            'proposal',
            'field_confidence',
            'value_provenance',
            'evidence',
            'regulatory_findings',
            'confidence',
            'warnings',
            'unresolved_questions',
        ])
        ->and(data_get($schema, 'properties.proposal.additionalProperties'))->toBeFalse()
        ->and(data_get($schema, 'properties.proposal.properties.identifiers.items.required'))->toBe([
            'scheme',
            'value',
            'is_primary',
            'source_name',
            'source_url',
            'source_tier',
            'confidence',
            'source_version',
            'source_updated_at',
            'retrieved_at',
        ])
        ->and(data_get($schema, 'properties.proposal.properties.aliases.items.required'))->toBe([
            'locale',
            'name',
            'kind',
            'source_name',
            'source_url',
            'source_tier',
            'confidence',
            'source_version',
            'source_updated_at',
            'retrieved_at',
        ])
        ->and(data_get($schema, 'properties.proposal.properties.aliases.items.properties.kind.enum'))->toBe(
            collect(IngredientAliasKind::cases())->map->value->all(),
        )
        ->and(data_get($schema, 'properties.field_confidence.items.required'))->toBe([
            'field',
            'confidence',
        ])
        ->and(data_get($schema, 'properties.field_confidence.items.properties.confidence.enum'))->toBe(
            collect(IngredientEvidenceConfidence::cases())->map->value->all(),
        )
        ->and(data_get($schema, 'properties.value_provenance.items.required'))->toBe([
            'field',
            'kind',
            'reasoning',
            'source_urls',
        ])
        ->and(data_get($schema, 'properties.value_provenance.items.properties.kind.enum'))->toBe(
            collect(IngredientValueProvenance::cases())->map->value->all(),
        )
        ->and(data_get($schema, 'properties.evidence.items.properties.source_tier.enum'))->toBe(
            collect(IngredientSourceTier::cases())->map->value->all(),
        )
        ->and(data_get($schema, 'properties.regulatory_findings.items.required'))->toBe([
            'market_code',
            'finding',
            'source_name',
            'source_url',
            'source_tier',
            'confidence',
            'source_version',
            'source_updated_at',
            'retrieved_at',
        ])
        ->and(data_get($schema, 'properties.proposal.properties.category.enum'))->toBe(
            [...collect(IngredientCategory::cases())->map->value->all(), null],
        )
        ->and(data_get($schema, 'properties.proposal.properties.subcategory.enum'))->toBe([
            ...collect(IngredientSubcategory::cases())->map->value->all(),
            null,
        ])
        ->and(data_get($schema, 'properties.proposal.properties.identifiers.items.properties.scheme.enum'))->toBe(
            collect(IngredientIdentifierScheme::cases())->map->value->all(),
        )
        ->and(data_get($schema, 'properties.proposal.properties.translations.items.properties.locale.enum'))->toBe([
            'de',
            'fr',
            'pt_BR',
        ])
        ->and(data_get($schema, 'properties.proposal.properties.market_labels.items.properties.market_code.enum'))->toBe(
            collect(IngredientLabelMarket::cases())->map->value->all(),
        );

    assertEveryObjectNodeIsStrict($schema);
});

it('uses only string constraints supported by OpenAI structured outputs', function (): void {
    $schema = app(IngredientEnrichmentResultSchema::class)->build([
        'vocabulary' => [
            'cosing_functions' => [['key' => 'EMOLLIENT']],
        ],
    ]);

    assertEverySchemaNodeUsesSupportedKeywords($schema);
});

/**
 * @param  array<string, mixed>  $node
 */
function assertEveryObjectNodeIsStrict(array $node): void
{
    if (($node['type'] ?? null) === 'object') {
        expect($node['additionalProperties'] ?? null)->toBeFalse()
            ->and($node['required'] ?? null)->toBe(array_keys($node['properties'] ?? []));
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            assertEveryObjectNodeIsStrict($value);
        }
    }
}

/**
 * @param  array<string, mixed>  $node
 */
function assertEverySchemaNodeUsesSupportedKeywords(array $node): void
{
    if (array_key_exists('type', $node)) {
        expect(array_keys($node))->each->toBeIn([
            'type',
            'properties',
            'required',
            'additionalProperties',
            'items',
            'enum',
            'pattern',
            'format',
        ]);

        if (array_key_exists('format', $node)) {
            expect($node['format'])->toBeIn([
                'date-time',
                'time',
                'date',
                'duration',
                'email',
                'hostname',
                'ipv4',
                'ipv6',
                'uuid',
            ]);
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            assertEverySchemaNodeUsesSupportedKeywords($value);
        }
    }
}
