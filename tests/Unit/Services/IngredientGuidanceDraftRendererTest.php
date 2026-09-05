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
            'text' => 'Add it to the oil phase of anhydrous products or emulsions.',
            'claim_type' => 'formulation_role',
            'support_type' => 'evidence',
            'evidence_indexes' => [0],
        ])],
        'soapmaking' => [],
    ]);

    $result = guidanceRenderer()->render($draft, guidanceContext());

    expect($result['info_markdown'])
        ->toBe("## Overview\n\nA fixed oil pressed from apricot kernels.\n\n## Formulation use\n\nAdd it to the oil phase of anhydrous products or emulsions.")
        ->not->toContain('## Soapmaking');
});

it('omits evidence meta prose while retaining the rest of the draft', function (string $text): void {
    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [
            guidanceClaim([
                'text' => $text,
                'claim_type' => 'formulation_role',
            ]),
            guidanceClaim([
                'text' => 'A pressed kernel oil from a single plant source.',
                'claim_type' => 'origin',
                'support_type' => 'fact',
                'fact_paths' => ['proposal.display_name'],
            ]),
        ],
    ]), guidanceContext());

    expect($result['info_markdown'])
        ->not->toContain($text)
        ->toContain('A pressed kernel oil from a single plant source.')
        ->and($result['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
})->with('guidance evidence meta prose');

it('keeps explicit grades, bounded use levels, and bounded experiment language', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'][1]['recommended_max_percent'] = '8';
    $context['guidance_evidence'][] = [
        'claim_type' => 'dispersion',
        'source_kind' => 'scientific',
        'scope' => 'product_grade',
        'evidence_kind' => 'experimental_observation',
        'usage_application' => 'not_applicable',
        'recommended_min_percent' => null,
        'recommended_max_percent' => null,
        'percentage_basis' => 'not_applicable',
    ];

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [
            guidanceClaim([
                'text' => 'A refined grade can be added to a heated oil phase.',
                'claim_type' => 'formulation_role',
            ]),
            guidanceClaim([
                'text' => 'An unrefined grade can be incorporated after gentle warming.',
                'claim_type' => 'formulation_role',
            ]),
            guidanceClaim([
                'text' => 'This refined grade can be added to a heated oil phase.',
                'claim_type' => 'formulation_role',
            ]),
            guidanceClaim([
                'text' => 'Typical use level: 1–8% of the total formula.',
                'claim_type' => 'usage',
                'evidence_indexes' => [1],
                'usage_application' => 'cosmetics',
            ]),
            guidanceClaim([
                'text' => 'In a Pickering-emulsion experiment, dispersion was observed under the tested conditions.',
                'claim_type' => 'dispersion',
                'evidence_indexes' => [3],
            ]),
        ],
    ]), $context);

    expect($result['info_markdown'])
        ->toContain('A refined grade can be added to a heated oil phase.')
        ->toContain('An unrefined grade can be incorporated after gentle warming.')
        ->toContain('This refined grade can be added to a heated oil phase.')
        ->toContain('Typical use level: 1–8% of the total formula.')
        ->toContain('under the tested conditions')
        ->and($result['warnings'])->toBe([]);
});

