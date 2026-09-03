<?php

use App\Services\IngredientEnrichment\IngredientGuidanceEvidencePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

it('accepts consulted broad-source evidence and preserves its classifications', function (): void {
    $candidate = guidanceEvidenceCandidate();

    $accepted = guidanceEvidencePolicy()->validateCandidates([$candidate], [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf#page=2',
        'title' => 'Apricot oil technical data',
    ]]);

    expect($accepted)->toBe([$candidate]);
});

it('partitions valid guidance evidence from rejected candidates', function (): void {
    $valid = guidanceEvidenceCandidate();
    $unconsulted = guidanceEvidenceCandidate([
        'source_url' => 'https://unconsulted.example/apricot-oil',
    ]);
    $invalidUsage = guidanceEvidenceCandidate([
        'claim_type' => 'usage',
        'usage_application' => 'not_applicable',
    ]);

    $result = guidanceEvidencePolicy()->partitionCandidates(
        [$valid, $unconsulted, $invalidUsage],
        [['url' => $valid['source_url'], 'title' => 'Supplier technical data']],
    );

    expect($result->accepted)->toBe([$valid])
        ->and($result->rejected)->toMatchArray([
            ['index' => 1, 'code' => 'unconsulted_url', 'host' => 'unconsulted.example'],
            ['index' => 2, 'code' => 'invalid_usage_metadata', 'host' => 'supplier.example'],
        ]);
});

it('accepts well-formed source-reported identifiers and rejects malformed ones', function (): void {
    $withIdentifiers = guidanceEvidenceCandidate([
        'identifiers' => ['cas' => ['8001-29-4'], 'ec' => ['269-656-5']],
    ]);
    $accepted = guidanceEvidencePolicy()->validateCandidates([$withIdentifiers], [[
        'url' => $withIdentifiers['source_url'],
        'title' => 'Supplier technical data',
    ]]);

    expect($accepted)->toHaveCount(1)
        ->and($accepted[0]['identifiers'])->toBe(['cas' => ['8001-29-4'], 'ec' => ['269-656-5']]);

    foreach ([
        ['cas' => ['not-a-cas'], 'ec' => []],
        ['cas' => [], 'ec' => ['123-45']],
        ['cas' => '8001-29-4', 'ec' => []],
    ] as $identifiers) {
        $invalid = guidanceEvidenceCandidate(['identifiers' => $identifiers]);

        expect(fn (): array => guidanceEvidencePolicy()->validateCandidates([$invalid], [[
            'url' => $invalid['source_url'],
            'title' => 'Supplier technical data',
        ]]))->toThrow(ValidationException::class);
    }
});

it('records precise rejection codes without retaining evidence content', function (): void {
    $cases = [
        'invalid_shape' => 'not-an-array',
        'invalid_field' => guidanceEvidenceCandidate(['field' => 'proposal.inci_name']),
        'invalid_url' => guidanceEvidenceCandidate(['source_url' => 'not-a-url']),
        'blocked_domain' => guidanceEvidenceCandidate(['source_url' => 'https://amazon.com/apricot-oil']),
        'unconsulted_url' => guidanceEvidenceCandidate(['source_url' => 'https://other.example/apricot-oil']),
        'invalid_classification' => guidanceEvidenceCandidate(['claim_type' => 'not-configured']),
        'invalid_usage_metadata' => guidanceEvidenceCandidate(['usage_application' => 'not_applicable']),
    ];

    foreach ($cases as $code => $candidate) {
        $consultedSources = [['url' => guidanceEvidenceCandidate()['source_url'], 'title' => 'Source']];

        $result = guidanceEvidencePolicy()->partitionCandidates([$candidate], $consultedSources);

        expect($result->accepted)->toBe([])
            ->and($result->rejected)->toBe([
                [
                    'index' => 0,
                    'code' => $code,
                    'host' => is_array($candidate)
                        ? parse_url($candidate['source_url'], PHP_URL_HOST)
                        : null,
                ],
            ])
            ->and(json_encode($result->rejected))->not->toContain('summary')
            ->and(json_encode($result->rejected))->not->toContain('apricot-oil');
    }
});

