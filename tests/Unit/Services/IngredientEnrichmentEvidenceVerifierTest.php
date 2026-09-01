<?php

use App\Services\IngredientEnrichment\IngredientEnrichmentEvidenceVerifier;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('accepts a consulted broad-source citation for guidance', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.info_markdown',
            'source_url' => 'https://supplier.example/technical/apricot-oil.pdf',
            'source_tier' => 'editorial',
        ]],
    ];

    $verified = false;

    app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf',
        'title' => 'Supplier technical data',
    ]]);

    $verified = true;

    expect($verified)->toBeTrue();
});

it('rejects an unconsulted broad-source citation for guidance', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.info_markdown',
            'source_url' => 'https://supplier.example/technical/apricot-oil.pdf',
            'source_tier' => 'editorial',
        ]],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://other.example/apricot-oil',
        'title' => 'Other source',
    ]]))->toThrow(ValidationException::class);
});

it('rejects a blocked guidance citation even when it was consulted', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.info_markdown',
            'source_url' => 'https://shop.amazon.com/apricot-oil',
            'source_tier' => 'editorial',
        ]],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://shop.amazon.com/apricot-oil',
        'title' => 'Marketplace listing',
    ]]))->toThrow(ValidationException::class);
});

it('keeps identity citations on the strict source allowlist', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_url' => 'https://supplier.example/technical/apricot-oil.pdf',
            'source_tier' => 'editorial',
        ]],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf',
        'title' => 'Supplier technical data',
    ]]))->toThrow(ValidationException::class);
});

it('accepts approved-secondary identifier citations from consulted technical sources', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.identifiers.1',
            'source_url' => 'https://supplier.example/technical/apricot-oil.pdf',
            'source_tier' => 'approved_secondary',
        ]],
        'proposal' => [
            'identifiers' => [[
                'field' => 'proposal.identifiers.1',
                'source_url' => 'https://supplier.example/technical/apricot-oil.pdf',
                'source_tier' => 'approved_secondary',
            ]],
        ],
    ];

    app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf',
        'title' => 'Supplier technical data',
    ]]);

    expect(true)->toBeTrue();
});

it('rejects approved-secondary identifier citations from unconsulted sources', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.identifiers.1',
            'source_url' => 'https://other.example/apricot-oil.pdf',
            'source_tier' => 'approved_secondary',
        ]],
        'proposal' => [
            'identifiers' => [[
                'field' => 'proposal.identifiers.1',
                'source_url' => 'https://other.example/apricot-oil.pdf',
                'source_tier' => 'approved_secondary',
            ]],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf',
        'title' => 'Supplier technical data',
    ]]))->toThrow(ValidationException::class);
});
