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

it('rejects a plausible citation that was not actually consulted', function (): void {
    $result = [
        'evidence' => [[
            'field' => 'proposal.inci_name',
            'source_name' => 'PubChem',
            'source_url' => 'https://pubchem.ncbi.nlm.nih.gov/compound/999',
            'checked_at' => '2026-08-13',
        ]],
        'proposal' => [
            'identifiers' => [],
            'cosing_functions' => [],
            'market_labels' => [],
        ],
    ];

    expect(fn () => app(IngredientEnrichmentEvidenceVerifier::class)->verify($result, [
        ['url' => 'https://pubchem.ncbi.nlm.nih.gov/compound/123', 'title' => 'Compound 123'],
    ]))->toThrow(ValidationException::class, 'not present');
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
