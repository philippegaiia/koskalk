<?php

use App\Services\IngredientEnrichment\IngredientGuidanceDraftRenderer;
use Tests\TestCase;

uses(TestCase::class);

it('renders supported claims under deterministic guidance headings', function (): void {
    $draft = guidanceDraft([
        'overview' => [guidanceClaim([
            'text' => 'A fixed oil pressed from apricot kernels.',
            'claim_type' => 'origin',
            'support_type' => 'fact',
            'fact_paths' => ['current.canonical.display_name'],
        ])],
        'formulation_use' => [guidanceClaim([
            'text' => 'A supplier describes this product grade as suitable for the oil phase.',
            'claim_type' => 'formulation_role',
            'support_type' => 'evidence',
            'evidence_indexes' => [0],
        ])],
        'soapmaking' => [],
    ]);

    $result = guidanceRenderer()->render($draft, guidanceContext());

    expect($result['info_markdown'])
        ->toBe("## Overview\n\nA fixed oil pressed from apricot kernels.\n\n## Formulation use\n\nA supplier describes this product grade as suitable for the oil phase.")
        ->not->toContain('## Soapmaking');
});

it('omits claims containing headings, newlines, or multiple sentences', function (): void {
    foreach ([
        '## Overview A claim.',
        "A claim with\nwrapped text.",
        'First sentence. Second sentence.',
    ] as $text) {
        $result = guidanceRenderer()->render(guidanceDraft([
            'overview' => [guidanceClaim([
                'text' => $text,
                'support_type' => 'fact',
                'fact_paths' => ['current.canonical.display_name'],
            ])],
        ]), guidanceContext());

        expect($result['info_markdown'])->not->toContain(trim($text))
            ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
    }
});

