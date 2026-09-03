<?php

use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Services\IngredientEnrichment\IngredientGuidancePrompt;
use App\Services\IngredientEnrichment\IngredientResearchProviderException;
use App\Services\IngredientEnrichment\OpenAiStructuredOutputTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('requests fuller practical guidance without filler when reviewed facts support it', function (): void {
    $prompt = app(IngredientGuidancePrompt::class)->build([]);

    expect($prompt['version'])->toBe('ingredient-guidance-v13')
        ->and(config('ingredient-enrichment.guidance.maximum_words'))->toBe(240)
        ->and(config('ingredient-enrichment.guidance.maximum_characters'))->toBe(2000)
        ->and($prompt['instructions'])
        ->toContain('Aim for 130–200 English words when the reviewed facts support it.')
        ->toContain('Normally write 1–2 overview sentences, 2–3 formulation-use sentences, and, when soapmaking is relevant, 1–2 soapmaking sentences.')
        ->toContain('Prefer specific handling, stability, sensory, selection, and recipe consequences over generic role prose.')
        ->toContain('Do not add filler to reach that range.')
        ->toContain('Use trusted soap chemistry for one conservative recipe consequence when present.')
        ->toContain('principal native or cultivated region')
        ->toContain('Do not include traditional-use, therapeutic, sustainability, or marketing claims')
        ->toContain('Do not repeat facts across sections.');
});

it('keeps renderer-only fresh evidence out of the guidance prompt input', function (): void {
    $prompt = app(IngredientGuidancePrompt::class)->build([
        'guidance_evidence' => [[
            'source_url' => 'https://example.test/merged',
            'summary' => 'Merged evidence row.',
        ]],
        'fresh_guidance_evidence' => [[
            'source_url' => 'https://example.test/internal-fresh',
            'summary' => 'Renderer-only fresh evidence row.',
        ]],
    ]);

    expect($prompt['input'])
        ->toContain('Merged evidence row.')
        ->not->toContain('fresh_guidance_evidence')
        ->not->toContain('Renderer-only fresh evidence row.')
        ->not->toContain('internal-fresh');
});

it('authors evidence-linked guidance with a strict no-web response contract', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_guidance_123',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'overview' => [[
                            'text' => 'A concise overview.',
                            'claim_type' => 'origin',
                            'support_type' => 'fact',
                            'evidence_indexes' => [],
                            'fact_paths' => ['current.canonical.display_name'],
                            'usage_application' => 'not_applicable',
                        ]],
                        'formulation_use' => [[
                            'text' => 'A material-specific use.',
                            'claim_type' => 'formulation_role',
                            'support_type' => 'evidence',
                            'evidence_indexes' => [0],
                            'fact_paths' => [],
                            'usage_application' => 'not_applicable',
                        ]],
                        'soapmaking' => [],
                        'warnings' => [],
                        'unresolved_questions' => [],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 21, 'output_tokens' => 13],
        ], 200, ['x-request-id' => 'req_guidance_456']),
    ]);

    $response = app(IngredientGuidanceAuthoringClient::class)->author([
        'current' => ['canonical' => ['display_name' => 'Argan oil']],
        'guidance_evidence' => [[
            'claim_type' => 'formulation_role',
            'source_kind' => 'supplier_technical',
            'scope' => 'product_grade',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ]],
    ]);

    expect($response->guidance['info_markdown'])->toContain('## Overview')
        ->and($response->responseId)->toBe('resp_guidance_123')
        ->and($response->requestId)->toBe('req_guidance_456')
        ->and($response->inputTokens)->toBe(21)
        ->and($response->outputTokens)->toBe(13);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $properties = data_get($data, 'text.format.schema.properties', []);

        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-key-never-log')
            && $data['model'] === config('ingredient-enrichment.openai.model')
            && $data['store'] === false
            && ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && data_get($data, 'text.format.type') === 'json_schema'
            && data_get($data, 'text.format.name') === 'ingredient_guidance'
            && data_get($data, 'text.format.strict') === true
            && data_get($data, 'text.format.schema.required') === [
                'overview', 'formulation_use', 'soapmaking', 'warnings', 'unresolved_questions',
            ]
            && data_get($data, 'text.format.schema.additionalProperties') === false
            && array_keys($properties) === ['overview', 'formulation_use', 'soapmaking', 'warnings', 'unresolved_questions']
            && data_get($properties, 'overview.items.anyOf.0.additionalProperties') === false
            && data_get($properties, 'overview.items.anyOf.0.required') === [
                'text', 'claim_type', 'support_type', 'evidence_indexes', 'fact_paths', 'usage_application',
            ]
            && data_get($properties, 'overview.items.anyOf.0.properties.claim_type.enum') === collect(config('ingredient-enrichment.openai.guidance_research.allowed_claim_types'))
                ->reject(fn (string $claimType): bool => $claimType === 'usage')
                ->values()
                ->all()
            && data_get($properties, 'overview.items.anyOf.0.properties.usage_application.enum') === ['not_applicable']
            && data_get($properties, 'overview.items.anyOf.1.properties.claim_type.enum') === ['usage']
            && data_get($properties, 'overview.items.anyOf.1.properties.usage_application.enum') === ['cosmetics', 'soapmaking']
            && str_contains((string) $data['instructions'], 'Do not repeat the INCI')
            && str_contains((string) $data['instructions'], 'material-specific formulation decision')
            && str_contains((string) $data['instructions'], 'one sentence per claim')
            && str_contains((string) $data['instructions'], 'configured word and visible-character limits')
            && str_contains((string) $data['instructions'], 'Typical use level')
            && str_contains((string) $data['instructions'], 'approved structured usage fact')
            && str_contains((string) $data['instructions'], 'For every non-usage claim, set `usage_application=not_applicable`')
            && str_contains((string) $data['instructions'], 'not a regulatory or safety limit')
            && str_contains((string) $data['instructions'], 'experienced cosmetic formulator')
            && str_contains((string) $data['instructions'], 'Keep source attribution and evidence-scope labels in structured evidence')
            && ! str_contains((string) $data['instructions'], 'controlled phrases')
            && str_contains((string) $data['instructions'], 'minimum-only bound')
            && str_contains((string) $data['instructions'], 'omit generic filler');
    });
});

