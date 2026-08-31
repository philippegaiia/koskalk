<?php

use App\Contracts\IngredientEditorialClient;
use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Contracts\IngredientGuidanceResearchClient;
use App\Data\IngredientEditorialResponse;
use App\Data\IngredientGapResearchResponse;
use App\Data\IngredientGuidanceAuthoringResponse;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Enums\IngredientSubcategory;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientFunction;
use App\Services\IngredientEnrichment\IngredientEnrichmentPipeline;
use App\Services\IngredientEnrichment\IngredientSourceException;
use App\Services\IngredientEnrichment\UsIngredientDeclarationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('assembles precise argan facts from deterministic eu and us sources before editorial work', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient();
    $item = hybridPipelineItem('argan_oil', 'Argan oil');

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);
    $proposal = $response->result['proposal'];
    $identifiers = collect($proposal['identifiers'])->groupBy('scheme')->map->pluck('value');

    expect($proposal['inci_name'])->toBe('ARGANIA SPINOSA KERNEL OIL')
        ->and($identifiers->get('cosing_ref')->all())->toBe(['54495'])
        ->and($identifiers->get('cas')->all())->toBe(['223747-87-3', '299184-75-1'])
        ->and($identifiers->get('unii')->all())->toBe(['4V59G5UW9X'])
        ->and(collect($proposal['market_labels'])->firstWhere('market_code', 'eu')['declaration_name'])->toBe('ARGANIA SPINOSA KERNEL OIL')
        ->and(collect($proposal['market_labels'])->firstWhere('market_code', 'us'))->toMatchArray([
            'declaration_name' => 'ARGAN OIL',
            'confidence' => 'supported',
        ])
        ->and(collect($proposal['cosing_functions'])->pluck('key')->all())->toBe([
            'skin_conditioning', 'emollient',
        ])
        ->and($response->structuredSourceCalls)->toBe(7)
        ->and($response->webSearchCalls)->toBe(0)
        ->and($editorial->calls)->toBe(1)
        ->and(data_get($editorial->facts, 'proposal.inci_name'))->toBe('ARGANIA SPINOSA KERNEL OIL');
});

it('retains all apricot identifiers and keeps eu and us declarations distinct', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('apricot');
    $editorial = fakeHybridEditorialClient();
    $item = hybridPipelineItem('apricot_oil', 'Apricot kernel oil');

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);
    $proposal = $response->result['proposal'];
    $identifiers = collect($proposal['identifiers'])->groupBy('scheme')->map->pluck('value');

    expect($proposal['inci_name'])->toBe('PRUNUS ARMENIACA KERNEL OIL')
        ->and($identifiers->get('cosing_ref')->all())->toBe(['78931'])
        ->and($identifiers->get('cas')->all())->toBe(['68650-44-2', '72869-69-3'])
        ->and($identifiers->get('ec')->all())->toBe(['272-046-1'])
        ->and($identifiers->get('unii')->all())->toBe(['54JB35T06A'])
        ->and(collect($proposal['market_labels'])->pluck('declaration_name', 'market_code')->all())->toBe([
            'eu' => 'PRUNUS ARMENIACA KERNEL OIL',
            'us' => 'APRICOT KERNEL OIL',
        ])
        ->and(collect($proposal['cosing_functions'])->pluck('key')->all())->toBe([
            'perfuming', 'skin_conditioning',
        ])
        ->and($response->structuredSourceCalls)->toBe(7)
        ->and($response->webSearchCalls)->toBe(0)
        ->and($editorial->calls)->toBe(1);
});

it('keeps EU and harmonized US botanical declarations distinct', function (): void {
    $result = app(UsIngredientDeclarationService::class)->propose([
        'unii' => 'Q9L0O73W7L',
        'common_name' => 'COCONUT OIL',
        'inci_names' => ['COCOS NUCIFERA (COCONUT) OIL'],
        'cas' => ['8001-31-8'],
    ]);

    expect($result->data['declaration_name'])->toBe('Coconut (Cocos Nucifera) Oil');
});

