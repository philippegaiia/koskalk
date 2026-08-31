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

    app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf',
        'title' => 'Supplier technical data',
    ]]);

    expect(true)->toBeTrue();
});

it('still rejects a blocked guidance citation', function (): void {
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