it('omits claims whose evidence indexes do not exist or do not match the claim type', function (): void {
    $missingIndex = guidanceClaim([
        'claim_type' => 'formulation_role',
        'support_type' => 'evidence',
        'evidence_indexes' => [2],
    ]);
    $mismatched = guidanceClaim([
        'claim_type' => 'solubility',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
    ]);

    $missingResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$missingIndex],
    ]), guidanceContext());
    $mismatchedResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$mismatched],
    ]), guidanceContext());

    expect($missingResult['info_markdown'])->not->toContain('A material-specific formulation observation.')
        ->and($missingResult['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.')
        ->and($mismatchedResult['info_markdown'])->not->toContain('A material-specific formulation observation.')
        ->and($mismatchedResult['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits fact-supported claims that cite a path outside the trusted catalogue', function (): void {
    $valid = guidanceClaim([
        'claim_type' => 'physical_form',
        'support_type' => 'fact',
        'fact_paths' => ['proposal.display_name'],
    ]);
    $invalid = guidanceClaim([
        'claim_type' => 'physical_form',
        'support_type' => 'fact',
        'fact_paths' => ['untrusted.notes'],
    ]);

    $result = guidanceRenderer()->render(guidanceDraft(['overview' => [$valid, $invalid]]), guidanceContext());

    expect($result['info_markdown'])->toContain('A material-specific formulation observation.')
        ->and($result['info_markdown'])->not->toContain('untrusted')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits formulation-use claims without evidence and usage claims without explicit evidence', function (): void {
    $factFormulationClaim = guidanceClaim([
        'claim_type' => 'solubility',
        'support_type' => 'fact',
        'fact_paths' => ['current.canonical.display_name'],
    ]);
    $invalidUsage = guidanceClaim([
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [],
        'usage_application' => 'cosmetics',
    ]);

    $factResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$factFormulationClaim],
    ]), guidanceContext());
    $usageResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$invalidUsage],
    ]), guidanceContext());

    expect($factResult['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.')
        ->and($usageResult['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('keeps cosmetics and soapmaking usage claims in their respective sections', function (): void {
    $cosmetics = guidanceClaim([
        'text' => 'A supplier recommends this product grade at 1–10% of the total formula in cosmetics.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);
    $soapmaking = guidanceClaim([
        'text' => 'A specialist recommends 5–30% of the soap-oil blend for this material.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [2],
        'usage_application' => 'soapmaking',
    ]);

    expect(guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$cosmetics],
        'soapmaking' => [$soapmaking],
    ]), guidanceContext())['info_markdown'])
        ->toContain('## Soapmaking')
        ->and(guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [$soapmaking],
        ]), guidanceContext())['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits usage prose whose percentage differs from the cited recommendation', function (): void {
    $claim = guidanceClaim([
        'text' => 'A supplier recommends this product grade at 2–20% of the total formula.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->not->toContain('2–20%')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits usage prose that drops the evidence scope or percentage basis', function (): void {
    $claim = guidanceClaim([
        'text' => 'A supplier recommends this material at 1–10%.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->not->toContain('1–10%')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits usage prose that drops the cited application', function (): void {
    $claim = guidanceClaim([
        'text' => 'A supplier recommends this product grade at 1–10% of the total formula.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->not->toContain('1–10%')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits usage prose that attributes the recommendation to the wrong source kind', function (): void {
    $claim = guidanceClaim([
        'text' => 'A manufacturer recommends this product grade at 1–10% of the total formula for cosmetics; the supplier does not.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->not->toContain('the supplier does not')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits wrong-direction one-sided usage recommendation bounds and keeps the correct direction', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'][1]['recommended_max_percent'] = null;
    $minimumClaim = guidanceClaim([
        'text' => 'A supplier recommends this product grade at up to 1% of the total formula for cosmetics.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $omitted = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$minimumClaim],
    ]), $context);
    expect($omitted['info_markdown'])->not->toContain('up to 1%')
        ->and($omitted['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');

    $validMinimumClaim = [
        ...$minimumClaim,
        'text' => 'A supplier recommends at least 1% of this product grade in the total formula for cosmetics.',
    ];
    expect(guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$validMinimumClaim],
    ]), $context)['info_markdown'])->toContain('at least 1%');

    $context['guidance_evidence'][1]['recommended_min_percent'] = null;
    $context['guidance_evidence'][1]['recommended_max_percent'] = '10';
    $maximumClaim = guidanceClaim([
        'text' => 'A supplier recommends this product grade at at least 10% of the total formula for cosmetics.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $omittedMaximum = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$maximumClaim],
    ]), $context);
    expect($omittedMaximum['info_markdown'])->not->toContain('at least 10%')
        ->and($omittedMaximum['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');

    $validMaximumClaim = [
        ...$maximumClaim,
        'text' => 'A supplier recommends up to 10% of this product grade in the total formula for cosmetics.',
    ];
    expect(guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$validMaximumClaim],
    ]), $context)['info_markdown'])->toContain('up to 10%');
});

it('omits a usage sentence that silently combines multiple recommendations', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'][] = [
        ...$context['guidance_evidence'][1],
        'recommended_min_percent' => '5',
        'recommended_max_percent' => '15',
    ];
    $claim = guidanceClaim([
        'text' => 'Suppliers recommend this product grade at 1–15% of the total formula.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1, 3],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), $context);

    expect($result['info_markdown'])->not->toContain('1–15%')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('accepts trusted soap chemistry facts and omits an empty soapmaking section', function (): void {
    $draft = guidanceDraft([
        'overview' => [guidanceClaim()],
        'soapmaking' => [guidanceClaim([
            'text' => 'Its trusted fatty-acid profile supports a conditioning, relatively soft bar character.',
            'claim_type' => 'soapmaking',
            'support_type' => 'fact',
            'fact_paths' => ['editorial_context.trusted_soap_chemistry'],
        ])],
    ]);

    $withSoap = guidanceRenderer()->render($draft, guidanceContext());
    $withoutSoap = guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim()],
    ]), guidanceContext());

    expect($withSoap['info_markdown'])->toContain('## Soapmaking')
        ->and($withoutSoap['info_markdown'])->not->toContain('## Soapmaking');
});

it('accepts concise guidance below the former minimum and rejects guidance above the maximum', function (): void {
    $short = guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim(['text' => 'A pressed kernel oil.'])],
    ]), guidanceContext());

    $long = guidanceDraft([
        'overview' => collect(range(1, 40))
            ->map(fn (): array => guidanceClaim(['text' => 'A material-specific formulation observation.']))
            ->all(),
    ]);

    expect($short['info_markdown'])->toContain('A pressed kernel oil.')
        ->and(fn (): array => guidanceRenderer()->render($long, guidanceContext()))
        ->toThrow(RuntimeException::class);
});

it('omits a solubility claim supported only by fatty-acid evidence', function (): void {
    $claim = guidanceClaim([
        'claim_type' => 'solubility',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->not->toContain('A material-specific formulation observation.')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('rejects generic water claims and universal emulsifier advice from bounded experiments', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'] = [
        [
            'claim_type' => 'formulation_role',
            'source_kind' => 'scientific',
            'scope' => 'material',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ],
        [
            'claim_type' => 'dispersion',
            'source_kind' => 'scientific',
            'scope' => 'product_grade',
            'evidence_kind' => 'experimental_observation',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ],
    ];

    expect(guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [guidanceClaim([
            'text' => 'It is not soluble in water.',
            'claim_type' => 'formulation_role',
            'evidence_indexes' => [0],
        ])],
    ]), $context)['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.')
        ->and(guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [guidanceClaim([
                'text' => 'It requires a universal emulsifier.',
                'claim_type' => 'dispersion',
                'evidence_indexes' => [1],
            ])],
        ]), $context)['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.')
        ->and(guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [guidanceClaim([
                'text' => 'A Pickering-emulsion experiment with this product grade observed dispersion under the tested conditions.',
                'claim_type' => 'dispersion',
                'evidence_indexes' => [1],
            ])],
        ]), $context)['info_markdown'])
        ->toContain('under the tested conditions');
});

