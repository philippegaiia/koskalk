<?php

namespace App\Contracts;

use App\Data\IngredientGuidanceAuthoringResponse;

interface IngredientGuidanceAuthoringClient
{
    /** @param array<string, mixed> $context */
    public function author(array $context): IngredientGuidanceAuthoringResponse;
}
