<?php

use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Services\IngredientEnrichment\OpenAiStructuredOutputTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('authors concise guidance with a strict no-web response contract', function (): void {
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
                        'info_markdown' => "## Overview\n\nA concise overview.\n\n## Formulation use\n\nA material-specific use.",
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
        'guidance_evidence' => [],
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
            && $data['model'] === 'gpt-5.6-terra'
            && $data['store'] === false
            && ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && data_get($data, 'text.format.type') === 'json_schema'
            && data_get($data, 'text.format.name') === 'ingredient_guidance'
            && data_get($data, 'text.format.strict') === true
            && data_get($data, 'text.format.schema.required') === ['info_markdown', 'warnings', 'unresolved_questions']
            && data_get($data, 'text.format.schema.additionalProperties') === false
            && array_keys($properties) === ['info_markdown', 'warnings', 'unresolved_questions']
            && str_contains((string) $data['instructions'], 'Do not repeat the INCI')
            && str_contains((string) $data['instructions'], 'material-specific formulation decision')
            && str_contains((string) $data['instructions'], '80 to 160 words')
            && str_contains((string) $data['instructions'], 'Typical use level')
            && str_contains((string) $data['instructions'], 'approved structured usage fact')
            && str_contains((string) $data['instructions'], 'not a regulatory or safety limit')
            && str_contains((string) $data['instructions'], 'omit generic filler');
    });
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
            && $data['model'] === 'gpt-5.6-terra'
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
