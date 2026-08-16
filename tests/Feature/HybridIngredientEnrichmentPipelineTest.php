<?php

use App\Contracts\IngredientEditorialClient;
use App\Data\IngredientEditorialResponse;
use App\Data\IngredientGapResearchResponse;
use App\Enums\IngredientCategory;
use App\Enums\IngredientEnrichmentResearchStage;
use App\Enums\IngredientSubcategory;
use App\Models\IngredientEnrichmentBatchItem;
use App\Models\IngredientFunction;
use App\Services\IngredientEnrichment\IngredientEnrichmentPipeline;
use App\Services\IngredientEnrichment\IngredientSourceException;
use App\Services\IngredientEnrichment\OpenAiIngredientGapResearchClient;
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

it('runs restricted guidance research automatically and includes its usage', function (): void {
    seedHybridCosingFunctions();
    cache()->flush();
    fakeHybridIngredientSources('argan');
    $editorial = fakeHybridEditorialClient();
    $gapResearch = new class extends OpenAiIngredientGapResearchClient
    {
        public int $calls = 0;

        public function research(array $facts): IngredientGapResearchResponse
        {
            $this->calls++;

            return new IngredientGapResearchResponse(
                candidateEvidence: [[
                    'field' => 'proposal.info_markdown',
                    'source_name' => 'COSMILE Europe',
                    'source_url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
                    'summary' => 'A lightweight fixed oil used as an emollient in skin-care formulations.',
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
                    'url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
                    'title' => 'Argania Spinosa Kernel Oil',
                ]],
            );
        }
    };
    app()->instance(OpenAiIngredientGapResearchClient::class, $gapResearch);
    $item = hybridPipelineItem('argan_gap_oil', 'Argan oil');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);

    $response = app(IngredientEnrichmentPipeline::class)->run($item->id);

    expect($gapResearch->calls)->toBe(1)
        ->and($editorial->calls)->toBe(1)
        ->and(data_get($editorial->facts, 'gap_research.candidate_evidence.0.source_name'))->toBe('COSMILE Europe')
        ->and(data_get($editorial->facts, 'gap_research.candidate_evidence.0.summary'))->toContain('emollient')
        ->and(collect($response->result['evidence'])->contains(
            fn (array $evidence): bool => $evidence['field'] === 'proposal.info_markdown'
                && $evidence['source_url'] === 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/'
                && $evidence['source_tier'] === 'editorial',
        ))->toBeTrue()
        ->and($response->inputTokens)->toBe(140)
        ->and($response->outputTokens)->toBe(70)
        ->and($response->webSearchCalls)->toBe(1)
        ->and($response->sources)->toContain([
            'url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
            'title' => 'Argania Spinosa Kernel Oil',
        ]);
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

function fakeHybridEditorialClient(array $overrides = []): IngredientEditorialClient
{
    $client = new class($overrides) implements IngredientEditorialClient
    {
        public int $calls = 0;

        /** @var array<string, mixed> */
        public array $facts = [];

        /** @param array<string, mixed> $overrides */
        public function __construct(private readonly array $overrides) {}

        public function edit(array $facts): IngredientEditorialResponse
        {
            $this->calls++;
            $this->facts = $facts;
            $displayName = str_contains((string) data_get($facts, 'proposal.inci_name'), 'ARGANIA')
                ? 'Argan oil'
                : 'Apricot kernel oil';

            return new IngredientEditorialResponse(
                editorial: [
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
                ],
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
