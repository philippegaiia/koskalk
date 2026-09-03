<?php

namespace App\Contracts;

use App\Data\IngredientIdentityNameLocalizationResponse;

interface IngredientIdentityNameLocalizationClient
{
    /** @param array<string, mixed> $context */
    public function localize(array $context): IngredientIdentityNameLocalizationResponse;
}