it('uses the FDA INCI alias to resolve a plain English oil to the exact EU identity', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('coconut');
    $editorial = fakeHybridEditorialClient();
    $item = hybridPipelineItem('coconut_oil', 'Coconut Oil');

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);
    $proposal = $response->result['proposal'];
    $identifiers = collect($proposal['identifiers'])->groupBy('scheme')->map->pluck('value');

    expect($proposal['inci_name'])->toBe('COCOS NUCIFERA OIL')
        ->and($identifiers->get('cosing_ref')->all())->toBe(['75444'])
        ->and($identifiers->get('cas')->all())->toBe(['8001-31-8'])
        ->and($identifiers->get('ec')->all())->toBe(['232-282-8'])
        ->and($identifiers->get('unii')->all())->toBe(['Q9L0O73W7L'])
        ->and(collect($proposal['market_labels'])->pluck('declaration_name', 'market_code')->all())->toBe([
            'eu' => 'COCOS NUCIFERA OIL',
            'us' => 'Coconut (Cocos Nucifera) Oil',
        ])
        ->and(data_get($editorial->facts, 'editorial_context.identity_description'))
        ->toBe('Fixed oil obtained from the dried endosperm of Cocos nucifera.');
});

it('maps an eu ci declaration to the distinct fda colour declaration and regulatory findings', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('colour');
    $editorial = fakeHybridEditorialClient();
    $item = hybridPipelineItem('tartrazine', 'Tartrazine', 'colourants', 'dyes_lakes');

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);
    $proposal = $response->result['proposal'];
    $labels = collect($proposal['market_labels'])->pluck('declaration_name', 'market_code');
    $bareCi = app(UsIngredientDeclarationService::class)->propose(['common_name' => 'CI 19140'], isColourant: true);

    expect($proposal['inci_name'])->toBe('CI 19140')
        ->and($labels->get('eu'))->toBe('CI 19140')
        ->and($labels->get('us'))->toBe('FD&C Yellow No. 5')
        ->and($labels->get('us'))->not->toBe($labels->get('eu'))
        ->and($bareCi->data)->toMatchArray(['declaration_name' => null, 'confidence' => 'unresolved'])
        ->and($proposal)->not->toHaveKey('authorization')
        ->and(data_get($response->result, 'regulatory_findings.0.finding'))->toContain('certification_required')
        ->and(data_get($response->result, 'regulatory_findings.0.finding'))->toContain('section-74.705')
        ->and($response->structuredSourceCalls)->toBe(4)
        ->and($response->webSearchCalls)->toBe(0)
        ->and($editorial->calls)->toBe(1);
});

it('uses an AI taxonomy proposal for an unclassified intake subject and marks it for human review', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('colour');
    fakeHybridEditorialClient([
        'display_name' => 'Tartrazine',
        'category' => 'colourants',
        'subcategory' => 'dyes_lakes',
        'saponification_name' => null,
        'soapmaking_relevant' => false,
    ]);
    $item = hybridPipelineItem(
        'tartrazine_intake',
        'Tartrazine',
        category: null,
        subcategory: null,
        subjectType: 'intake',
        researchFamily: 'colourants',
    );

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);
    $taxonomyProvenance = collect($response->result['value_provenance'])
        ->whereIn('field', ['proposal.category', 'proposal.subcategory'])
        ->pluck('kind', 'field');

    expect($response->result['proposal'])
        ->toMatchArray(['category' => 'colourants', 'subcategory' => 'dyes_lakes'])
        ->and($taxonomyProvenance->all())->toBe([
            'proposal.category' => 'ai_proposed',
            'proposal.subcategory' => 'ai_proposed',
        ]);
});

it('resumes from the FDA identity boundary before querying downstream EU sources', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    $editorial = fakeHybridEditorialClient();
    $item = hybridPipelineItem('argan_retry_oil', 'Argan oil');
    $fdaAvailable = false;

    Http::fake(function ($request) use (&$fdaAvailable) {
        return match (true) {
            str_contains($request->url(), 'cosingchecker.com') => Http::response(file_get_contents(base_path('tests/Fixtures/IngredientEnrichment/cosing-argan.json'))),
            str_contains($request->url(), 'eur-lex.europa.eu') => Http::response(file_get_contents(base_path('tests/Fixtures/IngredientEnrichment/eur-lex-glossary.html'))),
            str_contains($request->url(), 'api.fda.gov') => $fdaAvailable
                ? Http::response(file_get_contents(base_path('tests/Fixtures/IngredientEnrichment/openfda-argan.json')))
                : Http::response([], 503),
            default => Http::response([], 404),
        };
    });

    expect(fn () => app(IngredientEnrichmentPipeline::class)->run($item->id))
        ->toThrow(IngredientSourceException::class)
        ->and(array_keys($item->fresh()->research_stages))->toBe([
            'identity_preparation', 'us_identity',
        ])
        ->and(data_get($item->fresh()->research_stages, 'us_identity.status'))->toBe('failed')
        ->and($item->fresh()->retryableFromStage())->toBe(IngredientEnrichmentResearchStage::UsIdentity)
        ->and($editorial->calls)->toBe(0);

    $fdaAvailable = true;

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect(data_get($response->result, 'proposal.inci_name'))->toBe('ARGANIA SPINOSA KERNEL OIL')
        ->and($item->fresh()->retryableFromStage())->toBeNull()
        ->and($editorial->calls)->toBe(1);
});

