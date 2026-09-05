<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceLocalizationClient;
use App\Data\IngredientGuidanceLocalizationResponse;
use RuntimeException;

class OpenAiIngredientGuidanceLocalizationClient implements IngredientGuidanceLocalizationClient
{
    public function __construct(
        private readonly IngredientGuidanceLocalizationPrompt $prompt,
        private readonly IngredientGuidanceLocalizationSchema $schema,
        private readonly OpenAiStructuredOutputTransport $transport,
    ) {}

    /** @param array<string, mixed> $context */
    public function localize(array $context): IngredientGuidanceLocalizationResponse
    {
        $prompt = $this->prompt->build($context);
        $response = $this->transport->send(
            instructions: $prompt['instructions'],
            input: $prompt['input'],
            schemaName: 'ingredient_guidance_localization',
            schema: $this->schema->build($context),
            model: (string) config('ingredient-enrichment.openai.localization_model'),
            reasoningEffort: (string) config('ingredient-enrichment.openai.localization_reasoning_effort'),
        );
        $translations = is_array($response->payload['translations'] ?? null)
            ? $response->payload['translations']
            : null;
        if ($translations === null) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        return new IngredientGuidanceLocalizationResponse(
            translations: array_values($translations),
            responseId: $response->responseId,
            requestId: $response->requestId,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }
}
