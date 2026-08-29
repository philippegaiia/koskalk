<?php

namespace App\Data;

use App\Enums\IngredientTranslationOrigin;

readonly class IngredientTranslationWriteIntent
{
    public function __construct(
        public IngredientTranslationOrigin $origin,
        public ?string $promptVersion,
        public bool $refreshMetadata = false,
    ) {}
}
