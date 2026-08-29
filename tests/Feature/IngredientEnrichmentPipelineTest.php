<?php

use App\Contracts\IngredientEditorialClient;
use App\Data\IngredientEditorialResponse;
use App\Data\IngredientSourceStageResult;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Models\IngredientEnrichmentBatchItem;
use App\Services\IngredientEnrichment\IngredientEnrichmentPipeline;
use App\Services\IngredientEnrichment\IngredientEnrichmentStageStore;
use App\Services\IngredientEnrichment\Sources\CosingCheckerClient;
use App\Services\IngredientEnrichment\Sources\EurLexGlossaryClient;
use App\Services\IngredientEnrichment\Sources\OpenFdaSubstanceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', false);
});

it('persists normalized completed stages and invalidates a stage with all of its downstream stages', function (): void {
    $item = IngredientEnrichmentBatchItem::factory()->create();
    $store = app(IngredientEnrichmentStageStore::class);

    $store->complete($item->id, new IngredientSourceStageResult(
        stage: IngredientEnrichmentResearchStage::EuStructured,
        status: 'completed',
        data: ['candidates' => [['inci_name' => 'ARGANIA SPINOSA KERNEL OIL']]],
        sourceCalls: 1,
    ));
    $store->complete($item->id, new IngredientSourceStageResult(
        stage: IngredientEnrichmentResearchStage::EuOfficial,
        status: 'completed',
        data: ['matched' => true, 'common_ingredient_name' => 'ARGANIA SPINOSA KERNEL OIL'],
        sourceCalls: 1,
    ));

    expect(array_keys($item->fresh()->research_stages))->toBe([
        'eu_structured',
        'eu_official',
    ])->and(data_get($item->fresh()->research_stages, 'eu_structured.source_calls'))->toBe(1);

    $store->invalidateFrom($item->id, IngredientEnrichmentResearchStage::EuStructured);

    expect($item->fresh()->research_stages)->toBe([]);
});

it('runs deterministic stages before one editorial pass and resumes their persisted results', function (): void {
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'catalog_key' => 'argan_oil',
        'snapshot' => [
            'catalog_key' => 'argan_oil',
            'source_fingerprint' => str_repeat('a', 64),
            'current' => [
                'canonical' => [
                    'display_name' => 'Argan oil',
                    'inci_name' => null,
                    'category' => 'lipids',
                    'subcategory' => 'vegetable_oils',
                ],
                'identifiers' => [],
            ],
            'vocabulary' => ['locales' => ['fr']],
        ],
        'source_fingerprint' => str_repeat('a', 64),
    ]);

    app()->instance(CosingCheckerClient::class, new class extends CosingCheckerClient
    {
        public function __construct() {}

        public function lookup(array $record): IngredientSourceStageResult
        {
            return new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuStructured, 'completed', [
                'candidates' => [[
                    'cosing_ref' => '54495',
                    'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
                    'cas' => [],
                    'ec' => [],
                    'functions' => [],
                ]],
                'soap_salts' => [
                    'naoh' => ['inci_name' => 'SODIUM ARGANATE'],
                    'koh' => ['inci_name' => 'POTASSIUM ARGANATE'],
                ],
            ], sourceCalls: 1);
        }
    });
    app()->instance(EurLexGlossaryClient::class, new class extends EurLexGlossaryClient
    {
        public function __construct() {}

        public function verify(array $facts): IngredientSourceStageResult
        {
            return new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuOfficial, 'completed', [
                'matched' => true,
                'common_ingredient_name' => 'ARGANIA SPINOSA KERNEL OIL',
                'soap_inci_naoh_name' => 'SODIUM ARGANATE',
                'soap_inci_koh_name' => 'POTASSIUM ARGANATE',
            ], sourceCalls: 1);
        }
    });
    app()->instance(OpenFdaSubstanceClient::class, new class extends OpenFdaSubstanceClient
    {
        public function __construct() {}

        public function lookup(array $record): IngredientSourceStageResult
        {
            return new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsIdentity, 'completed', [
                'candidates' => [[
                    'unii' => '4V59G5UW9X',
                    'common_name' => 'ARGAN OIL',
                    'inci_names' => ['ARGANIA SPINOSA KERNEL OIL'],
                    'cas' => [],
                ]],
            ], sourceCalls: 1);
        }
    });
    app()->instance(IngredientEditorialClient::class, new class implements IngredientEditorialClient
    {
        public function edit(array $facts): IngredientEditorialResponse
        {
            return new IngredientEditorialResponse(
                editorial: [
                    'display_name' => 'Argan oil',
                    'saponification_name' => 'Argan oil',
                    'info_markdown' => "## Overview\nArgan oil is a plant-derived lipid.\n\n## Formulation use\nIt is used for emollience.\n\n## Soapmaking\nIt can be included as an oil component.",
                    'soapmaking_relevant' => true,
                    'translations' => [[
                        'locale' => 'fr',
                        'display_name' => 'Huile d’argan',
                        'saponification_name' => 'Huile d’argan',
                        'info_markdown' => "## Overview\nL’huile d’argan est un lipide.\n\n## Formulation use\nElle apporte de l’émollience.\n\n## Soapmaking\nElle peut être incluse comme huile.",
                    ]],
                    'warnings' => [],
                    'unresolved_questions' => [],
                ],
                responseId: 'resp_editorial_123',
                requestId: 'req_editorial_456',
                model: 'gpt-test',
                inputTokens: 321,
                outputTokens: 123,
            );
        }
    });

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect(array_keys($item->fresh()->research_stages))->toBe([
        'identity_preparation',
        'us_identity',
        'eu_structured',
        'eu_official',
        'us_declaration',
        'conflict_evaluation',
        'ai_guidance_research',
        'ai_editorial',
        'ai_guidance_authoring',
        'ai_guidance_localization',
        'validation',
    ])->and($response->structuredSourceCalls)->toBe(3)
        ->and($response->inputTokens)->toBe(321)
        ->and(data_get($response->result, 'proposal.inci_name'))->toBe('ARGANIA SPINOSA KERNEL OIL')
        ->and(data_get($response->result, 'proposal.soap_inci_naoh_name'))->toBe('SODIUM ARGANATE')
        ->and(data_get($response->result, 'proposal.soap_inci_koh_name'))->toBe('POTASSIUM ARGANATE')
        ->and(data_get($response->result, 'proposal.translations.0.info_markdown'))->toStartWith("## Vue d’ensemble\n")
        ->and(data_get($response->result, 'proposal.translations.0.info_markdown'))->toContain("## Utilisation en formulation\n", "## Savonnerie\n")
        ->and(collect($response->result['value_provenance'])->pluck('kind', 'field')->get('proposal.translations.0'))
        ->toBe('ai_proposed');

    app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect($item->fresh()->research_stages['eu_structured']['source_calls'])->toBe(1);
});

