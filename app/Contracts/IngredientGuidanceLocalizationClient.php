<?php

namespace App\Contracts;

use App\Data\IngredientGuidanceLocalizationResponse;

interface IngredientGuidanceLocalizationClient
{
    /** @param array<string, mixed> $context */
    public function localize(array $context): IngredientGuidanceLocalizationResponse;
}
