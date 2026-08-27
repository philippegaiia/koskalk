<?php

use App\Contracts\IngredientEditorialClient;
use App\Services\IngredientEnrichment\IngredientEnrichmentEditorialPrompt;
use App\Services\IngredientEnrichment\OpenAiIngredientGapResearchClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends deterministic facts to a strict editorial-only response request', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_editorial_123',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(editorialValues(), JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 321, 'output_tokens' => 123],
        ], 200, ['x-request-id' => 'req_editorial_456']),
    ]);

    $response = app(IngredientEditorialClient::class)->edit(editorialFacts());

    expect($response->editorial)->toBe(editorialValues())
        ->and($response->responseId)->toBe('resp_editorial_123')
        ->and($response->requestId)->toBe('req_editorial_456')
        ->and($response->inputTokens)->toBe(321)
        ->and($response->outputTokens)->toBe(123)
        ->and($response->webSearchCalls)->toBe(0);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/responses'
            && $data['model'] === 'gpt-5.6-terra'
            && $data['store'] === false
            && ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && data_get($data, 'text.format.type') === 'json_schema'
            && data_get($data, 'text.format.strict') === true
            && array_keys(data_get($data, 'text.format.schema.properties', [])) === [
                'display_name',
                'category',
                'subcategory',
                'saponification_name',
                'soap_inci_naoh_name',
                'soap_inci_koh_name',
                'soapmaking_relevant',
                'translations',
                'warnings',
                'unresolved_questions',
            ]
            && str_contains((string) $data['instructions'], 'must not change')
            && str_contains((string) $data['input'], 'ARGANIA SPINOSA KERNEL OIL')
            && str_contains((string) $data['input'], '4V59G5UW9X')
            && str_contains((string) $data['input'], 'skin_conditioning')
            && str_contains((string) $data['input'], 'ARGAN OIL')
            && str_contains((string) $data['input'], 'verified');
    });
});

it('uses source-restricted web search only in an explicitly enabled gap-research call', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.gap_research.enabled', true);
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_gap_123',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'action' => ['sources' => [[
                        'url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
                        'title' => 'Argania Spinosa Kernel Oil',
                    ]]],
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'candidate_evidence' => [[
                                'field' => 'proposal.info_markdown',
                                'source_name' => 'COSMILE Europe',
                                'source_url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
                                'summary' => 'A lightweight fixed oil used as an emollient in skin-care formulations.',
                            ]],
                            'warnings' => [],
                            'unresolved_questions' => [],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ],
            ],
            'usage' => ['input_tokens' => 210, 'output_tokens' => 67],
        ], 200, ['x-request-id' => 'req_gap_456']),
    ]);

    $response = app(OpenAiIngredientGapResearchClient::class)->research(editorialFacts());

    expect($response->webSearchCalls)->toBe(1)
        ->and($response->sources)->toBe([[
            'url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
            'title' => 'Argania Spinosa Kernel Oil',
        ]]);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['tools'][0] === [
            'type' => 'web_search',
            'filters' => [
                'allowed_domains' => config('ingredient-enrichment.openai.gap_research.allowed_domains'),
            ],
        ]
            && $data['include'] === ['web_search_call.action.sources']
            && str_contains((string) $data['instructions'], 'candidate evidence only')
            && str_contains((string) $data['instructions'], 'practical formulation and soapmaking facts')
            && str_contains((string) $data['instructions'], 'must not establish legal declarations');
    });
});

it('does not permit an implicit gap-research request when the feature is disabled', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.gap_research.enabled', false);
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', false);
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(OpenAiIngredientGapResearchClient::class)->research(editorialFacts()))
        ->toThrow(RuntimeException::class, 'disabled');

    Http::assertNothingSent();
});

it('rejects COSMILE candidate evidence for an identity or declaration field', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.gap_research.enabled', true);
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_gap_identity_123',
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'candidate_evidence' => [[
                            'field' => 'proposal.inci_name',
                            'source_name' => 'COSMILE Europe',
                            'source_url' => 'https://cosmileeurope.eu/inci/detail/1152/argania-spinosa-kernel-oil/',
                            'summary' => 'An identity claim that is not allowed from this source.',
                        ]],
                        'warnings' => [],
                        'unresolved_questions' => [],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    expect(fn () => app(OpenAiIngredientGapResearchClient::class)->research(editorialFacts()))
        ->toThrow(RuntimeException::class, 'cannot support identity or declaration fields');
});