it('does not veto evidence-backed claims when the matching research question remains unresolved', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'] = [[
        'claim_type' => 'solubility',
        'source_kind' => 'scientific',
        'scope' => 'material',
        'evidence_kind' => 'fact',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ]];
    $context['guidance_unresolved_questions'] = ['Confirm the material water solubility before making an aqueous formulation decision.'];

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [guidanceClaim([
            'text' => 'The material is soluble in water.',
            'claim_type' => 'solubility',
            'evidence_indexes' => [0],
        ])],
    ]), $context);

    expect($result['info_markdown'])->toContain('soluble in water');
});

it('keeps fact-only claims grounded in trusted catalogue paths even when a research question overlaps', function (): void {
    $context = guidanceContext();
    $context['guidance_unresolved_questions'] = ['Confirm the material water solubility before making an aqueous formulation decision.'];

    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim([
            'text' => 'A pressed kernel oil from a single plant source.',
            'claim_type' => 'origin',
            'support_type' => 'fact',
            'fact_paths' => ['proposal.display_name'],
        ])],
    ]), $context);

    expect($result['info_markdown'])->toContain('pressed kernel oil');
});

it('does not block supported soapmaking guidance for unresolved soap declaration names', function (): void {
    $context = guidanceContext();
    $context['unresolved_questions'] = [
        'An official sodium soap INCI name could not be verified for this material.',
        'An official potassium soap INCI name could not be verified for this material.',
    ];
    $context['guidance_evidence'][] = [
        'claim_type' => 'soapmaking',
        'source_kind' => 'supplier_technical',
        'scope' => 'product_grade',
        'evidence_kind' => 'formulation_recommendation',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ];

    $result = guidanceRenderer()->render(guidanceDraft([
        'soapmaking' => [guidanceClaim([
            'text' => 'For this supplied grade, the supplier describes a softer-feeling bar.',
            'claim_type' => 'soapmaking',
            'support_type' => 'evidence',
            'evidence_indexes' => [3],
        ])],
    ]), $context);

    expect($result['info_markdown'])->toContain('## Soapmaking');
});

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function guidanceClaim(array $overrides = []): array
{
    $claim = [
        'text' => 'A material-specific formulation observation.',
        'claim_type' => 'formulation_role',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
        'fact_paths' => [],
        'usage_application' => 'not_applicable',
        ...$overrides,
    ];

    if ($claim['support_type'] === 'fact' && ! array_key_exists('evidence_indexes', $overrides)) {
        $claim['evidence_indexes'] = [];
    }

    return $claim;
}

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function guidanceDraft(array $overrides = []): array
{
    return [
        'overview' => [],
        'formulation_use' => [],
        'soapmaking' => [],
        'warnings' => [],
        'unresolved_questions' => [],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function guidanceContext(): array
{
    return [
        'current' => [
            'canonical' => [
                'display_name' => 'Apricot oil',
            ],
        ],
        'proposal' => [
            'display_name' => 'Apricot oil',
        ],
        'editorial_context' => [
            'trusted_soap_chemistry' => [
                'fatty_acid_profile' => ['oleic' => '65%'],
            ],
        ],
        'guidance_evidence' => [
            [
                'claim_type' => 'formulation_role',
                'source_kind' => 'scientific',
                'scope' => 'material',
                'evidence_kind' => 'fact',
                'usage_application' => 'not_applicable',
                'recommended_min_percent' => null,
                'recommended_max_percent' => null,
                'percentage_basis' => 'not_applicable',
            ],
            [
                'claim_type' => 'usage',
                'source_kind' => 'supplier_technical',
                'scope' => 'product_grade',
                'evidence_kind' => 'formulation_recommendation',
                'usage_application' => 'cosmetics',
                'recommended_min_percent' => '1',
                'recommended_max_percent' => '10',
                'percentage_basis' => 'total_formula',
            ],
            [
                'claim_type' => 'usage',
                'source_kind' => 'specialist_reference',
                'scope' => 'material',
                'evidence_kind' => 'formulation_recommendation',
                'usage_application' => 'soapmaking',
                'recommended_min_percent' => '5',
                'recommended_max_percent' => '30',
                'percentage_basis' => 'soap_oils',
            ],
        ],
    ];
}

function guidanceRenderer(): IngredientGuidanceDraftRenderer
{
    return app(IngredientGuidanceDraftRenderer::class);
}
