<?php

namespace App\Data;

final readonly class IngredientEditorialResponse
{
    /**
     * @param  array<string, mixed>  $editorial
     */
    public function __construct(
        public array $editorial,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $webSearchCalls = 0,
    ) {}
}
