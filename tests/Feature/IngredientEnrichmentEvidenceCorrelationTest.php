<?php

use App\Services\IngredientEnrichment\IngredientEnrichmentEvidenceVerifier;
use Illuminate\Validation\ValidationException;

it('requires nested source citations to agree with field-specific evidence', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.aliases.0',
            'source_name' => 'FDA GSRS',
            'source_url' => 'https://api.fda.gov/other/substance.json',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => 'gsrs-v1',
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-16T10:00:00+00:00',
        ]],
        'proposal' => [
            'aliases' => [[
                'locale' => 'und',
                'name' => 'Different source value',
                'kind' => 'common',
                'source_name' => 'FDA GSRS',
                'source_url' => 'https://precision.fda.gov/uniisearch/srs/unii/example',
                'source_tier' => 'official',
                'confidence' => 'verified',
                'source_version' => 'gsrs-v1',
                'source_updated_at' => null,
                'retrieved_at' => '2026-08-16T10:00:00+00:00',
            ]],
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, []))
        ->toThrow(ValidationException::class);
});

it('accepts a nested citation when the same field and source are present in evidence', function (): void {
    $source = [
        'source_name' => 'FDA GSRS',
        'source_url' => 'https://api.fda.gov/other/substance.json',
        'source_tier' => 'official',
        'confidence' => 'verified',
        'source_version' => 'gsrs-v1',
        'source_updated_at' => null,
        'retrieved_at' => '2026-08-16T10:00:00+00:00',
    ];
    $result = [
        'evidence' => [['field' => 'proposal.aliases.0', ...$source]],
        'proposal' => [
            'aliases' => [[
                'locale' => 'und',
                'name' => 'Argan oil',
                'kind' => 'common',
                ...$source,
            ]],
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, []))
        ->not->toThrow(ValidationException::class);
});
