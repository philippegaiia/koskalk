<?php

namespace App\Contracts;

use App\Data\IngredientEditorialResponse;

interface IngredientEditorialClient
{
    /** @param array<string, mixed> $facts */
    public function edit(array $facts): IngredientEditorialResponse;
}
