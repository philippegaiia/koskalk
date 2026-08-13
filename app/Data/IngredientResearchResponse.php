<?php

namespace App\Data;

final readonly class IngredientResearchResponse
{
    /**
     * @param  array<string, mixed>  $result
     * @param  list<array{url: string, title: string}>  $sources
     */
    public function __construct(
        public array $result,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $webSearchCalls,
        public array $sources,
    ) {}
}