it('handles case and punctuation variants without blocking named grade prose', function (): void {
    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [
            guidanceClaim([
                'text' => 'A DOCUMENTED, cold-pressed product-grade profile is reddish.',
            ]),
            guidanceClaim([
                'text' => 'A SUPPLIER-RECOMMENDED range is available for formulation trials.',
            ]),
            guidanceClaim([
                'text' => 'The refined grade remains fluid when warmed.',
            ]),
        ],
    ]), guidanceContext());

    expect($result['info_markdown'])
        ->not->toContain('DOCUMENTED')
        ->not->toContain('SUPPLIER-RECOMMENDED')
        ->toContain('The refined grade remains fluid when warmed.')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits source attribution narration across the bounded evidence verb family', function (string $text): void {
    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [
            guidanceClaim(['text' => $text]),
            guidanceClaim([
                'text' => 'The oil remains fluid in the heated oil phase.',
            ]),
            guidanceClaim([
                'text' => 'The manufacturer-grade oil remains fluid in the heated oil phase.',
            ]),
            guidanceClaim([
                'text' => 'The supplier-derived oil remains fluid in the heated oil phase.',
            ]),
            guidanceClaim([
                'text' => 'The material suggests a stable oil-phase dispersion.',
            ]),
        ],
    ]), guidanceContext());

    expect($result['info_markdown'])
        ->not->toContain($text)
        ->toContain('The oil remains fluid in the heated oil phase.')
        ->toContain('The manufacturer-grade oil remains fluid in the heated oil phase.')
        ->toContain('The supplier-derived oil remains fluid in the heated oil phase.')
        ->toContain('The material suggests a stable oil-phase dispersion.')
        ->and($result['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
})->with('guidance source attribution meta prose');

it('omits named supplier and brand attribution narration while retaining natural formulation facts', function (): void {
    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [
            guidanceClaim(['text' => 'Supplier A recommends this material for emulsion trials.']),
            guidanceClaim(['text' => 'supplier A recommends this material for emulsion trials.']),
            guidanceClaim(['text' => 'BASF recommends this product grade for emulsions.']),
            guidanceClaim(['text' => 'The range was recommended by BASF.']),
            guidanceClaim([
                'text' => 'The BASF-derived material remains fluid in the oil phase.',
                'support_type' => 'fact',
                'fact_paths' => ['proposal.display_name'],
            ]),
            guidanceClaim([
                'text' => 'Vitamin E supports oxidative stability in an oil phase.',
                'support_type' => 'fact',
                'fact_paths' => ['proposal.display_name'],
            ]),
        ],
    ]), guidanceContext());

    expect($result['info_markdown'])
        ->not->toContain('Supplier A recommends')
        ->not->toContain('supplier a recommends')
        ->not->toContain('BASF recommends')
        ->not->toContain('recommended by BASF')
        ->toContain('The BASF-derived material remains fluid in the oil phase.')
        ->toContain('Vitamin E supports oxidative stability in an oil phase.')
        ->and($result['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('fails closed when every guidance claim is omitted', function (): void {
    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim(['text' => 'BASF recommends this product grade for emulsions.'])],
    ]), guidanceContext());

    expect($result['info_markdown'])->toBe('')
        ->and($result['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('omits each evidence adjective when it qualifies a catalogue subject', function (string $text): void {
    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [
            guidanceClaim(['text' => $text]),
            guidanceClaim([
                'text' => 'The oil remains fluid in the heated oil phase.',
            ]),
            guidanceClaim([
                'text' => 'The study reported a fluid oil-phase result.',
            ]),
        ],
    ]), guidanceContext());

    expect($result['info_markdown'])
        ->not->toContain($text)
        ->toContain('The oil remains fluid in the heated oil phase.')
        ->toContain('The study reported a fluid oil-phase result.')
        ->and($result['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
})->with('guidance evidence adjective meta prose');

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

