<?php

use App\Contracts\IngredientGuidanceLocalizationClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('localizes approved guidance with a locale-bounded strict response contract', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_localization_123',
            'status' => 'completed',
            'model' => 'gpt-5.6-terra-2026-08-01',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'translations' => [[
                            'locale' => 'fr',
                            'info_markdown' => "## Vue d’ensemble\n\nUne présentation concise.",
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 31, 'output_tokens' => 17],
        ], 200, ['x-request-id' => 'req_localization_456']),
    ]);

    $response = app(IngredientGuidanceLocalizationClient::class)->localize([
        'locales' => ['fr'],
        'english_guidance' => "## Overview\n\nA concise overview.",
    ]);

    expect($response->translations)->toBe([
        ['locale' => 'fr', 'info_markdown' => "## Vue d’ensemble\n\nUne présentation concise."],
    ])
        ->and($response->responseId)->toBe('resp_localization_123')
        ->and($response->requestId)->toBe('req_localization_456')
        ->and($response->inputTokens)->toBe(31)
        ->and($response->outputTokens)->toBe(17);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $properties = data_get($data, 'text.format.schema.properties', []);
        $translationProperties = data_get($properties, 'translations.items.properties', []);

        return ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && array_keys($properties) === ['translations']
            && array_keys($translationProperties) === ['locale', 'info_markdown'];
    });
});
