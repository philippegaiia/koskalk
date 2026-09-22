<?php

namespace App\Services\ContextualHelp;

use App\Contracts\HelpTranslationClient;
use App\Data\HelpContentInput;
use App\Data\HelpTranslationResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

final class OpenAiHelpTranslationClient implements HelpTranslationClient
{
    public function __construct(private readonly HelpTranslationPrompt $prompt) {}

    public function translate(HelpContentInput $source, string $locale, string $model, string $reasoningEffort, string $promptVersion): HelpTranslationResponse
    {
        $prompt = $this->prompt->build($source, $locale, $promptVersion);
        $key = trim((string) config('ingredient-enrichment.openai.api_key'));
        if ($key === '') {
            return new HelpTranslationResponse(null, errorCode: 'missing_api_key');
        }
        try {
            $response = Http::acceptJson()->asJson()->withToken($key)->connectTimeout(10)->timeout(90)
                ->post(rtrim((string) config('ingredient-enrichment.openai.base_url'), '/').'/responses', [
                    'model' => $model,
                    'reasoning' => ['effort' => $reasoningEffort],
                    ...$prompt,
                    'store' => false,
                    'text' => ['format' => ['type' => 'json_schema', 'name' => 'help_translation', 'strict' => true, 'schema' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['title', 'summary', 'body_markdown'],
                        'properties' => ['title' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'body_markdown' => ['type' => ['string', 'null']]],
                    ]]],
                ]);
        } catch (Throwable) {
            return new HelpTranslationResponse(null, errorCode: 'provider_connection_failed');
        }
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $content = collect(is_array($payload['output'] ?? null) ? $payload['output'] : [])
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'message')
            ->flatMap(fn (array $item): array => is_array($item['content'] ?? null) ? $item['content'] : [])
            ->first(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'output_text');
        $decoded = is_string($content['text'] ?? null) ? json_decode($content['text'], true) : null;
        $error = $response->failed() ? 'provider_http_'.$response->status() : (($payload['status'] ?? null) !== 'completed' || ! is_array($decoded) ? 'invalid_response' : null);

        return new HelpTranslationResponse(
            content: is_array($decoded) ? $decoded : null,
            model: $this->identifier($payload['model'] ?? null, 160),
            responseId: $this->identifier($payload['id'] ?? null),
            requestId: $this->identifier($response->header('x-request-id')),
            inputTokens: $this->tokens(data_get($payload, 'usage.input_tokens')),
            outputTokens: $this->tokens(data_get($payload, 'usage.output_tokens')),
            errorCode: $error,
        );
    }

    private function identifier(mixed $value, int $maxLength = 255): ?string
    {
        return is_string($value) && strlen($value) <= $maxLength && preg_match('/^[a-zA-Z0-9._:-]{1,255}$/D', $value) ? $value : null;
    }

    private function tokens(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