it('omits claims that state the material catalogue classification in prose', function (): void {
    foreach ([
        'This material is classified as a vegetable oil within the lipid category.',
        'It is categorized as an emollient.',
    ] as $text) {
        $result = guidanceRenderer()->render(guidanceDraft([
            'overview' => [guidanceClaim([
                'text' => $text,
                'support_type' => 'fact',
                'fact_paths' => ['current.canonical.display_name'],
            ])],
        ]), guidanceContext());

        expect($result['info_markdown'])->not->toContain('classified as')
            ->and($result['info_markdown'])->not->toContain('categorized as')
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

it('keeps reviewed formulation guidance as an editorial baseline but omits unrelated fact support', function (): void {
    $context = guidanceContext();
    $context['current']['canonical']['info_markdown'] = "## Overview\n\nA reviewed overview.\n\n## Formulation use\n\nAdd it to the oil phase.";
    $reviewedGuidanceClaim = guidanceClaim([
        'text' => 'Add it to the oil phase.',
        'claim_type' => 'formulation_role',
        'support_type' => 'fact',
        'fact_paths' => ['current.canonical.info_markdown'],
    ]);
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

    $reviewedResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$reviewedGuidanceClaim],
    ]), $context);
    $unrelatedFactResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$factFormulationClaim],
    ]), $context);
    $usageResult = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$invalidUsage],
    ]), guidanceContext());

    expect($reviewedResult['info_markdown'])->toContain('Add it to the oil phase.')
        ->and($unrelatedFactResult['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.')
        ->and($usageResult['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('keeps cosmetics and soapmaking usage claims in their respective sections', function (): void {
    $cosmetics = guidanceClaim([
        'text' => 'Typical use level: 1–10% of the total formula.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);
    $soapmaking = guidanceClaim([
        'text' => 'Typical use level: 5–30% of the soap-oil blend.',
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

it('keeps a preserved baseline soapmaking use level rendered as a baseline fact', function (): void {
    $baseline = '## Overview'."\n".'Apricot kernel oil is a fixed oil.'."\n\n"
        .'## Formulation use'."\n".'Typical use level: 1–10% of the total formula.'."\n\n"
        .'## Soapmaking'."\n".'Typical use level: 10–25% of the soap-oil blend.';
    $claim = guidanceClaim([
        'text' => 'Typical use level: 10–25% of the soap-oil blend.',
        'claim_type' => 'usage',
        'support_type' => 'fact',
        'fact_paths' => ['current.canonical.info_markdown'],
        'usage_application' => 'soapmaking',
    ]);
    $context = guidanceContext();
    $context['current']['canonical']['info_markdown'] = $baseline;

    $result = guidanceRenderer()->render(guidanceDraft([
        'soapmaking' => [$claim],
    ]), $context);

    expect($result['info_markdown'])->toContain('## Soapmaking', 'Typical use level: 10–25% of the soap-oil blend.')
        ->and($result['warnings'])->toBe([]);
});

it('preserves a soapmaking baseline use-level sentence when the refresh draft omits it', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'][2]['recommended_min_percent'] = '10';
    $context['guidance_evidence'][2]['recommended_max_percent'] = '25';
    $context['current']['canonical']['info_markdown'] = "## Overview\nApricot kernel oil is a fixed oil.\n\n## Formulation use\nAdd it to the oil phase.\n\n## Soapmaking\nTypical use level: 10–25% of the soap-oil blend.";

    $result = guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim([
            'text' => 'A pressed kernel oil from a single plant source.',
            'claim_type' => 'origin',
            'support_type' => 'fact',
            'fact_paths' => ['proposal.display_name'],
        ])],
        'formulation_use' => [guidanceClaim([
            'text' => 'Add it to the oil phase.',
            'claim_type' => 'formulation_role',
            'support_type' => 'fact',
            'fact_paths' => ['current.canonical.info_markdown'],
        ])],
        'soapmaking' => [],
    ]), $context);

    expect($result['info_markdown'])
        ->toContain('## Soapmaking')
        ->toContain('Typical use level: 10–25% of the soap-oil blend.')
        ->and($result['warnings'])->toBe([]);
});

it('drops supplier and brand attribution from preserved baseline use-level sentences with a warning', function (): void {
    $context = guidanceContext();
    $context['current']['canonical']['info_markdown'] = "## Overview\nApricot kernel oil is a fixed oil.\n\n## Formulation use\nBASF-recommended Typical use level: 1–10% of the total formula.\n\n## Soapmaking\nSupplier A recommends a Typical use level: 5–30% of the soap-oil blend.";

    $result = guidanceRenderer()->render(guidanceDraft(), $context);

    expect($result['info_markdown'])->toBe('')
        ->and($result['warnings'])
        ->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('preserves direct cosmetics and soapmaking use-level baselines during refresh', function (): void {
    $context = guidanceContext();
    $context['current']['canonical']['info_markdown'] = "## Overview\nApricot kernel oil is a fixed oil.\n\n## Formulation use\nTypical use level: 1–10% of the total formula.\n\n## Soapmaking\nTypical use level: 5–30% of the soap-oil blend.";

    $result = guidanceRenderer()->render(guidanceDraft(), $context);

    expect($result['info_markdown'])
        ->toContain('Typical use level: 1–10% of the total formula.')
        ->toContain('Typical use level: 5–30% of the soap-oil blend.')
        ->and($result['warnings'])->toBe([]);
});

it('uses only fresh evidence when deciding whether a historical baseline is contradicted', function (): void {
    $context = guidanceContext();
    $context['current']['canonical']['info_markdown'] = "## Overview\nApricot kernel oil is a fixed oil.\n\n## Formulation use\nTypical use level: 1–10% of the total formula.";
    $historicalConflict = [
        'claim_type' => 'usage',
        'usage_application' => 'cosmetics',
        'recommended_min_percent' => '20',
        'recommended_max_percent' => '30',
        'percentage_basis' => 'total_formula',
    ];
    $context['guidance_evidence'] = [$historicalConflict];
    $context['fresh_guidance_evidence'] = [];

    $historicalResult = guidanceRenderer()->render(guidanceDraft(), $context);

    $context['fresh_guidance_evidence'] = [$historicalConflict];
    $freshResult = guidanceRenderer()->render(guidanceDraft(), $context);

    expect($historicalResult['info_markdown'])
        ->toContain('Typical use level: 1–10% of the total formula.')
        ->and($freshResult['info_markdown'])
        ->not->toContain('Typical use level: 1–10% of the total formula.');
});

it('omits a soapmaking usage fact that is not a verbatim baseline sentence', function (): void {
    $claim = guidanceClaim([
        'text' => 'Typical use level: 10–25% of the soap-oil blend.',
        'claim_type' => 'usage',
        'support_type' => 'fact',
        'fact_paths' => ['current.soap_chemistry'],
        'usage_application' => 'soapmaking',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'soapmaking' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->not->toContain('10–25%')
        ->and($result['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
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

it('omits usage prose that drops the percentage basis', function (): void {
    $claim = guidanceClaim([
        'text' => 'Typical use level: 1–10%.',
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

it('keeps natural usage prose without repeating the application, source kind, or evidence scope', function (): void {
    $claim = guidanceClaim([
        'text' => 'Typical use level: 1–10% of the total formula.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), guidanceContext());

    expect($result['info_markdown'])->toContain('Typical use level: 1–10% of the total formula.')
        ->and($result['warnings'])->not->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('keeps the same natural usage prose for every recommendation-capable source kind', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'][1]['source_kind'] = 'manufacturer_technical';
    $claim = guidanceClaim([
        'text' => 'Typical use level: 1–10% of the total formula.',
        'claim_type' => 'usage',
        'support_type' => 'evidence',
        'evidence_indexes' => [1],
        'usage_application' => 'cosmetics',
    ]);

    $result = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$claim],
    ]), $context);

    expect($result['info_markdown'])->toContain('Typical use level: 1–10% of the total formula.');
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
        'text' => 'Typical use level: at least 1% of the total formula.',
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
        'text' => 'Typical use level: up to 10% of the total formula.',
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

it('accepts concise guidance and rejects guidance above the word or visible-character maximum', function (): void {
    $short = guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim(['text' => 'A pressed kernel oil.'])],
    ]), guidanceContext());

    $long = guidanceDraft([
        'overview' => collect(range(1, 80))
            ->map(fn (): array => guidanceClaim(['text' => 'A material-specific formulation observation.']))
            ->all(),
    ]);

    expect($short['info_markdown'])->toContain('A pressed kernel oil.')
        ->and(fn (): array => guidanceRenderer()->render($long, guidanceContext()))
        ->toThrow(RuntimeException::class);

    config()->set('ingredient-enrichment.guidance.maximum_characters', 40);

    expect(fn (): array => guidanceRenderer()->render(guidanceDraft([
        'overview' => [guidanceClaim(['text' => 'A concise but visibly overlong ingredient description.'])],
    ]), guidanceContext()))->toThrow(RuntimeException::class);
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
                'text' => 'In a Pickering-emulsion experiment, dispersion was observed under the tested conditions.',
                'claim_type' => 'dispersion',
                'evidence_indexes' => [1],
            ])],
        ]), $context)['info_markdown'])
        ->toContain('under the tested conditions');
});

it('omits claims that leak an evidence product code into the prose', function (): void {
    $context = guidanceContext();
    $context['guidance_evidence'][0]['source_name'] = 'Citróleo Group Buriti Oil Technical Data Sheet (PA3019)';
    $leaking = guidanceClaim([
        'text' => 'PA3019 is a reddish, viscous liquid with a characteristic odour.',
        'claim_type' => 'formulation_role',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
    ]);
    $clean = guidanceClaim([
        'text' => 'A reddish, viscous liquid with a characteristic odour.',
        'claim_type' => 'formulation_role',
        'support_type' => 'evidence',
        'evidence_indexes' => [0],
    ]);

    $leaked = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$leaking],
    ]), $context);
    $kept = guidanceRenderer()->render(guidanceDraft([
        'formulation_use' => [$clean],
    ]), $context);

    expect($leaked['info_markdown'])->not->toContain('PA3019')
        ->and($leaked['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.')
        ->and($kept['info_markdown'])->toContain('A reddish, viscous liquid with a characteristic odour.');
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
            'text' => 'This formulation can produce a softer-feeling bar.',
            'claim_type' => 'soapmaking',
            'support_type' => 'evidence',
            'evidence_indexes' => [3],
        ])],
    ]), $context);

    expect($result['info_markdown'])->toContain('## Soapmaking');
});

