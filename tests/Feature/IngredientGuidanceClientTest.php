<?php

use App\Contracts\IngredientGuidanceAuthoringClient;
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

        return ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
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
