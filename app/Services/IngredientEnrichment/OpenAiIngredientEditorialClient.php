<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientEditorialClient;
use App\Data\IngredientEditorialResponse;

class OpenAiIngredientEditorialClient implements IngredientEditorialClient
{
    public function __construct(
        private readonly IngredientEnrichmentEditorialPrompt $prompt,
        private readonly IngredientEnrichmentEditorialSchema $schema,
        private readonly OpenAiStructuredOutputTransport $transport,
    ) {}

    /** @param array<string, mixed> $facts */
    public function edit(array $facts): IngredientEditorialResponse
    {
        $prompt = $this->prompt->build($facts);
        $response = $this->transport->send(
            instructions: $prompt['instructions'],
            input: $prompt['input'],
            schemaName: 'ingredient_enrichment_editorial',
            schema: $this->schema->build($facts),
        );

        return new IngredientEditorialResponse(
            editorial: $response->payload,
            responseId: $response->responseId,
            requestId: $response->requestId,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }
}
