<?php

namespace App\Data;

final readonly class IngredientIdentityNameLocalizationResponse
{
    /** @param list<array{locale:string,display_name:string,saponification_name:string|null}> $translations */
    public function __construct(
        public array $translations,
        public string $responseId,
        public string $requestId,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
