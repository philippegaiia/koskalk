<?php

namespace App\Services\IngredientEnrichment;

use App\Data\OpenAiStructuredOutputResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiStructuredOutputTransport
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function send(
        string $instructions,
        string $input,
        string $schemaName,
        array $schema,
        ?string $model = null,
        ?string $reasoningEffort = null,
    ): OpenAiStructuredOutputResponse {
        $apiKey = trim((string) config('ingredient-enrichment.openai.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.missing_api_key'));
        }

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
                    'model' => $model ?? config('ingredient-enrichment.openai.model'),
                    'reasoning' => ['effort' => $reasoningEffort ?? config('ingredient-enrichment.openai.reasoning_effort')],
                    'instructions' => $instructions,
                    'input' => $input,
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => $schemaName,
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                    'store' => false,
                ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.provider_failed'), previous: $exception);
        }

        if ($response->failed()) {
            $errorPayload = $response->json();

            throw $this->providerException(
                $response->status(),
                is_array($errorPayload) ? $errorPayload : null,
                $this->nonEmptyScalarString($response->header('x-request-id')) ?? '',
            );
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'completed') {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $responseId = $this->nonEmptyScalarString($payload['id'] ?? null);
        $requestId = $this->nonEmptyScalarString($response->header('x-request-id'));
        $model = $this->nonEmptyScalarString($payload['model'] ?? null);
        $usage = $payload['usage'] ?? null;

        if ($responseId === null || $requestId === null || $model === null
            || ! is_array($usage)
            || ! array_key_exists('input_tokens', $usage)
            || ! array_key_exists('output_tokens', $usage)
            || ! is_int($usage['input_tokens'])
            || $usage['input_tokens'] < 0
            || ! is_int($usage['output_tokens'])
            || $usage['output_tokens'] < 0
        ) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $outputText = collect($payload['output'] ?? [])
            ->filter(fn (mixed $output): bool => is_array($output) && ($output['type'] ?? null) === 'message')
            ->flatMap(fn (mixed $output): array => is_array($output['content'] ?? null) ? $output['content'] : [])
            ->first(fn (mixed $content): bool => is_array($content) && ($content['type'] ?? null) === 'output_text');
        $outputText = is_array($outputText) && is_string($outputText['text'] ?? null)
            ? $outputText['text']
            : null;

        if (! is_string($outputText)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        try {
            $decoded = json_decode($outputText, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        $inputTokens = $usage['input_tokens'];
        $outputTokens = $usage['output_tokens'];
        $totalTokens = $inputTokens + $outputTokens;
        if (array_key_exists('total_tokens', $usage)) {
            if (! is_int($usage['total_tokens']) || $usage['total_tokens'] < 0) {
                throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
            }

            $totalTokens = $usage['total_tokens'];
        }

        return new OpenAiStructuredOutputResponse(
            payload: $decoded,
            responseId: $responseId,
            requestId: $requestId,
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
        );
    }

    private function nonEmptyScalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function providerException(int $status, ?array $payload, string $requestId): IngredientResearchProviderException
    {
        $providerCode = data_get($payload, 'error.code')
            ?? data_get($payload, 'error.type')
            ?? 'unknown_error';
        $providerCode = is_scalar($providerCode) ? (string) $providerCode : 'unknown_error';
        $providerCode = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', $providerCode) ?: 'unknown_error', 0, 60);
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
