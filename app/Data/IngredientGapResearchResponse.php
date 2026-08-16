<?php

namespace App\Data;

final readonly class IngredientGapResearchResponse
{
    /**
     * @param  list<array<string, mixed>>  $candidateEvidence
     * @param  list<string>  $warnings
     * @param  list<string>  $unresolvedQuestions
     * @param  list<array{url: string, title: string}>  $sources
     */
    public function __construct(
        public array $candidateEvidence,
        public array $warnings,
        public array $unresolvedQuestions,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $webSearchCalls,
        public array $sources,
    ) {}
}
