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
        ]]))->toThrow(RuntimeException::class);
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
        ...$overrides,
    ];
}

function guidanceEvidencePolicy(): IngredientGuidanceEvidencePolicy
{
    return app(IngredientGuidanceEvidencePolicy::class);
}
