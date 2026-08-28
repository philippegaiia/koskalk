<?php

namespace App\Data;

final readonly class OpenAiStructuredOutputResponse
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $totalTokens,
    ) {}
}