it('runs guidance research without exposing broad evidence to metadata editorial work', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient();
    $gapResearch = new class implements IngredientGuidanceResearchClient
    {
        public int $calls = 0;

        public function research(array $facts): IngredientGapResearchResponse
        {
            $this->calls++;

            return new IngredientGapResearchResponse(
                candidateEvidence: [[
                    'field' => 'proposal.info_markdown',
                    'source_name' => 'Example supplier',
                    'source_url' => 'https://supplier.example/technical/argan-oil.pdf',
                    'summary' => 'A lightweight fixed oil used as an emollient in skin-care formulations.',
                    'claim_type' => 'formulation_role',
                    'source_kind' => 'supplier_technical',
                    'scope' => 'product_grade',
                    'evidence_kind' => 'fact',
                    'usage_application' => 'not_applicable',
                    'recommended_min_percent' => null,
                    'recommended_max_percent' => null,
                    'percentage_basis' => 'not_applicable',
                ]],
                warnings: [],
                unresolvedQuestions: [],
                responseId: 'resp_gap',
                requestId: 'req_gap',
                model: 'gpt-test',
                inputTokens: 40,
                outputTokens: 20,
                webSearchCalls: 1,
                sources: [[
                    'url' => 'https://supplier.example/technical/argan-oil.pdf',
                    'title' => 'Argan oil technical data',
                ]],
            );
        }
    };
    app()->instance(IngredientGuidanceResearchClient::class, $gapResearch);
    $item = hybridPipelineItem('argan_gap_oil', 'Argan oil');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect($gapResearch->calls)->toBe(1)
        ->and($editorial->calls)->toBe(1)
        ->and(data_get($editorial->facts, 'gap_research'))->toBeNull()
        ->and(collect($response->result['evidence'])->contains(
            fn (array $evidence): bool => $evidence['field'] === 'proposal.info_markdown'
                && $evidence['source_url'] === 'https://supplier.example/technical/argan-oil.pdf'
                && $evidence['source_tier'] === 'editorial',
        ))->toBeTrue()
        ->and(data_get($response->result, 'guidance_evidence.0'))->toMatchArray([
            'claim_type' => 'formulation_role',
            'source_kind' => 'supplier_technical',
            'scope' => 'product_grade',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'percentage_basis' => 'not_applicable',
        ])
        ->and(data_get($item->fresh()->research_stages, 'ai_guidance_research.data.guidance_evidence.0.claim_type'))
        ->toBe('formulation_role')
        ->and($response->inputTokens)->toBe(140)
        ->and($response->outputTokens)->toBe(70)
        ->and($response->webSearchCalls)->toBe(1)
        ->and($response->sources)->toContain([
            'url' => 'https://supplier.example/technical/argan-oil.pdf',
            'title' => 'Argan oil technical data',
        ]);
});

it('aligns soapmaking relevance with the rendered guidance sections', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    fakeHybridEditorialClient([
        'info_markdown' => "## Overview\nA source-identified plant oil.\n\n## Formulation use\nUsed as an emollient in cosmetic formulations.",
        'soapmaking_relevant' => true,
    ]);
    $item = hybridPipelineItem('argan_without_soap_guidance', 'Argan oil');

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect($response->result['proposal']['info_markdown'])->not->toContain('## Soapmaking')
        ->and($response->result['proposal']['soapmaking_relevant'])->toBeFalse();
});