it('omits an unsupported guidance claim before returning authoring output', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_guidance_invalid',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'overview' => [],
                        'formulation_use' => [[
                            'text' => 'A claim with no supporting evidence.',
                            'claim_type' => 'solubility',
                            'support_type' => 'evidence',
                            'evidence_indexes' => [0],
                            'fact_paths' => [],
                            'usage_application' => 'not_applicable',
                        ]],
                        'soapmaking' => [],
                        'warnings' => [],
                        'unresolved_questions' => [],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 21, 'output_tokens' => 13],
        ], 200, ['x-request-id' => 'req_guidance_invalid']),
    ]);

    $response = app(IngredientGuidanceAuthoringClient::class)->author([
        'current' => ['canonical' => ['display_name' => 'Argan oil']],
        'guidance_evidence' => [[
            'claim_type' => 'formulation_role',
            'source_kind' => 'supplier_technical',
            'scope' => 'product_grade',
            'evidence_kind' => 'fact',
            'usage_application' => 'not_applicable',
            'recommended_min_percent' => null,
            'recommended_max_percent' => null,
            'percentage_basis' => 'not_applicable',
        ]],
    ]);

    expect($response->guidance['info_markdown'])->not->toContain('A claim with no supporting evidence.')
        ->and($response->guidance['warnings'])->toContain('A guidance claim was omitted because it did not faithfully represent its cited evidence or trusted facts.');
});

it('fails safely before sending when the structured output api key is missing', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', null);
    Http::fake();

    expect(fn () => app(OpenAiStructuredOutputTransport::class)->send(
        instructions: 'Return the answer.',
        input: '<context>value</context>',
        schemaName: 'transport_test',
        schema: ['type' => 'object'],
    ))->toThrow(RuntimeException::class, 'not configured');

    Http::assertNothingSent();
});

it('normalizes a structured response with provider accounting and identifiers', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_transport_123',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['answer' => 'ok'], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => [
                'input_tokens' => 21,
                'output_tokens' => 13,
                'total_tokens' => 34,
            ],
        ], 200, ['x-request-id' => 'req_transport_456']),
    ]);

    $response = app(OpenAiStructuredOutputTransport::class)->send(
        instructions: 'Return the answer.',
        input: '<context>value</context>',
        schemaName: 'transport_test',
        schema: [
            'type' => 'object',
            'properties' => ['answer' => ['type' => 'string']],
            'required' => ['answer'],
            'additionalProperties' => false,
        ],
    );

    expect($response->payload)->toBe(['answer' => 'ok'])
        ->and($response->responseId)->toBe('resp_transport_123')
        ->and($response->requestId)->toBe('req_transport_456')
        ->and($response->model)->toBe('gpt-5.6-terra-2026-08-01')
        ->and($response->inputTokens)->toBe(21)
        ->and($response->outputTokens)->toBe(13)
        ->and($response->totalTokens)->toBe(34);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-key-never-log')
            && $data['model'] === config('ingredient-enrichment.openai.model')
            && $data['reasoning'] === ['effort' => config('ingredient-enrichment.openai.reasoning_effort')]
            && $data['store'] === false
            && ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && data_get($data, 'text.format') === [
                'type' => 'json_schema',
                'name' => 'transport_test',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => ['answer' => ['type' => 'string']],
                    'required' => ['answer'],
                    'additionalProperties' => false,
                ],
            ];
    });
});