it('rejects guidance evidence outside the approved field and closed vocabularies', function (): void {
    $cases = [
        ['field' => 'proposal.inci_name'],
        ['claim_type' => 'identity'],
        ['source_kind' => 'generic_blog'],
        ['scope' => 'universal'],
        ['evidence_kind' => 'safety_limit'],
        ['usage_application' => 'both'],
        ['percentage_basis' => 'per_batch'],
    ];

    foreach ($cases as $override) {
        expect(fn (): array => guidanceEvidencePolicy()->validateCandidates([
            [...guidanceEvidenceCandidate(), ...$override],
        ], [[
            'url' => 'https://supplier.example/technical/apricot-oil.pdf',
            'title' => 'Apricot oil technical data',
        ]]))->toThrow(ValidationException::class);
    }
});

it('rejects malformed or empty guidance evidence as invalid provider output', function (): void {
    foreach ([
        [...guidanceEvidenceCandidate(), 'source_url' => 'not-a-url'],
        [...guidanceEvidenceCandidate(), 'summary' => ''],
    ] as $candidate) {
        expect(fn (): array => guidanceEvidencePolicy()->validateCandidates([$candidate], [[
            'url' => $candidate['source_url'],
            'title' => 'Source',
        ]]))->toThrow(ValidationException::class);
    }
});

it('rejects unconsulted and blocked guidance sources', function (): void {
    $unconsulted = [...guidanceEvidenceCandidate(), 'source_url' => 'https://other.example/technical/apricot-oil.pdf'];
    $blocked = [...guidanceEvidenceCandidate(), 'source_url' => 'https://shop.amazon.com/apricot-oil'];

    expect(fn (): array => guidanceEvidencePolicy()->validateCandidates([$unconsulted], [[
        'url' => guidanceEvidenceCandidate()['source_url'],
        'title' => 'Source',
    ]]))->toThrow(ValidationException::class)
        ->and(fn (): array => guidanceEvidencePolicy()->validateCandidates([$blocked], [[
            'url' => $blocked['source_url'],
            'title' => 'Source',
        ]]))->toThrow(ValidationException::class);
});

it('requires explicitly sourced application percentages for usage recommendations', function (): void {
    $candidate = guidanceEvidenceCandidate();

    $invalid = [
        ['evidence_kind' => 'fact'],
        ['source_kind' => 'scientific'],
        ['usage_application' => 'not_applicable'],
        ['recommended_min_percent' => null, 'recommended_max_percent' => null],
        ['percentage_basis' => 'not_applicable'],
        ['recommended_min_percent' => '-1'],
        ['recommended_max_percent' => '101'],
        ['recommended_min_percent' => '11', 'recommended_max_percent' => '10'],
    ];

    foreach ($invalid as $override) {
        expect(fn (): array => guidanceEvidencePolicy()->validateCandidates([
            [...$candidate, ...$override],
        ], [[
            'url' => $candidate['source_url'],
            'title' => 'Source',
        ]]))->toThrow(ValidationException::class);
    }
});

it('keeps distinct cosmetics and soapmaking recommendation evidence separate', function (): void {
    $cosmetics = guidanceEvidenceCandidate([
        'usage_application' => 'cosmetics',
        'recommended_min_percent' => '1',
        'recommended_max_percent' => '10',
        'percentage_basis' => 'total_formula',
    ]);
    $soapmaking = guidanceEvidenceCandidate([
        'usage_application' => 'soapmaking',
        'recommended_min_percent' => '5',
        'recommended_max_percent' => '30',
        'percentage_basis' => 'soap_oils',
    ]);

    $accepted = guidanceEvidencePolicy()->validateCandidates(
        [$cosmetics, $soapmaking],
        [['url' => $cosmetics['source_url'], 'title' => 'Supplier guidance']],
    );

    expect($accepted)->toHaveCount(2)
        ->and($accepted[0]['usage_application'])->toBe('cosmetics')
        ->and($accepted[1]['usage_application'])->toBe('soapmaking')
        ->and($accepted[1]['percentage_basis'])->toBe('soap_oils');
});

it('does not turn experimental or reported concentrations into recommendations', function (): void {
    $candidate = guidanceEvidenceCandidate([
        'evidence_kind' => 'experimental_observation',
    ]);

    expect(fn (): array => guidanceEvidencePolicy()->validateCandidates([
        $candidate,
    ], [['url' => $candidate['source_url'], 'title' => 'Study']]))
        ->toThrow(ValidationException::class);
});

