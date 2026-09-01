<?php

use App\Contracts\IngredientEditorialClient;
use App\Contracts\IngredientGuidanceResearchClient;
use App\Services\IngredientEnrichment\IngredientEnrichmentEditorialPrompt;
use App\Services\IngredientEnrichment\OpenAiIngredientGapResearchClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('binds guidance research to the OpenAI web-search client', function (): void {
    expect(app(IngredientGuidanceResearchClient::class))
        ->toBeInstanceOf(OpenAiIngredientGapResearchClient::class);
});

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

        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-key-never-log')
            && $data['model'] === config('ingredient-enrichment.openai.model')
            && $data['store'] === false
            && ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && data_get($data, 'text.format.type') === 'json_schema'
            && data_get($data, 'text.format.name') === 'ingredient_enrichment_editorial'
            && data_get($data, 'text.format.strict') === true
            && data_get($data, 'text.format.schema.required') === [
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
            && data_get($data, 'text.format.schema.additionalProperties') === false
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

it('redacts an unparseable provider error body after transient retries', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'secret-api-key');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response(
            'provider-raw-sensitive-body',
            500,
            ['x-request-id' => 'req_editorial_failure'],
        ),
    ]);

    try {
        app(IngredientEditorialClient::class)->edit(editorialFacts());

        $this->fail('The provider response should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 500')
            ->toContain('req_editorial_failure')
            ->not->toContain('secret-api-key')
            ->not->toContain('provider-raw-sensitive-body');
    }

    Http::assertSentCount(3);
});

it('uses bounded practical web search only in an explicitly enabled guidance-research call', function (): void {
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
                        'url' => 'https://supplier.example/technical/argan-oil.pdf',
                        'title' => 'Argan oil technical data',
                    ]]],
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'candidate_evidence' => [[
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
            'url' => 'https://supplier.example/technical/argan-oil.pdf',
            'title' => 'Argan oil technical data',
        ]]);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['tools'][0] === [
            'type' => 'web_search',
        ]
            && $data['max_tool_calls'] === config('ingredient-enrichment.openai.guidance_research.maximum_tool_calls')
            && $data['include'] === ['web_search_call.action.sources']
            && data_get($data, 'text.format.schema.properties.candidate_evidence.items.properties.field.enum') === ['proposal.info_markdown']
            && str_contains((string) $data['instructions'], 'candidate evidence only')
            && str_contains((string) $data['instructions'], 'practical usefulness')
            && str_contains((string) $data['instructions'], 'specialist formulation or soapmaking references')
            && str_contains((string) $data['instructions'], 'Do not use patents')
            && str_contains((string) $data['instructions'], 'isolated narrow studies')
            && str_contains((string) $data['instructions'], 'Stop researching')
            && str_contains((string) $data['instructions'], 'soap-relevant materials')
            && str_contains((string) $data['instructions'], 'For non-usage claims')
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

it('returns guidance candidates without applying the evidence policy in transport', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_gap_unconsulted',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'action' => ['sources' => [[
                        'url' => 'https://supplier.example/technical/argan-oil.pdf',
                        'title' => 'Argan oil technical data',
                    ]]],
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'candidate_evidence' => [[
                                'field' => 'proposal.info_markdown',
                                'source_name' => 'Fabricated source',
                                'source_url' => 'https://other.example/argan-oil',
                                'summary' => 'A fabricated citation.',
                                'claim_type' => 'formulation_role',
                                'source_kind' => 'specialist_reference',
                                'scope' => 'material',
                                'evidence_kind' => 'fact',
                                'usage_application' => 'not_applicable',
                                'recommended_min_percent' => null,
                                'recommended_max_percent' => null,
                                'percentage_basis' => 'not_applicable',
                            ]],
                            'warnings' => [],
                            'unresolved_questions' => [],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ],
            ],
        ]),
    ]);

    $response = app(OpenAiIngredientGapResearchClient::class)->research(editorialFacts());

    expect($response->candidateEvidence)->toHaveCount(1)
        ->and($response->candidateEvidence[0]['source_url'])->toBe('https://other.example/argan-oil')
        ->and($response->sources)->toBe([[
            'url' => 'https://supplier.example/technical/argan-oil.pdf',
            'title' => 'Argan oil technical data',
        ]]);
});

it('returns blocked guidance candidates without applying the evidence policy in transport', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.guidance_research.enabled', true);
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_gap_blocked',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'action' => ['sources' => [[
                        'url' => 'https://www.reddit.com/r/formulation/comments/example',
                        'title' => 'Community post',
                    ]]],
                ],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'candidate_evidence' => [[
                                'field' => 'proposal.info_markdown',
                                'source_name' => 'Community post',
                                'source_url' => 'https://www.reddit.com/r/formulation/comments/example',
                                'summary' => 'An unsourced community recommendation.',
                                'claim_type' => 'formulation_role',
                                'source_kind' => 'specialist_reference',
                                'scope' => 'material',
                                'evidence_kind' => 'fact',
                                'usage_application' => 'not_applicable',
                                'recommended_min_percent' => null,
                                'recommended_max_percent' => null,
                                'percentage_basis' => 'not_applicable',
                            ]],
                            'warnings' => [],
                            'unresolved_questions' => [],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ],
            ],
        ]),
    ]);

    $response = app(OpenAiIngredientGapResearchClient::class)->research(editorialFacts());

    expect($response->candidateEvidence)->toHaveCount(1)
        ->and($response->candidateEvidence[0]['source_url'])
        ->toBe('https://www.reddit.com/r/formulation/comments/example');
});

it('does not apply identity evidence policy inside the guidance transport', function (): void {
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
                            'claim_type' => 'origin',
                            'source_kind' => 'regulatory_reference',
                            'scope' => 'material',
                            'evidence_kind' => 'fact',
                            'usage_application' => 'not_applicable',
                            'recommended_min_percent' => null,
                            'recommended_max_percent' => null,
                            'percentage_basis' => 'not_applicable',
                        ]],
                        'warnings' => [],
                        'unresolved_questions' => [],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    $response = app(OpenAiIngredientGapResearchClient::class)->research(editorialFacts());

    expect($response->candidateEvidence)->toHaveCount(1)
        ->and($response->candidateEvidence[0]['field'])->toBe('proposal.inci_name');
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
