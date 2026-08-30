<?php

use App\Contracts\IngredientResearchClient;
use App\Services\IngredientEnrichment\IngredientEnrichmentEvidenceVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

it('sends a source restricted strict responses request and normalizes provenance', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    $result = [
        'format' => 'soapkraft-platform-ingredient-enrichment-result',
        'catalog_key' => 'apricot_oil',
        'evidence' => [],
    ];
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_123',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [
                [
                    'type' => 'web_search_call',
                    'action' => ['sources' => [
                        ['url' => 'https://pubchem.ncbi.nlm.nih.gov/compound/123', 'title' => 'Compound 123'],
                        ['url' => 'https://pubchem.ncbi.nlm.nih.gov/compound/123', 'title' => 'Compound 123'],
                    ]],
                ],
                [
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]],
                ],
            ],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 450],
        ], 200, ['x-request-id' => 'req_456']),
    ]);

    $response = app(IngredientResearchClient::class)->research(researchRecord());

    expect($response->result)->toBe($result)
        ->and($response->responseId)->toBe('resp_123')
        ->and($response->requestId)->toBe('req_456')
        ->and($response->model)->toBe('gpt-5.6-terra-2026-08-01')
        ->and($response->inputTokens)->toBe(1200)
        ->and($response->outputTokens)->toBe(450)
        ->and($response->webSearchCalls)->toBe(1)
        ->and($response->sources)->toBe([
            ['url' => 'https://pubchem.ncbi.nlm.nih.gov/compound/123', 'title' => 'Compound 123'],
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-5.6-terra'
            && $request['store'] === false
            && $request['tools'][0] === [
                'type' => 'web_search',
                'filters' => ['allowed_domains' => config('ingredient-enrichment.openai.allowed_domains')],
            ]
            && $request['include'] === ['web_search_call.action.sources']
            && data_get($request->data(), 'text.format.type') === 'json_schema'
            && data_get($request->data(), 'text.format.strict') === true
            && str_contains((string) $request['instructions'], 'Required source hierarchy')
            && str_contains((string) $request['input'], 'apricot_oil');
    });
});

it('fails safely before sending when the server api key is missing', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', null);
    Http::fake();

    expect(fn () => app(IngredientResearchClient::class)->research(researchRecord()))
        ->toThrow(RuntimeException::class, 'not configured');

    Http::assertNothingSent();
});

it('reports safe provider diagnostics for an unsuccessful response', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'unsupported_parameter',
                'message' => 'Sensitive provider detail must not be exposed.',
            ],
        ], 400, ['x-request-id' => 'req_diagnostic_123']),
    ]);

    try {
        app(IngredientResearchClient::class)->research(researchRecord());
        $this->fail('The provider response should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 400')
            ->toContain('unsupported_parameter')
            ->toContain('req_diagnostic_123')
            ->not->toContain('Sensitive provider detail');
    }
});

it('accepts deterministic adapter evidence without an OpenAI consulted-source echo', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'EUR-Lex Common Ingredient Names Glossary',
            'source_url' => 'https://eur-lex.europa.eu/legal-content/EN/TXT/HTML/?uri=CELEX:32025D1175',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => '32025D1175',
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-13T12:00:00+00:00',
        ]],
        'proposal' => [
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, []))->not->toThrow(ValidationException::class);
});

it('accepts Kew botanical evidence for editorial guidance', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.info_markdown',
            'source_name' => 'Plants of the World Online — Royal Botanic Gardens, Kew',
            'source_url' => 'https://powo.science.kew.org/taxon/urn:lsid:ipni.org:names:668509-1/general-information',
            'source_tier' => 'editorial',
            'confidence' => 'supported',
            'source_version' => null,
            'source_updated_at' => null,
            'retrieved_at' => '2026-08-14T12:00:00+00:00',
        ]],
        'proposal' => [
            'aliases' => [],
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [[
        'url' => 'https://powo.science.kew.org/taxon/urn:lsid:ipni.org:names:668509-1/general-information',
        'title' => 'Plants of the World Online — Royal Botanic Gardens, Kew',
    ]]))
        ->not->toThrow(ValidationException::class);
});

it('rejects a structured mirror falsely marked as an official source', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'CosIng Checker',
            'source_url' => 'https://cosingchecker.com/ingredients/54495-argania-spinosa-kernel-oil/',
            'source_tier' => 'official',
            'confidence' => 'verified',
            'source_version' => 'inventory-2026-03-21',
            'source_updated_at' => '2026-03-21',
            'retrieved_at' => '2026-08-13T12:00:00+00:00',
        ]],
        'proposal' => [
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, []))
        ->toThrow(ValidationException::class);
});

/** @return array<string, mixed> */
function researchRecord(): array
{
    return [
        'catalog_key' => 'apricot_oil',
        'source_fingerprint' => str_repeat('a', 64),
        'vocabulary' => [
            'cosing_functions' => [['key' => 'EMOLLIENT']],
        ],
    ];
}
