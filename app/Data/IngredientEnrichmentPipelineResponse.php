<?php

namespace App\Data;

final readonly class IngredientEnrichmentPipelineResponse
{
    /**
     * @param  array<string, mixed>  $result
     * @param  list<array{url: string, title: string}>  $sources
     */
    public function __construct(
        public array $result,
        public array $sources,
        public string $providerResponseId,
        public string $providerRequestId,
        public string $providerModel,
        public int $inputTokens,
        public int $outputTokens,
        public int $webSearchCalls,
        public int $structuredSourceCalls,
    ) {}
}
