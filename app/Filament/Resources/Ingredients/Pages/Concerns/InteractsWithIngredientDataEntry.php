<?php

namespace App\Filament\Resources\Ingredients\Pages\Concerns;

use App\Models\Ingredient;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientTranslationService;

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
        $translations = app(IngredientTranslationService::class)->validateRows(
            $data['translations'] ?? [],
        );

        $this->ingredientDataEntryState = [
            'current_version' => $data['current_version'] ?? [],
            'cas_number' => $data['cas_number'] ?? data_get($data, 'current_version.cas_number'),
            'ec_number' => $data['ec_number'] ?? data_get($data, 'current_version.ec_number'),
            'additional_identifiers' => $data['additional_identifiers'] ?? [],
            'aliases' => $data['aliases'] ?? [],
            'substance_entries' => $data['substance_entries'] ?? [],
            'sap_profile' => $data['sap_profile'] ?? [],
            'fatty_acid_entries' => $data['fatty_acid_entries'] ?? [],
            'allergen_entries' => $data['allergen_entries'] ?? [],
            'function_ids' => $data['function_ids'] ?? [],
            'components' => $data['components'] ?? [],
            'translations' => $translations,
        ];

        unset(
            $data['current_version'],
            $data['cas_number'],
            $data['ec_number'],
            $data['additional_identifiers'],
            $data['aliases'],
            $data['substance_entries'],
            $data['sap_profile'],
            $data['fatty_acid_entries'],
            $data['allergen_entries'],
            $data['function_ids'],
            $data['components'],
            $data['translations'],
        );

        return $data;
    }

    protected function syncIngredientDataEntryState(Ingredient $ingredient): void
    {
        app(IngredientDataEntryService::class)->syncCurrentData($ingredient, $this->ingredientDataEntryState);
        app(IngredientTranslationService::class)->sync(
            $ingredient,
            $this->ingredientDataEntryState['translations'] ?? [],
        );
        $this->record = $ingredient->fresh();
    }
}
