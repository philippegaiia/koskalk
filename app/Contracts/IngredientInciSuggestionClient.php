<?php

namespace App\Contracts;

use App\Data\IngredientInciSuggestionResponse;

interface IngredientInciSuggestionClient
{
    /** @param array<string, mixed> $record */
    public function suggest(array $record): IngredientInciSuggestionResponse;
}