it('passes guidance research uncertainty to full-enrichment authoring without exposing it to metadata editorial', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient(omitGuidance: true);
    $researchQuestion = 'Confirm the reported cosmetic usage basis before publishing a recommendation.';
    app()->instance(IngredientGuidanceResearchClient::class, new class($researchQuestion) implements IngredientGuidanceResearchClient
    {
        public function __construct(private readonly string $researchQuestion) {}

        public function research(array $facts): IngredientGapResearchResponse
        {
            return new IngredientGapResearchResponse(
                candidateEvidence: [],
                warnings: [],
                unresolvedQuestions: [$this->researchQuestion],
                responseId: 'resp_gap_question',
                requestId: 'req_gap_question',
                model: 'gpt-test',
                inputTokens: 1,
                outputTokens: 1,
                webSearchCalls: 1,
                sources: [],
            );
        }
    });
    $authoring = new class implements IngredientGuidanceAuthoringClient
    {
        /** @var array<string, mixed> */
        public array $context = [];

        public function author(array $context): IngredientGuidanceAuthoringResponse
        {
            $this->context = $context;

            return new IngredientGuidanceAuthoringResponse(
                guidance: [
                    'info_markdown' => "## Overview\nA source-identified plant oil.\n\n## Formulation use\nIts supported profile informs formulation selection.",
                    'warnings' => [],
                    'unresolved_questions' => [],
                ],
                responseId: 'resp_guidance_question',
                requestId: 'req_guidance_question',
                model: 'gpt-test',
                inputTokens: 1,
                outputTokens: 1,
            );
        }
    };
    app()->instance(IngredientGuidanceAuthoringClient::class, $authoring);
    $item = hybridPipelineItem('argan_guidance_question', 'Argan oil');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);

    app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect(data_get($editorial->facts, 'unresolved_questions'))->not->toContain($researchQuestion)
        ->and($authoring->context['guidance_unresolved_questions'])->toContain($researchQuestion);
});

it('keeps full enrichment reviewable when guidance evidence is mixed', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient();
    $gapResearch = new class implements IngredientGuidanceResearchClient
    {
        public function research(array $facts): IngredientGapResearchResponse
        {
            return new IngredientGapResearchResponse(
                candidateEvidence: [
                    [
                        'field' => 'proposal.info_markdown',
                        'source_name' => 'Example supplier',
                        'source_url' => 'https://supplier.example/technical/argan-oil.pdf',
                        'summary' => 'A lightweight fixed oil used as an emollient in skin-care formulations.',
                        'claim_type' => 'formulation_role',
                        'source_kind' => 'supplier_technical',
                        'scope' => 'product_grade',
                        'evidence_kind' => 'fact',
                        'usage_application' => 'not_applicable',
                        'recommended_min_percent' => null,
                        'recommended_max_percent' => null,
                        'percentage_basis' => 'not_applicable',
                    ],
                    [
                        'field' => 'proposal.info_markdown',
                        'source_name' => 'Unconsulted source',
                        'source_url' => 'https://other.example/argan-oil',
                        'summary' => 'This row must not reach editorial authoring.',
                        'claim_type' => 'formulation_role',
                        'source_kind' => 'specialist_reference',
                        'scope' => 'material',
                        'evidence_kind' => 'fact',
                        'usage_application' => 'not_applicable',
                        'recommended_min_percent' => null,
                        'recommended_max_percent' => null,
                        'percentage_basis' => 'not_applicable',
                    ],
                ],
                warnings: [],
                unresolvedQuestions: [],
                responseId: 'resp-gap-mixed',
                requestId: 'req-gap-mixed',
                model: 'gpt-test',
                inputTokens: 40,
                outputTokens: 20,
                webSearchCalls: 1,
                sources: [[
                    'url' => 'https://supplier.example/technical/argan-oil.pdf',
                    'title' => 'Argan oil technical data',
                ]],
            );
        }
    };
    app()->instance(IngredientGuidanceResearchClient::class, $gapResearch);
    $item = hybridPipelineItem('argan_mixed_guidance_oil', 'Argan oil');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);
    $freshItem = $item->fresh();

    expect(data_get($editorial->facts, 'gap_research.candidate_evidence'))->toHaveCount(1)
        ->and(data_get($editorial->facts, 'gap_research.candidate_evidence.0.source_url'))
        ->toBe('https://supplier.example/technical/argan-oil.pdf')
        ->and(data_get($editorial->facts, 'gap_research.candidate_evidence.1'))->toBeNull()
        ->and(data_get($freshItem->research_stages, 'ai_guidance_research.data.rejected_evidence'))->toBe([
            ['index' => 1, 'code' => 'unconsulted_url', 'host' => 'other.example'],
        ])
        ->and($response->result['guidance_evidence'])->toHaveCount(1)
        ->and($response->sources)->toContain([
            'url' => 'https://supplier.example/technical/argan-oil.pdf',
            'title' => 'Argan oil technical data',
        ])
        ->and(collect($response->sources)->pluck('url')->all())
        ->not->toContain('https://other.example/argan-oil')
        ->and($response->result['warnings'])->toContain(
            '1 researched evidence item was rejected because it did not meet the evidence rules.',
        );
});