it('identifies the earliest failed or unresolved stage as the safe retry boundary', function (): void {
    $failed = IngredientEnrichmentBatchItem::factory()->create([
        'research_stages' => [
            'identity_preparation' => ['status' => 'completed'],
            'eu_structured' => ['status' => 'completed'],
            'eu_official' => ['status' => 'completed'],
            'us_identity' => ['status' => 'failed'],
        ],
    ]);
    $unresolved = IngredientEnrichmentBatchItem::factory()->create([
        'research_stages' => [
            'identity_preparation' => ['status' => 'completed'],
            'eu_structured' => ['status' => 'completed'],
            'eu_official' => ['status' => 'completed'],
            'us_identity' => ['status' => 'completed'],
            'us_declaration' => [
                'status' => 'completed',
                'unresolved_questions' => ['A US declaration needs an exact FDA source.'],
            ],
        ],
    ]);

    expect($failed->retryableFromStage())->toBe(IngredientEnrichmentResearchStage::UsIdentity)
        ->and($unresolved->retryableFromStage())->toBe(IngredientEnrichmentResearchStage::UsDeclaration);
});

it('surfaces identity conflicts as warnings and forces low confidence', function (): void {
    $item = IngredientEnrichmentBatchItem::factory()->create([
        'catalog_key' => 'argan_unsaponifiables',
        'snapshot' => [
            'catalog_key' => 'argan_unsaponifiables',
            'source_fingerprint' => str_repeat('b', 64),
            'current' => [
                'canonical' => [
                    'display_name' => 'Argan unsaponifiables',
                    'inci_name' => 'ARGANIA SPINOSA KERNEL OIL UNSAPONIFIABLES',
                    'category' => 'lipids',
                    'subcategory' => 'vegetable_oils',
                ],
                'identifiers' => [],
            ],
            'vocabulary' => ['locales' => []],
        ],
        'source_fingerprint' => str_repeat('b', 64),
    ]);
    $store = app(IngredientEnrichmentStageStore::class);
    $record = [
        'display_name' => 'Argan unsaponifiables',
        'inci_name' => 'ARGANIA SPINOSA KERNEL OIL UNSAPONIFIABLES',
        'category' => 'lipids',
        'subcategory' => 'vegetable_oils',
        'identifiers' => [],
    ];

    foreach ([
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::IdentityPreparation, 'completed', ['record' => $record]),
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsIdentity, 'completed', ['candidates' => []]),
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuStructured, 'completed', ['candidates' => []]),
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::EuOfficial, 'completed', ['matched' => false]),
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::UsDeclaration, 'completed', []),
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::ConflictEvaluation, 'completed', [
            'facts' => [
                'proposal' => [
                    'display_name' => 'Argan unsaponifiables',
                    'inci_name' => null,
                    'soap_inci_naoh_name' => null,
                    'soap_inci_koh_name' => null,
                    'category' => 'lipids',
                    'subcategory' => 'vegetable_oils',
                    'aliases' => [],
                    'identifiers' => [],
                    'cosing_functions' => [],
                    'market_labels' => [],
                ],
                'field_confidence' => [],
                'evidence' => [],
                'regulatory_findings' => [],
                'warnings' => [],
                'unresolved_questions' => [],
                'conflicts' => ['Material difference: unsaponifiables.'],
            ],
        ]),
        new IngredientSourceStageResult(IngredientEnrichmentResearchStage::AiEditorial, 'completed', [
            'editorial' => [
                'display_name' => 'Argan unsaponifiables',
                'saponification_name' => null,
                'info_markdown' => null,
                'soapmaking_relevant' => false,
                'translations' => [],
                'warnings' => [],
                'unresolved_questions' => [],
            ],
        ]),
    ] as $stage) {
        $store->complete($item->id, $stage);
    }

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect($response->result['confidence'])->toBe('low')
        ->and($response->result['warnings'])->toContain('Material difference: unsaponifiables.');
});
