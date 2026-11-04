<?php

namespace App\Data;

final readonly class IngredientInciSuggestionResponse
{
    /** @param list<string> $inciNames */
    public function __construct(
        public array $inciNames,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
