<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientIdentifierScheme;
use App\Enums\IngredientLabelMarket;
use App\Enums\IngredientSubcategory;
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
            'catalog_key',
            'source_fingerprint',
            'proposal',
            'evidence',
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
            'checked_at',
        ])
        ->and(data_get($schema, 'properties.proposal.properties.category.enum'))->toBe(
            collect(IngredientCategory::cases())->map->value->all(),
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
