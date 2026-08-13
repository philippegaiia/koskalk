<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientResearchClient;
use App\Data\IngredientResearchResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiIngredientResearchClient implements IngredientResearchClient
{
    public function __construct(
        private readonly IngredientEnrichmentResearchPrompt $prompt,
        private readonly IngredientEnrichmentResultSchema $schema,
    ) {}

    public function research(array $record): IngredientResearchResponse
    {
        $apiKey = trim((string) config('ingredient-enrichment.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.missing_api_key'));
        }

        $prompt = $this->prompt->build($record, now()->toImmutable());
        $url = rtrim((string) config('ingredient-enrichment.openai.base_url'), '/').'/responses';

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->connectTimeout((int) config('ingredient-enrichment.openai.connect_timeout_seconds'))
                ->timeout((int) config('ingredient-enrichment.openai.timeout_seconds'))
                ->retry([250, 1000], when: function (Throwable $exception, PendingRequest $request): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && ($exception->response->status() === 429 || $exception->response->serverError()));
                })
                ->post($url, [
                    'model' => config('ingredient-enrichment.openai.model'),
                    'reasoning' => ['effort' => config('ingredient-enrichment.openai.reasoning_effort')],
                    'instructions' => $prompt['instructions'],
                    'input' => $prompt['input'],
                    'tools' => [[
                        'type' => 'web_search',
                        'filters' => [
                            'allowed_domains' => config('ingredient-enrichment.openai.allowed_domains'),
                        ],
                    ]],
                    'include' => ['web_search_call.action.sources'],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'ingredient_enrichment_result',
                            'strict' => true,
                            'schema' => $this->schema->build($record),
                        ],
                    ],
                    'store' => false,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.provider_failed'), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.provider_failed'));
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $outputText = collect($payload['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $output): array => is_array($output['content'] ?? null) ? $output['content'] : [])
            ->firstWhere('type', 'output_text')['text'] ?? null;

        if (! is_string($outputText)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        try {
            $result = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'), previous: $exception);
        }

        if (! is_array($result)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $searchCalls = collect($payload['output'] ?? [])->where('type', 'web_search_call');
        $sources = $searchCalls
            ->flatMap(fn (array $output): array => is_array(data_get($output, 'action.sources')) ? data_get($output, 'action.sources') : [])
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['url'] ?? null))
            ->map(fn (array $source): array => [
                'url' => trim($source['url']),
                'title' => trim((string) ($source['title'] ?? $source['url'])),
            ])
            ->unique('url')
            ->values()
            ->all();

        return new IngredientResearchResponse(
            result: $result,
            responseId: (string) ($payload['id'] ?? ''),
            requestId: (string) $response->header('x-request-id'),
            model: (string) ($payload['model'] ?? config('ingredient-enrichment.openai.model')),
            inputTokens: (int) data_get($payload, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($payload, 'usage.output_tokens', 0),
            webSearchCalls: $searchCalls->count(),
            sources: $sources,
        );
    }
}
