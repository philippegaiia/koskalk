<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Data\IngredientGuidanceLocalizationResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiIngredientGuidanceLocalizationClient implements IngredientGuidanceLocalizationClient
{
    public function __construct(
        private readonly IngredientGuidanceLocalizationPrompt $prompt,
        private readonly IngredientGuidanceLocalizationSchema $schema,
    ) {}

    /** @param array<string, mixed> $context */
    public function localize(array $context): IngredientGuidanceLocalizationResponse
    {
        $apiKey = trim((string) config('ingredient-enrichment.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.missing_api_key'));
        }

        $prompt = $this->prompt->build($context);
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
                }, throw: false)
                ->post($url, [
                    'model' => config('ingredient-enrichment.openai.model'),
                    'reasoning' => ['effort' => config('ingredient-enrichment.openai.reasoning_effort')],
                    'instructions' => $prompt['instructions'],
                    'input' => $prompt['input'],
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'ingredient_guidance_localization',
                            'strict' => true,
                            'schema' => $this->schema->build($context),
                        ],
                    ],
                    'store' => false,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.provider_failed'), previous: $exception);
        }

        if ($response->failed()) {
            throw $this->providerException($response->status(), $response->json(), (string) $response->header('x-request-id'));
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
            $decoded = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'), previous: $exception);
        }
        $translations = is_array($decoded) && is_array($decoded['translations'] ?? null)
            ? $decoded['translations']
            : null;
        if ($translations === null) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        return new IngredientGuidanceLocalizationResponse(
            translations: array_values($translations),
            responseId: (string) ($payload['id'] ?? ''),
            requestId: (string) $response->header('x-request-id'),
            model: (string) ($payload['model'] ?? config('ingredient-enrichment.openai.model')),
            inputTokens: (int) data_get($payload, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($payload, 'usage.output_tokens', 0),
        );
    }

    /** @param array<string, mixed>|null $payload */
    private function providerException(int $status, ?array $payload, string $requestId): IngredientResearchProviderException
    {
        $providerCode = data_get($payload, 'error.code')
            ?? data_get($payload, 'error.type')
            ?? 'unknown_error';
        $providerCode = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) $providerCode) ?: 'unknown_error', 0, 60);
        $safeRequestId = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', $requestId) ?: 'unavailable', 0, 100);

        return new IngredientResearchProviderException(
            failureCode: "provider_http_{$status}_{$providerCode}",
            safeMessage: __('ingredient_enrichment_admin.validation.provider_failed_with_details', [
                'status' => $status,
                'code' => $providerCode,
                'request_id' => $safeRequestId,
            ]),
        );
    }
}
