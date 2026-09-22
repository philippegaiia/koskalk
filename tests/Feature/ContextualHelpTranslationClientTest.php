<?php

use App\Contracts\HelpTranslationClient;
use App\Data\HelpContentInput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('sends only authored English content and locale with strict output schema and no storage', function () {
    Http::preventStrayRequests();
    config(['ingredient-enrichment.openai.api_key' => 'fake-key', 'ingredient-enrichment.openai.base_url' => 'https://api.openai.com/v1']);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response([
        'id' => 'resp_test', 'model' => 'returned-model', 'status' => 'completed',
        'usage' => ['input_tokens' => 9, 'output_tokens' => 4],
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"title":"Formule","summary":"Ne pas modifier 5%.","body_markdown":null}']]]],
    ], 200, ['x-request-id' => 'req_test'])]);
    $response = app(HelpTranslationClient::class)->translate(new HelpContentInput('Formula', 'Do not change 5%.', null), 'fr', 'chosen-model', 'medium', 'help-translation-v1');
    expect($response->content['title'])->toBe('Formule');
    expect($response->inputTokens)->toBe(9);
    expect($response->model)->toBe('returned-model');
    expect($response->requestId)->toBe('req_test');
    Http::assertSent(function (Request $request): bool {
        $input = json_decode($request['input'], true);

        return $request['store'] === false && $request['model'] === 'chosen-model' && $request['reasoning']['effort'] === 'medium'
            && array_keys($input) === ['target_locale', 'source'] && $input['source'] === ['title' => 'Formula', 'summary' => 'Do not change 5%.', 'body_markdown' => null]
            && $request['text']['format']['schema']['additionalProperties'] === false && $request['text']['format']['strict'] === true;
    });
    Http::assertSentCount(1);
});

it('preserves metadata on malformed output without leaking response error bodies', function () {
    Http::preventStrayRequests();
    config(['ingredient-enrichment.openai.api_key' => 'fake-key', 'ingredient-enrichment.openai.base_url' => 'https://api.openai.com/v1']);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(['id' => 'resp_bad', 'model' => 'actual-model', 'status' => 'failed', 'usage' => ['input_tokens' => 12], 'error' => ['message' => 'secret-key source body']], 500, ['x-request-id' => 'req_bad'])]);
    $response = app(HelpTranslationClient::class)->translate(new HelpContentInput('Help', 'Published text.', null), 'fr', 'model', 'medium', 'help-translation-v1');
    expect($response->content)->toBeNull();
    expect($response->errorCode)->toBe('provider_http_500');
    expect($response->inputTokens)->toBe(12);
    expect($response->outputTokens)->toBeNull();
    expect($response->responseId)->toBe('resp_bad');
    Http::assertSentCount(1);
});

it('does not retry connection failures', function () {
    Http::preventStrayRequests();
    config(['ingredient-enrichment.openai.api_key' => 'fake-key', 'ingredient-enrichment.openai.base_url' => 'https://api.openai.com/v1']);
    $calls = 0;
    Http::fake(['https://api.openai.com/v1/responses' => function () use (&$calls) {
        $calls++;
        throw new ConnectionException('private provider detail');
    }]);
    $response = app(HelpTranslationClient::class)->translate(new HelpContentInput('Help', 'Published text.', null), 'fr', 'model', 'medium', 'help-translation-v1');
    expect($response->errorCode)->toBe('provider_connection_failed');
    expect($calls)->toBe(1);
    Http::assertNothingSent();
});
