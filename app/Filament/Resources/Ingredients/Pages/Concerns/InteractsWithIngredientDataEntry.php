<?php

namespace App\Filament\Resources\Ingredients\Pages\Concerns;

use App\Models\Ingredient;
use App\Services\IngredientDataEntryService;

trait InteractsWithIngredientDataEntry
{
    /**
     * @var array<string, mixed>
     */
    protected array $ingredientDataEntryState = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function extractIngredientDataEntryState(array $data): array
    {
        $this->ingredientDataEntryState = [
            'current_version' => $data['current_version'] ?? [],
            'sap_profile' => $data['sap_profile'] ?? [],
            'fatty_acid_entries' => $data['fatty_acid_entries'] ?? [],
            'allergen_entries' => $data['allergen_entries'] ?? [],
            'function_ids' => $data['function_ids'] ?? [],
            'components' => $data['components'] ?? [],
        ];

        unset(
            $data['current_version'],
            $data['sap_profile'],
            $data['fatty_acid_entries'],
            $data['allergen_entries'],
            $data['function_ids'],
            $data['components'],
        );

        return $data;
    }

    protected function syncIngredientDataEntryState(Ingredient $ingredient): void
    {
        app(IngredientDataEntryService::class)->syncCurrentData($ingredient, $this->ingredientDataEntryState);
        $this->record = $ingredient->fresh();
    }
}
