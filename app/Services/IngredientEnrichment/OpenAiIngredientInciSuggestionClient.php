<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientInciSuggestionClient;
use App\Data\IngredientInciSuggestionResponse;
use RuntimeException;

class OpenAiIngredientInciSuggestionClient implements IngredientInciSuggestionClient
{
    public function __construct(
        private readonly IngredientInciSuggestionPrompt $prompt,
        private readonly OpenAiStructuredOutputTransport $transport,
    ) {}

    public function suggest(array $record): IngredientInciSuggestionResponse
    {
        $prompt = $this->prompt->build($record);
        $response = $this->transport->send(
            instructions: $prompt['instructions'],
            input: $prompt['input'],
            schemaName: 'ingredient_inci_suggestion',
            schema: $prompt['schema'],
            model: (string) config('ingredient-enrichment.openai.model'),
            reasoningEffort: (string) config('ingredient-enrichment.openai.inci_suggestion_reasoning_effort'),
        );
        $names = $response->payload['inci_names'] ?? null;
        if (! is_array($names)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        return new IngredientInciSuggestionResponse(
            inciNames: collect($names)
                ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                ->map(fn (string $name): string => mb_strtoupper(trim($name)))
                ->unique()
                ->take(IngredientInciSuggestionPrompt::MAXIMUM_SUGGESTIONS)
                ->values()
                ->all(),
            responseId: $response->responseId,
            requestId: $response->requestId,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }
}