it('passes trusted soap chemistry through to the editorial facts', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient();
    $item = hybridPipelineItem(
        'argan_trusted_oil',
        'Argan oil',
        trustedSoapChemistry: [
            'koh_sap_value' => '0.191000',
            'naoh_sap_value' => '0.136183',
            'iodine_value' => '95.000',
            'ins_value' => '95.000',
            'fatty_acids' => [[
                'key' => 'oleic',
                'name' => 'Oleic',
                'saturation_class' => 'monounsaturated',
                'percentage' => '46.00000',
            ]],
        ],
    );

    app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect(data_get($editorial->facts, 'editorial_context.trusted_soap_chemistry'))
        ->toBe(data_get($item->snapshot, 'current.soap_chemistry'));
});

it('reuses persisted guidance research after editorial generation fails', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient(failFirst: true);
    $gapResearch = new class implements IngredientGuidanceResearchClient
    {
        public int $calls = 0;

        public function research(array $facts): IngredientGapResearchResponse
        {
            $this->calls++;

            return new IngredientGapResearchResponse(
                candidateEvidence: [[
                    'field' => 'proposal.info_markdown',
                    'source_name' => 'Example supplier',
                    'source_url' => 'https://supplier.example/technical/argan-oil.pdf',
                    'summary' => 'A lightweight fixed oil used as an emollient in skin-care formulations.',
                    'claim_type' => 'formulation_role',
                    'source_kind' => 'supplier_technical',
                    'scope' => 'product_grade',
                    'evidence_kind' => 'fact',
                    'usage_application' => 'not_applicable',
                    'recommended_min_percent' => null,
                    'recommended_max_percent' => null,
                    'percentage_basis' => 'not_applicable',
                ]],
                warnings: [],
                unresolvedQuestions: [],
                responseId: 'resp_gap_retry',
                requestId: 'req_gap_retry',
                model: 'gpt-test',
                inputTokens: 40,
                outputTokens: 20,
                webSearchCalls: 1,
                sources: [[
                    'url' => 'https://supplier.example/technical/argan-oil.pdf',
                    'title' => 'Argan oil technical data',
                ]],
            );
        }
    };
    app()->instance(IngredientGuidanceResearchClient::class, $gapResearch);
    $item = hybridPipelineItem('argan_persisted_guidance_oil', 'Argan oil');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);

    expect(fn () => app(IngredientEnrichmentPipeline::class)->run($item->id))
        ->toThrow(RuntimeException::class, 'Simulated editorial timeout');

    expect($gapResearch->calls)->toBe(1)
        ->and($editorial->calls)->toBe(1)
        ->and(data_get($item->fresh()->research_stages, 'ai_guidance_research.status'))->toBe('completed')
        ->and(data_get($item->fresh()->research_stages, 'ai_editorial.status'))->toBe('failed');

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect($gapResearch->calls)->toBe(1)
        ->and($editorial->calls)->toBe(2)
        ->and($response->inputTokens)->toBe(140)
        ->and($response->outputTokens)->toBe(70)
        ->and($response->webSearchCalls)->toBe(1);
});

function seedHybridCosingFunctions(): void
{
    foreach ([
        ['key' => 'perfuming', 'name' => 'Perfuming', 'sort_order' => 10],
        ['key' => 'colorant', 'name' => 'Colorant', 'sort_order' => 15],
        ['key' => 'skin_conditioning', 'name' => 'Skin conditioning', 'sort_order' => 20],
        ['key' => 'emollient', 'name' => 'Emollient', 'sort_order' => 30],
    ] as $function) {
        IngredientFunction::factory()->create($function);
    }
}

