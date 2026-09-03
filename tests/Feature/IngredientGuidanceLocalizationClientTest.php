<?php

use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Services\IngredientEnrichment\IngredientGuidanceLocalizationPrompt;
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

        return $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-key-never-log')
            && $data['model'] === config('ingredient-enrichment.openai.localization_model')
            && data_get($data, 'reasoning.effort') === config('ingredient-enrichment.openai.localization_reasoning_effort')
            && $data['store'] === false
            && ! array_key_exists('tools', $data)
            && ! array_key_exists('include', $data)
            && data_get($data, 'text.format.type') === 'json_schema'
            && data_get($data, 'text.format.name') === 'ingredient_guidance_localization'
            && data_get($data, 'text.format.strict') === true
            && data_get($data, 'text.format.schema.required') === ['translations']
            && data_get($data, 'text.format.schema.additionalProperties') === false
            && array_keys($properties) === ['translations']
            && array_keys($translationProperties) === ['locale', 'info_markdown'];
    });
});

it('uses the localization-specific model and reasoning effort', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.localization_model', 'gpt-5.6-luna');
    config()->set('ingredient-enrichment.openai.localization_reasoning_effort', 'xhigh');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_localization_settings',
            'status' => 'completed',
            'model' => 'gpt-5.6-luna',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode([
                        'translations' => [[
                            'locale' => 'fr',
                            'info_markdown' => "## Vue d’ensemble\n\nUne présentation.",
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 5],
        ], 200, ['x-request-id' => 'req_localization_settings']),
    ]);

    app(IngredientGuidanceLocalizationClient::class)->localize([
        'locales' => ['fr'],
        'english_guidance' => "## Overview\n\nAn overview.",
    ]);

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'model') === 'gpt-5.6-luna'
        && data_get($request->data(), 'reasoning.effort') === 'xhigh');
});

it('describes localization as an in-context native editorial rewrite', function (): void {
    $prompt = app(IngredientGuidanceLocalizationPrompt::class)->build([]);

    expect($prompt['version'])->toBe('ingredient-guidance-localization-v2')
        ->and($prompt['instructions'])
        ->toContain('in-context')
        ->toContain('native cosmetic-formulation')
        ->toContain('soapmaking terminology')
        ->toContain('never translate literally or sentence by sentence');
});

it('fails safely when the no-web provider connection cannot be established', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'secret-api-key');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::failedConnection(),
    ]);

    try {
        app(IngredientGuidanceLocalizationClient::class)->localize([
            'locales' => ['fr'],
            'english_guidance' => "## Overview\n\nA concise overview.",
        ]);

        $this->fail('The provider connection should have failed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('could not complete')
            ->not->toContain('secret-api-key');
    }

    Http::assertSentCount(3);
});
