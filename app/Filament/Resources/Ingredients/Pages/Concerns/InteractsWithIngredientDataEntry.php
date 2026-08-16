<?php

namespace App\Filament\Resources\Ingredients\Pages\Concerns;

use App\Models\Ingredient;
use App\Models\User;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientMarketLabelService;
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
            'reviewed_function_ids' => $data['reviewed_function_ids'] ?? [],
            'components' => $data['components'] ?? [],
            'ifra' => $data['ifra'] ?? [],
            'market_labels' => $this->marketLabelRows($data['market_labels'] ?? []),
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
            $data['reviewed_function_ids'],
            $data['components'],
            $data['ifra'],
            $data['market_labels'],
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
        $actor = auth()->user();
        if ($actor instanceof User) {
            app(IngredientMarketLabelService::class)->replaceReviewed(
                $ingredient,
                $this->ingredientDataEntryState['market_labels'] ?? [],
                $actor,
            );
        }
        $this->record = $ingredient->fresh();
    }

    /** @return array<string, array<string, mixed>> */
    protected function marketLabelFormData(Ingredient $ingredient): array
    {
        $empty = fn (string $market): array => [
            'market_code' => $market,
            'declaration_name' => null,
            'source_name' => null,
            'source_url' => null,
            'effective_from' => null,
            'effective_until' => null,
            'reviewed_at' => null,
            'source_tier' => null,
            'confidence' => null,
            'source_version' => null,
            'source_updated_at' => null,
            'retrieved_at' => null,
        ];

        return collect(['eu', 'us'])
            ->mapWithKeys(fn (string $market): array => [$market => $empty($market)])
            ->replace(collect(app(IngredientMarketLabelService::class)->formData($ingredient))
                ->mapWithKeys(fn (array $row): array => [(string) $row['market_code'] => $row]))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketLabelRows(mixed $state): array
    {
        return collect(is_array($state) ? $state : [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['declaration_name'] ?? null))
            ->map(fn (array $row, string|int $market): array => [
                ...$row,
                'market_code' => is_string($market) ? $market : ($row['market_code'] ?? null),
            ])
            ->values()
            ->all();
    }
}