it('keeps metadata editorial separate from guidance localization', function (): void {
    $prompt = app(IngredientEnrichmentEditorialPrompt::class)->build(editorialFacts());

    expect($prompt['instructions'])
        ->toContain('Do not write `info_markdown`')
        ->toContain('Translate only display names and saponification stems')
        ->toContain('Do not research the web');
});

it('keeps research commentary out of metadata editorial output', function (): void {
    $prompt = app(IngredientEnrichmentEditorialPrompt::class)->build(editorialFacts());

    expect($prompt['instructions'])
        ->toContain('Never mention the research process')
        ->toContain('Never mention the research process')
        ->toContain('When the supplied facts are insufficient')
        ->toContain('human reviewer makes the final taxonomy decision');
});

it('filters low-value editorial claims instead of mechanically expanding source facts', function (): void {
    $prompt = app(IngredientEnrichmentEditorialPrompt::class)->build(editorialFacts());

    expect($prompt['version'])->toBe('ingredient-enrichment-metadata-v1')
        ->and($prompt['instructions'])
        ->toContain('Do not research the web')
        ->toContain('Do not write `info_markdown`');
});

it('allows a bounded taxonomy proposal while keeping the family hint non-authoritative', function (): void {
    $prompt = app(IngredientEnrichmentEditorialPrompt::class)->build(editorialFacts());

    expect($prompt['instructions'])
        ->toContain('propose one compatible category and subcategory')
        ->toContain('research-family hint is routing context, not an approved category')
        ->toContain('human reviewer makes the final taxonomy decision');
});

it('requires botanical subcategories to match the physical material form', function (): void {
    $prompt = app(IngredientEnrichmentEditorialPrompt::class)->build(editorialFacts());

    expect($prompt['instructions'])
        ->toContain('Select the subcategory from the supported physical material form')
        ->toContain('`aqueous_glycerinated_extracts` only for a liquid extract')
        ->toContain('`plant_powders` for whole or milled plant material')
        ->toContain('`dry_extracts` for concentrated botanical-derived dry solids');
});

/** @return array<string, mixed> */
function editorialFacts(): array
{
    return [
        'catalog_key' => 'argan_oil',
        'vocabulary' => [
            'category' => ['allowed' => ['lipids', 'colourants']],
            'subcategories' => ['vegetable_oils', 'dyes_lakes'],
            'locales' => ['fr'],
        ],
        'proposal' => [
            'display_name' => 'Argan oil',
            'inci_name' => 'ARGANIA SPINOSA KERNEL OIL',
            'category' => 'lipids',
            'subcategory' => 'vegetable_oils',
            'aliases' => [],
            'identifiers' => [[
                'scheme' => 'unii',
                'value' => '4V59G5UW9X',
                'is_primary' => true,
            ]],
            'cosing_functions' => ['skin_conditioning'],
            'market_labels' => [
                ['market_code' => 'eu', 'declaration_name' => 'ARGANIA SPINOSA KERNEL OIL'],
                ['market_code' => 'us', 'declaration_name' => 'ARGAN OIL'],
            ],
        ],
        'field_confidence' => ['proposal.inci_name' => 'verified'],
        'evidence' => [['field' => 'proposal.inci_name', 'source_tier' => 'official']],
    ];
}

/** @return array<string, mixed> */
function editorialValues(): array
{
    return [
        'display_name' => 'Argan oil',
        'category' => 'lipids',
        'subcategory' => 'vegetable_oils',
        'saponification_name' => 'Argan oil',
        'soapmaking_relevant' => true,
        'translations' => [[
            'locale' => 'fr',
            'display_name' => 'Huile d’argan',
            'saponification_name' => 'Huile d’argan',
        ]],
        'warnings' => [],
        'unresolved_questions' => [],
    ];
}
