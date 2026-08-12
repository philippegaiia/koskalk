<?php

namespace App\Data;

final readonly class IngredientClassificationPromptInput
{
    public function __construct(
        public ?string $name,
        public ?string $inciName,
        public ?string $casNumber,
        public ?string $ecNumber,
        public ?string $supplierNotes,
        public string $responseLocale,
        /** @var array<int, array<string, mixed>> */
        public array $additionalIdentifiers = [],
    ) {}
}
