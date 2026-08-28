<?php

namespace App\Services\IngredientEnrichment;

use App\Contracts\IngredientGuidanceAuthoringClient;
use App\Data\IngredientGuidanceAuthoringResponse;

class OpenAiIngredientGuidanceClient implements IngredientGuidanceAuthoringClient
{
    public function __construct(
        private readonly IngredientGuidancePrompt $prompt,
        private readonly IngredientGuidanceSchema $schema,
        private readonly OpenAiStructuredOutputTransport $transport,
    ) {}

    /** @param array<string, mixed> $context */
    public function author(array $context): IngredientGuidanceAuthoringResponse
    {
        $prompt = $this->prompt->build($context);
        $response = $this->transport->send(
            instructions: $prompt['instructions'],
            input: $prompt['input'],
            schemaName: 'ingredient_guidance',
            schema: $this->schema->build(),
        );

        return new IngredientGuidanceAuthoringResponse(
            guidance: $response->payload,
            responseId: $response->responseId,
            requestId: $response->requestId,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
        );
    }
}