function fakeHybridIngredientSources(string $fixture): void
{
    Http::fake(function ($request) use ($fixture) {
        $path = match (true) {
            str_contains($request->url(), 'cosingchecker.com') => "tests/Fixtures/IngredientEnrichment/cosing-{$fixture}.json",
            str_contains($request->url(), 'eur-lex.europa.eu') => 'tests/Fixtures/IngredientEnrichment/eur-lex-glossary.html',
            str_contains($request->url(), 'api.fda.gov') => "tests/Fixtures/IngredientEnrichment/openfda-{$fixture}.json",
            str_contains($request->url(), 'fda.gov') => 'tests/Fixtures/IngredientEnrichment/fda-colours.html',
            default => null,
        };

        return $path === null
            ? Http::response([], 404)
            : Http::response(file_get_contents(base_path($path)), 200);
    });
}

function fakeHybridEditorialClient(
    array $overrides = [],
    bool $failFirst = false,
    bool $omitGuidance = false,
): IngredientEditorialClient {
    $client = new class($overrides, $failFirst, $omitGuidance) implements IngredientEditorialClient
    {
        public int $calls = 0;

        /** @var array<string, mixed> */
        public array $facts = [];

        /** @param array<string, mixed> $overrides */
        public function __construct(
            private readonly array $overrides,
            private readonly bool $failFirst,
            private readonly bool $omitGuidance,
        ) {}

        public function edit(array $facts): IngredientEditorialResponse
        {
            $this->calls++;
            $this->facts = $facts;
            if ($this->failFirst && $this->calls === 1) {
                throw new RuntimeException('Simulated editorial timeout');
            }

            $displayName = str_contains((string) data_get($facts, 'proposal.inci_name'), 'ARGANIA')
                ? 'Argan oil'
                : 'Apricot kernel oil';

            $editorial = [
                'display_name' => $displayName,
                'category' => data_get($facts, 'proposal.category'),
                'subcategory' => data_get($facts, 'proposal.subcategory'),
                'saponification_name' => $displayName,
                'info_markdown' => "## Overview\nA source-identified plant oil.\n\n## Formulation use\nUsed as an emollient in cosmetic formulations.\n\n## Soapmaking\nCan be used as part of the oil blend.",
                'soapmaking_relevant' => true,
                'translations' => [[
                    'locale' => 'fr',
                    'display_name' => $displayName,
                    'saponification_name' => $displayName,
                    'info_markdown' => "## Présentation\nUne huile végétale identifiée.\n\n## Utilisation\nUtilisée comme émollient.\n\n## Savonnerie\nPeut faire partie du mélange huileux.",
                ]],
                'warnings' => [],
                'unresolved_questions' => [],
                ...$this->overrides,
            ];
            if ($this->omitGuidance) {
                unset($editorial['info_markdown']);
            }

            return new IngredientEditorialResponse(
                editorial: $editorial,
                responseId: 'resp_hybrid',
                requestId: 'req_hybrid',
                model: 'gpt-test',
                inputTokens: 100,
                outputTokens: 50,
            );
        }
    };

    app()->instance(IngredientEditorialClient::class, $client);

    return $client;
}

function hybridPipelineItem(
    string $catalogKey,
    string $displayName,
    ?string $category = 'lipids',
    ?string $subcategory = 'vegetable_oils',
    string $subjectType = 'ingredient',
    ?string $researchFamily = null,
    ?array $trustedSoapChemistry = null,
): IngredientEnrichmentBatchItem {
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', false);

    return IngredientEnrichmentBatchItem::factory()->create([
        'catalog_key' => $catalogKey,
        'snapshot' => [
            'catalog_key' => $catalogKey,
            'source_fingerprint' => str_repeat('a', 64),
            'subject_type' => $subjectType,
            'current' => [
                'canonical' => [
                    'display_name' => $displayName,
                    'inci_name' => null,
                    'category' => $category,
                    'subcategory' => $subcategory,
                ],
                'identifiers' => [],
                ...($trustedSoapChemistry === null ? [] : ['soap_chemistry' => $trustedSoapChemistry]),
            ],
            'vocabulary' => [
                'category' => ['allowed' => collect(IngredientCategory::cases())->map->value->all()],
                'subcategories' => collect(IngredientSubcategory::cases())->map->value->all(),
                'locales' => ['fr'],
            ],
            'research_rules' => ['research_family' => $researchFamily],
        ],
        'source_fingerprint' => str_repeat('a', 64),
    ]);
}