it('retains product-grade and specialist scope without promoting it to a universal limit', function (): void {
    $productGrade = guidanceEvidenceCandidate([
        'scope' => 'product_grade',
        'source_kind' => 'supplier_technical',
    ]);
    $specialist = guidanceEvidenceCandidate([
        'scope' => 'material',
        'source_kind' => 'specialist_reference',
    ]);

    $accepted = guidanceEvidencePolicy()->validateCandidates(
        [$productGrade, $specialist],
        [['url' => $productGrade['source_url'], 'title' => 'Supplier guidance']],
    );

    expect($accepted[0]['scope'])->toBe('product_grade')
        ->and($accepted[1]['scope'])->toBe('material')
        ->and($accepted[1]['source_kind'])->toBe('specialist_reference');
});

it('converts accepted and legacy evidence to the persisted guidance shape', function (): void {
    $retrievedAt = CarbonImmutable::parse('2026-08-30T12:00:00+00:00');
    $candidate = guidanceEvidenceCandidate();

    $persisted = guidanceEvidencePolicy()->toPersisted([$candidate], $retrievedAt);
    $legacy = guidanceEvidencePolicy()->normalizePersisted([[
        'source_name' => 'Legacy source',
        'source_url' => 'https://legacy.example/apricot-oil',
        'summary' => 'A legacy editorial observation.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-01-01T00:00:00+00:00',
    ]]);

    expect(array_keys($persisted[0]))->toBe([
        'source_name', 'source_url', 'summary', 'source_tier', 'retrieved_at',
        'claim_type', 'source_kind', 'scope', 'evidence_kind', 'usage_application',
        'recommended_min_percent', 'recommended_max_percent', 'percentage_basis',
        'identifiers',
    ])->and($persisted[0]['retrieved_at'])->toBe($retrievedAt->toIso8601String())
        ->and($legacy[0])->toMatchArray([
            'claim_type' => 'origin',
            'source_kind' => 'legacy_editorial',
            'scope' => 'material',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ]);
});

it('quarantines malformed rows and deterministically normalizes legacy rows without timestamps', function (): void {
    $policy = guidanceEvidencePolicy();
    $legacy = [[
        'source_name' => 'Legacy source',
        'source_url' => 'https://legacy.example/apricot-oil',
        'summary' => 'A legacy editorial observation.',
        'source_tier' => 'editorial',
    ]];
    $malformed = [
        [
            'source_name' => '',
            'source_url' => 'https://malformed.example/first',
            'summary' => 'Missing source name.',
            'source_tier' => 'editorial',
        ],
        [
            'source_name' => 'Partial source',
            'source_url' => 'https://malformed.example/second',
            'summary' => 'Partially classified evidence.',
            'source_tier' => 'editorial',
            'claim_type' => 'usage',
        ],
    ];

    $normalized = $policy->normalizePersisted([...$legacy, ...$malformed]);
    $normalizedAgain = $policy->normalizePersisted([...$legacy, ...$malformed]);

    expect($normalized)->toHaveCount(1)
        ->and($normalized)->toBe($normalizedAgain)
        ->and($normalized[0])->toMatchArray([
            'source_name' => 'Legacy source',
            'source_url' => 'https://legacy.example/apricot-oil',
            'summary' => 'A legacy editorial observation.',
            'source_tier' => 'editorial',
            'retrieved_at' => '1970-01-01T00:00:00+00:00',
            'claim_type' => 'origin',
            'source_kind' => 'legacy_editorial',
            'scope' => 'material',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ])
        ->and($policy->reconcilePersisted($malformed, []))->toBe([]);
});

it('returns only logical candidate rows absent from prior evidence', function (): void {
    $policy = guidanceEvidencePolicy();
    $prior = [[
        'source_name' => 'Current source',
        'source_url' => 'https://example.test/shared',
        'summary' => 'The shared evidence.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-01T00:00:00+00:00',
    ]];
    $staleDuplicate = [[
        'source_name' => 'Stale inherited source',
        'source_url' => 'HTTPS://EXAMPLE.TEST/shared/',
        'summary' => '  THE shared evidence. ',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-02T00:00:00+00:00',
    ]];
    $distinct = [[
        'source_name' => 'New source',
        'source_url' => 'https://example.test/distinct',
        'summary' => 'A distinct evidence row.',
        'source_tier' => 'editorial',
        'retrieved_at' => '2026-08-03T00:00:00+00:00',
    ]];

    expect($policy->logicalDifference($prior, [...$staleDuplicate, ...$distinct, ...$distinct]))
        ->toBe($policy->normalizePersisted($distinct));
});

it('matches consulted URLs canonically while removing fragments and a sole trailing slash', function (): void {
    $candidate = guidanceEvidenceCandidate([
        'source_url' => 'HTTPS://Supplier.Example/technical/apricot-oil.pdf',
    ]);

    $accepted = guidanceEvidencePolicy()->validateCandidates([$candidate], [[
        'url' => 'https://supplier.example/technical/apricot-oil.pdf/#page=2',
        'title' => 'Source',
    ]]);

    expect($accepted)->toHaveCount(1);
});

