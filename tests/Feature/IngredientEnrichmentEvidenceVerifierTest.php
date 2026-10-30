<?php

use App\Services\IngredientEnrichment\IngredientEnrichmentEvidenceVerifier;
use Illuminate\Validation\ValidationException;

it('accepts deterministic adapter evidence without an OpenAI consulted-source echo', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => '32025D1175',
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-13T12:00:00+00:00',
        ]],
        'proposal' => [
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, []))->not->toThrow(ValidationException::class);
});

it('accepts Kew botanical evidence for editorial guidance', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.info_markdown',
            'source_name' => 'Plants of the World Online — Royal Botanic Gardens, Kew',
            'source_url' => 'https://powo.science.kew.org/taxon/urn:lsid:ipni.org:names:668509-1/general-information',
            'source_tier' => 'editorial',
            'confidence' => 'supported',
            'source_version' => null,
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-14T12:00:00+00:00',
        ]],
        'proposal' => [
            'aliases' => [],
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://powo.science.kew.org/taxon/urn:lsid:ipni.org:names:668509-1/general-information',
        'title' => 'Plants of the World Online — Royal Botanic Gardens, Kew',
    ]]))
        ->not->toThrow(ValidationException::class);
});

it('rejects a structured mirror falsely marked as an official source', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'CosIng Checker',
            'source_url' => 'https://cosingchecker.com/ingredients/54495-argania-spinosa-kernel-oil/',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => 'inventory-2026-03-21',
            'source_updated_at' => '2026-03-21',
            'retrieved_at' => '2026-08-13T12:00:00+00:00',
        ]],
        'proposal' => [
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, []))
        ->toThrow(ValidationException::class);
});

/** @return array<string, mixed> */
