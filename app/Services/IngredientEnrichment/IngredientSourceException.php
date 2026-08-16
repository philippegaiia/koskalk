<?php

namespace App\Services\IngredientEnrichment;

use RuntimeException;

class IngredientSourceException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly ?int $status = null,
    ) {
        parent::__construct(__('ingredient_enrichment.validation.source_unavailable'));
    }
}
