<?php

namespace App\Contracts;

use App\Data\IngredientResearchResponse;

interface IngredientResearchClient
{
    /** @param array<string, mixed> $record */
    public function research(array $record): IngredientResearchResponse;
}
