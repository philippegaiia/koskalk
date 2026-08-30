<?php

namespace App\Contracts;

use App\Data\IngredientGapResearchResponse;

interface IngredientGuidanceResearchClient
{
    /** @param array<string, mixed> $facts */
    public function research(array $facts): IngredientGapResearchResponse;
}
