<?php

namespace App\Data;

final readonly class HelpTranslationResponse
{
    /** @param array<string, mixed>|null $content */
    public function __construct(
        public ?array $content,
        public ?string $model = null,
        public ?string $responseId = null,
        public ?string $requestId = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $errorCode = null,
    ) {}
}
