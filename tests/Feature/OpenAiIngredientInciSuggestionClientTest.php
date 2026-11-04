<?php

use App\Contracts\IngredientInciSuggestionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('returns at most three distinct upper-case INCI suggestions at a low reasoning effort', function (): void {
    config()->set('ingredient-enrichment.openai.api_key', 'test-key-never-log');
    config()->set('ingredient-enrichment.openai.model', 'gpt-test');
    config()->set('ingredient-enrichment.openai.inci_suggestion_reasoning_effort', 'low');
    Http::preventStrayRequests();
    Http::fake([
        'api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_inci_suggestion',
            'status' => 'completed',
            'model' => 'gpt-test',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['inci_names' => [
                        'Maranta Arundinacea Root Powder',
                        'MARANTA ARUNDINACEA ROOT POWDER',
                        ' ',
                        'MARANTA ARUNDINACEA ROOT EXTRACT',
                        'MANIHOT ESCULENTA ROOT POWDER',
                        'ZEA MAYS STARCH',
                    ]], JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 362, 'output_tokens' => 27],
        ], 200, ['x-request-id' => 'req_inci_suggestion']),
    ]);

    $response = app(IngredientInciSuggestionClient::class)->suggest([
        'display_name' => 'arrow root powder',
        'inci_name' => null,
        'research_family' => 'botanicals',
    ]);

    expect($response->inciNames)->toBe([
        'MARANTA ARUNDINACEA ROOT POWDER',
        'MARANTA ARUNDINACEA ROOT EXTRACT',
        'MANIHOT ESCULENTA ROOT POWDER',
    ])
        ->and($response->inputTokens)->toBe(362)
        ->and($response->outputTokens)->toBe(27);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'reasoning.effort') === 'low'
        && str_contains((string) data_get($request->data(), 'input'), 'arrow root powder'));
});
