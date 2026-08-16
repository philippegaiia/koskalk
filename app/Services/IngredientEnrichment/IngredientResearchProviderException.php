<?php

namespace App\Services\IngredientEnrichment;

use RuntimeException;

class IngredientResearchProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
