<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientIdentityNameLocalizationClient;
use App\Data\IngredientIdentityNameLocalizationResponse;
use RuntimeException;

class OpenAiIngredientIdentityNameLocalizationClient implements IngredientIdentityNameLocalizationClient
{
    public function __construct(
        private readonly IngredientIdentityNameLocalizationPrompt $prompt,
        private readonly IngredientIdentityNameLocalizationSchema $schema,
        private readonly OpenAiStructuredOutputTransport $transport,
    ) {}

    public function localize(array $context): IngredientIdentityNameLocalizationResponse
    {
        $prompt = $this->prompt->build($context);
        $response = $this->transport->send(
            instructions: $prompt['instructions'],
            input: $prompt['input'],
            schemaName: 'ingredient_identity_name_localization',
            schema: $this->schema->build($context),
            model: (string) config('ingredient-enrichment.openai.localization_model'),
            reasoningEffort: (string) config('ingredient-enrichment.openai.localization_reasoning_effort'),
        );
        $translations = $response->payload['translations'] ?? null;
        if (! is_array($translations)) {
            throw new RuntimeException(__('ingredient_enrichment_admin.validation.invalid_response'));
        }

        return new IngredientIdentityNameLocalizationResponse(
            translations: array_values($translations),
            responseId: $response->responseId,
            requestId: $response->requestId,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }
}
