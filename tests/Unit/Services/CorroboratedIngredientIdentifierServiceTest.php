<?php

use App\Services\IngredientEnrichment\CorroboratedIngredientIdentifierService;
use Tests\TestCase;

uses(TestCase::class);

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function corroborationEvidenceRow(string $url, string $scheme, string $value, array $overrides = []): array
{
    return [
        'field' => 'proposal.info_markdown',
        'source_name' => 'Supplier sheet',
        'source_url' => $url,
        'claim_type' => 'formulation_role',
        'source_kind' => 'supplier_technical',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
        'identifiers' => [$scheme => [$value]],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function corroborationFacts(array $overrides = []): array
{
    return [
        'proposal' => ['identifiers' => []],
        'evidence' => [],
        'value_provenance' => [],
        'field_confidence' => [],
        ...$overrides,
    ];
}

it('adds an identifier printed by two independent publishers with approved-secondary provenance', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://supplier-b.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toHaveCount(1)
        ->and($facts['proposal']['identifiers'][0])->toMatchArray([
            'scheme' => 'cas',
            'value' => '68956-68-3',
            'is_primary' => false,
            'source_tier' => 'approved_secondary',
            'confidence' => 'supported',
        ])
        ->and(collect($facts['evidence'])->pluck('source_url')->all())->toBe([
            'https://supplier-a.com/technical/argan-oil.pdf',
            'https://supplier-b.com/technical/argan-oil.pdf',
        ])
        ->and($facts['value_provenance'][0])->toMatchArray([
            'kind' => 'source_confirmed',
            'source_urls' => [
                'https://supplier-a.com/technical/argan-oil.pdf',
                'https://supplier-b.com/technical/argan-oil.pdf',
            ],
        ]);
});

it('does not treat two pages on one publisher as independent authorities', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://supplier-a.com/spec-sheets/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://www.supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toBe([]);
});

it('does not treat sibling subdomains on one publisher as independent authorities', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('https://docs.supplier.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://shop.supplier.com/spec-sheets/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toBe([]);
});

it('accepts identifiers printed by two independent registrable publishers', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://supplier-b.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toHaveCount(1);
});

it('accepts identifiers from unrelated multi-label-suffix publishers', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('https://docs.supplier-a.co.uk/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://shop.supplier-b.co.uk/technical/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toHaveCount(1);
});

it('rejects an identifier when one source has no resolvable publisher domain', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://localhost/technical/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toBe([]);
});

it('does not count urls without a parseable host as authorities', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts(), [
        corroborationEvidenceRow('not-a-url', 'cas', '68956-68-3'),
        corroborationEvidenceRow('', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toBe([]);
});

it('skips a value the official record already carries even when two hosts print it', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts([
        'proposal' => [
            'identifiers' => [[
                'scheme' => 'cas',
                'value' => '68956-68-3',
                'is_primary' => true,
                'source_tier' => 'official',
                'confidence' => 'verified',
            ]],
        ],
    ]), [
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://supplier-b.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
    ]);

    expect($facts['proposal']['identifiers'])->toHaveCount(1);
});

it('keeps a different value of an officially covered scheme as a non-primary secondary', function (): void {
    $service = app(CorroboratedIngredientIdentifierService::class);

    $facts = $service->merge(corroborationFacts([
        'proposal' => [
            'identifiers' => [[
                'scheme' => 'cas',
                'value' => '223747-87-3',
                'is_primary' => true,
                'source_tier' => 'official',
                'confidence' => 'verified',
            ]],
        ],
    ]), [
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://supplier-b.com/technical/argan-oil.pdf', 'cas', '68956-68-3'),
        corroborationEvidenceRow('https://supplier-a.com/technical/argan-oil.pdf', 'ec', '614-11-9'),
        corroborationEvidenceRow('https://supplier-b.com/technical/argan-oil.pdf', 'ec', '614-11-9'),
    ]);

    expect(collect($facts['proposal']['identifiers'])->pluck('value')->all())->toBe(['223747-87-3', '68956-68-3', '614-11-9'])
        ->and($facts['proposal']['identifiers'][1])->toMatchArray([
            'scheme' => 'cas',
            'value' => '68956-68-3',
            'is_primary' => false,
            'source_tier' => 'approved_secondary',
        ])
        ->and($facts['proposal']['identifiers'][0]['is_primary'])->toBeTrue()
        ->and($facts['proposal']['identifiers'][2]['scheme'])->toBe('ec');
});