it('reconciles prior evidence in place while replacing logical duplicates with fresh metadata', function (): void {
    $policy = guidanceEvidencePolicy();
    $priorRetrievedAt = CarbonImmutable::parse('2026-08-01T00:00:00+00:00');
    $freshRetrievedAt = CarbonImmutable::parse('2026-09-01T00:00:00+00:00');
    $prior = $policy->toPersisted([
        guidanceEvidenceCandidate([
            'source_name' => 'Prior first source',
            'source_url' => 'https://first.example/apricot-oil',
            'summary' => 'A distinct first source observation.',
            'claim_type' => 'formulation_role',
            'source_kind' => 'scientific',
            'scope' => 'material',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ]),
        guidanceEvidenceCandidate([
            'source_name' => 'Stale source name',
            'summary' => 'The exact product grade is recommended at 1–10% in cosmetic formulations.',
        ]),
        guidanceEvidenceCandidate([
            'source_name' => 'Prior last source',
            'source_url' => 'https://last.example/apricot-oil',
            'summary' => 'A distinct last source observation.',
            'claim_type' => 'formulation_role',
            'source_kind' => 'scientific',
            'scope' => 'material',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ]),
    ], $priorRetrievedAt);
    $fresh = $policy->toPersisted([
        guidanceEvidenceCandidate([
            'source_name' => 'Fresh source name',
            'source_url' => 'HTTPS://Supplier.Example/technical/apricot-oil.pdf/#page=2',
            'summary' => '  THE exact product grade is recommended at 1–10% in cosmetic formulations.  ',
        ]),
        guidanceEvidenceCandidate([
            'source_name' => 'Fresh cosmetics conflict',
            'recommended_min_percent' => '11',
            'recommended_max_percent' => '20',
        ]),
        guidanceEvidenceCandidate([
            'source_name' => 'Fresh soapmaking application',
            'usage_application' => 'soapmaking',
            'recommended_min_percent' => '5',
            'recommended_max_percent' => '30',
            'percentage_basis' => 'soap_oils',
        ]),
        guidanceEvidenceCandidate([
            'source_name' => 'Fresh distinct URL',
            'source_url' => 'https://another.example/apricot-oil',
        ]),
    ], $freshRetrievedAt);

    $merged = $policy->reconcilePersisted($prior, $fresh);

    expect($merged)->toHaveCount(6)
        ->and(array_column($merged, 'source_name'))->toBe([
            'Prior first source',
            'Fresh source name',
            'Prior last source',
            'Fresh cosmetics conflict',
            'Fresh soapmaking application',
            'Fresh distinct URL',
        ])
        ->and($merged[1]['source_url'])->toBe('HTTPS://Supplier.Example/technical/apricot-oil.pdf/#page=2')
        ->and($merged[1]['retrieved_at'])->toBe($freshRetrievedAt->toIso8601String())
        ->and($merged[3])->toMatchArray([
            'recommended_min_percent' => '11',
            'recommended_max_percent' => '20',
            'usage_application' => 'cosmetics',
            'percentage_basis' => 'total_formula',
        ])
        ->and($merged[4])->toMatchArray([
            'recommended_min_percent' => '5',
            'recommended_max_percent' => '30',
            'usage_application' => 'soapmaking',
            'percentage_basis' => 'soap_oils',
        ]);
});

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function guidanceEvidenceCandidate(array $overrides = []): array
{
    return [
        'field' => 'proposal.info_markdown',
        'source_name' => 'Example supplier technical data sheet',
        'source_url' => 'https://supplier.example/technical/apricot-oil.pdf',
        'summary' => 'The exact product grade is recommended at 1–10% in cosmetic formulations.',
        'claim_type' => 'usage',
        'source_kind' => 'supplier_technical',
        'scope' => 'product_grade',
        'evidence_kind' => 'formulation_recommendation',
        'usage_application' => 'cosmetics',
        'recommended_min_percent' => '1',
        'recommended_max_percent' => '10',
        'percentage_basis' => 'total_formula',
        'identifiers' => ['cas' => [], 'ec' => []],
        ...$overrides,
    ];
}

function guidanceEvidencePolicy(): IngredientGuidanceEvidencePolicy
{
    return app(IngredientGuidanceEvidencePolicy::class);
}