dataset('guidance evidence meta prose', [
    ['A documented product grade is a reddish, viscous liquid.'],
    ['For this product grade, add it to the oil phase.'],
    ['The specified cold-pressed grade is water-insoluble.'],
    ['A manufacturer recommends this range.'],
    ['A supplier describes this product grade as suitable for emulsions.'],
    ['This particular product grade is a reddish, viscous liquid.'],
    ['This specific grade can be added to the oil phase.'],
]);

dataset('guidance source attribution meta prose', [
    ['A supplier states that the material is suitable for emulsions.'],
    ['The manufacturer notes that the material is fluid in the oil phase.'],
    ['A supplier advises a low starting level.'],
    ['A supplier said that the material is suitable for emulsions.'],
    ['A supplier, stating that the material is suitable, supports this use.'],
    ['THE MANUFACTURER, NOTING THAT THE MATERIAL IS FLUID, RECOMMENDS THIS RANGE.'],
    ['SUPPLIERS SAY the material is suitable for emulsions.'],
    ['The manufacturer suggests this material for emulsions.'],
    ['A supplier indicates that the profile is stable.'],
    ['According to the manufacturer, the material is fluid.'],
    ['The range was recommended by a supplier.'],
    ['The result is described by the manufacturer.'],
    ['The supplier generally recommends addition to the oil phase.'],
    ['The manufacturer’s technical sheet recommends a low starting level.'],
    ['A 1–10% range is recommended for emulsions by the supplier.'],
    ['The supplier strongly recommends this range.'],
    ['The manufacturer’s datasheet recommends this range.'],
    ['The supplier generally also recommends this range.'],
]);

dataset('guidance evidence adjective meta prose', [
    ['A cited material has a reddish, viscous appearance.'],
    ['A documented grade has a reddish, viscous appearance.'],
    ['A specified profile is predominantly oleic.'],
    ['A referenced data point describes viscosity.'],
    ['A supplied material has a reddish hue.'],
    ['A reported grade is pale yellow.'],
    ['A listed profile is mostly oleic.'],
    ['A verified material is clear.'],
]);

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