it('redacts provider response bodies and retries transient failures', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'secret-api-key');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => [
                'type' => 'rate_limit_error',
                'code' => 'provider-sensitive-code',
                'message' => 'provider-sensitive-body',
            ]], 429, ['x-request-id' => 'req_retry_1'])
            ->push(['error' => [
                'type' => 'server_error',
                'code' => 'provider-sensitive-code',
                'message' => 'provider-sensitive-body',
            ]], 500, ['x-request-id' => 'req_retry_2'])
            ->push(['error' => [
                'type' => 'server_error',
                'code' => 'provider-sensitive-code',
                'message' => 'provider-sensitive-body',
            ]], 500, ['x-request-id' => 'req_retry_3']),
    ]);

    try {
        app(OpenAiStructuredOutputTransport::class)->send(
            instructions: 'Return the answer.',
            input: '<context>value</context>',
            schemaName: 'transport_test',
            schema: ['type' => 'object'],
        );

        $this->fail('The provider response should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 500')
            ->toContain('provider-sensitive-code')
            ->toContain('req_retry_3')
            ->not->toContain('secret-api-key')
            ->not->toContain('provider-sensitive-body');
    }

    Http::assertSentCount(3);
});

it('rejects completed responses with missing metadata or invalid token counts', function (array $overrides, array $headers): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    $payload = array_replace([
        'id' => 'resp_transport_123',
        'status' => 'completed',
        'model' => 'gpt-5.6-terra-2026-08-01',
        'output' => [[
            'type' => 'message',
            'content' => [[
                'type' => 'output_text',
                'text' => json_encode(['answer' => 'ok'], JSON_THROW_ON_ERROR),
            ]],
        ]],
        'usage' => ['input_tokens' => 21, 'output_tokens' => 13],
    ], $overrides);
    Http::fake([
        'api.openai.com/v1/responses' => Http::response($payload, 200, $headers),
    ]);

    expect(fn () => app(OpenAiStructuredOutputTransport::class)->send(
        instructions: 'Return the answer.',
        input: '<context>value</context>',
        schemaName: 'transport_test',
        schema: ['type' => 'object'],
    ))->toThrow(RuntimeException::class, 'invalid ingredient result');
})->with([
    'missing response id' => [['id' => null], ['x-request-id' => 'req_transport_456']],
    'non-scalar response id' => [['id' => ['resp_transport_123']], ['x-request-id' => 'req_transport_456']],
    'blank response id' => [['id' => '   '], ['x-request-id' => 'req_transport_456']],
    'missing model' => [['model' => null], ['x-request-id' => 'req_transport_456']],
    'non-scalar model' => [['model' => ['gpt-5.6-terra-2026-08-01']], ['x-request-id' => 'req_transport_456']],
    'blank model' => [['model' => '   '], ['x-request-id' => 'req_transport_456']],
    'missing request id' => [[], []],
    'missing input token count' => [['usage' => ['output_tokens' => 13]], ['x-request-id' => 'req_transport_456']],
    'negative output token count' => [['usage' => ['input_tokens' => 21, 'output_tokens' => -1]], ['x-request-id' => 'req_transport_456']],
    'non-integer input token count' => [['usage' => ['input_tokens' => '21', 'output_tokens' => 13]], ['x-request-id' => 'req_transport_456']],
]);

it('uses an unknown diagnostic for non-scalar provider error fields', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'error' => [
                'code' => ['provider-sensitive-code'],
                'type' => ['provider-sensitive-type'],
                'message' => 'provider-sensitive-body',
            ],
        ], 400, ['x-request-id' => 'req_transport_error']),
    ]);

    try {
        app(OpenAiStructuredOutputTransport::class)->send(
            instructions: 'Return the answer.',
            input: '<context>value</context>',
            schemaName: 'transport_test',
            schema: ['type' => 'object'],
        );

        $this->fail('The provider response should have failed.');
    } catch (IngredientResearchProviderException $exception) {
        expect($exception->failureCode)
            ->toBe('provider_http_400_unknown_error')
            ->and($exception->getMessage())
            ->toContain('HTTP 400')
            ->toContain('req_transport_error')
            ->not->toContain('provider-sensitive-body')
            ->not->toContain('provider-sensitive-code')
            ->not->toContain('provider-sensitive-type');
    }
});
