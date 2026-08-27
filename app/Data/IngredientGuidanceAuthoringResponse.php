<?php

namespace App\Data;

final readonly class IngredientGuidanceAuthoringResponse
{
    /**
     * @param  array<string, mixed>  $guidance
     */
    public function __construct(
        public array $guidance,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
