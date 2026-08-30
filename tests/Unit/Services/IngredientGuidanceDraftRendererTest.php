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

it('rejects claims containing headings, newlines, or multiple sentences', function (): void {
    foreach ([
        '## Overview A claim.',
        "A claim with\nwrapped text.",
        'First sentence. Second sentence.',
    ] as $text) {
        expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
            'overview' => [guidanceClaim([
                'text' => $text,
                'support_type' => 'fact',
                'fact_paths' => ['current.canonical.display_name'],
            ])],
        ]), guidanceContext()))->toThrow(RuntimeException::class);
    }
});

it('requires evidence indexes to exist and match the claim type', function (): void {
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

    expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$missingIndex],
    ]), guidanceContext()))->toThrow(RuntimeException::class)
        ->and(fn (): array => guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [$mismatched],
        ]), guidanceContext()))->toThrow(RuntimeException::class);
});

it('allows only present deterministic facts for fact-supported claims', function (): void {
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

    expect(guidanceRenderer()->render(guidanceDraft(['overview' => [$valid]]), guidanceContext()))
        ->toHaveKey('info_markdown')
        ->and(fn (): array => guidanceRenderer()->render(guidanceDraft(['overview' => [$invalid]]), guidanceContext()))
        ->toThrow(RuntimeException::class);
});

it('requires evidence for formulation-use claims and explicit evidence for usage percentages', function (): void {
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

    expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$factFormulationClaim],
    ]), guidanceContext()))->toThrow(RuntimeException::class)
        ->and(fn (): array => guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [$invalidUsage],
        ]), guidanceContext()))->toThrow(RuntimeException::class);
});

it('keeps cosmetics and soapmaking usage claims in their respective sections', function (): void {
    $cosmetics = guidanceClaim([
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
        ->and(fn (): array => guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [$soapmaking],
        ]), guidanceContext()))->toThrow(RuntimeException::class);
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

    $longText = trim(str_repeat('Material-specific evidence supports careful formulation. ', 35));
    $long = guidanceDraft([
        'overview' => [guidanceClaim([
            'text' => $longText,
            'support_type' => 'fact',
            'fact_paths' => ['current.canonical.display_name'],
        ])],
    ]);

    expect($short['info_markdown'])->toContain('A pressed kernel oil.')
        ->and(fn (): array => guidanceRenderer()->render($long, guidanceContext()))
        ->toThrow(RuntimeException::class);
});

it('rejects a solubility claim supported only by fatty-acid evidence', function (): void {
    $claim = guidanceClaim([
        'claim_type' => 'solubility',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
    ]);

    expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext()))->toThrow(RuntimeException::class);
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

    expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [guidanceClaim([
            'text' => 'It is not soluble in water.',
            'claim_type' => 'formulation_role',
            'evidence_indexes' => [0],
        ])],
    ]), $context))->toThrow(RuntimeException::class)
        ->and(fn (): array => guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [guidanceClaim([
                'text' => 'It requires a universal emulsifier.',
                'claim_type' => 'dispersion',
                'evidence_indexes' => [1],
            ])],
        ]), $context))->toThrow(RuntimeException::class)
        ->and(guidanceRenderer()->render(guidanceDraft([
            'formulation_use' => [guidanceClaim([
                'text' => 'A Pickering-emulsion experiment with this product grade observed dispersion under the tested conditions.',
                'claim_type' => 'dispersion',
                'evidence_indexes' => [1],
            ])],
        ]), $context)['info_markdown'])
        ->toContain('under the tested conditions');
});

it('rejects claims when the matching research question remains unresolved', function (): void {
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
    $context['unresolved_questions'] = ['Confirm the material water solubility before making an aqueous formulation decision.'];

    expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [guidanceClaim([
            'text' => 'The material is soluble in water.',
            'claim_type' => 'solubility',
            'evidence_indexes' => [0],
        ])],
    ]), $context))->toThrow(RuntimeException::class);
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
