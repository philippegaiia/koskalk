<?php

namespace Database\Seeders;

use App\Enums\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use JsonException;
use RuntimeException;

class CarrierOilChemistrySeeder extends Seeder
{
    public function run(): void
    {
        $path = (string) config('catalog-imports.carrier_oil_chemistry.path');
        $rows = $this->rows($path);

        if ($rows === []) {
            return;
        }

        $ingredientsByCatalogKey = Ingredient::query()
            ->whereIn('catalog_key', array_keys($rows))
            ->get()
            ->keyBy('catalog_key');

        $fattyAcidIdsByKey = FattyAcid::query()->pluck('id', 'key');

        foreach ($rows as $catalogKey => $row) {
            $ingredient = $ingredientsByCatalogKey->get($catalogKey);

            if (! $ingredient instanceof Ingredient) {
                throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] does not match any existing ingredient.");
            }

            if ($ingredient->category !== IngredientCategory::CarrierOil) {
                throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] points to [{$ingredient->display_name}], which is not a carrier oil.");
            }

            $sourceNotes = $this->nullableString($row, 'source_notes');
            $fattyAcids = $this->normalizeFattyAcids($row['fatty_acids'] ?? null, $catalogKey, $fattyAcidIdsByKey);

            $this->syncSapProfile(
                ingredient: $ingredient,
                kohSapValue: $this->nullableFloat($row, 'koh_sap_value', $catalogKey),
                iodineValue: $this->nullableFloat($row, 'iodine_value', $catalogKey),
                insValue: $this->nullableFloat($row, 'ins_value', $catalogKey),
                sourceNotes: $sourceNotes,
                fattyAcids: $fattyAcids,
            );

            $this->syncFattyAcids($ingredient, $fattyAcids, $sourceNotes);

            if (! $ingredient->is_potentially_saponifiable) {
                $ingredient->forceFill([
                    'is_potentially_saponifiable' => true,
                ])->save();
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to read carrier oil chemistry JSON at [{$path}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to open carrier oil chemistry JSON at [{$path}].");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Carrier oil chemistry JSON at [{$path}] is invalid: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Carrier oil chemistry JSON at [{$path}] must contain a top-level array of rows.");
        }

        $rowsByCatalogKey = [];

        foreach ($decoded as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Carrier oil chemistry row [{$index}] must be an object.");
            }

            $catalogKey = $this->requiredString($row, 'catalog_key', "row {$index}");

            if (array_key_exists($catalogKey, $rowsByCatalogKey)) {
                throw new RuntimeException("Carrier oil chemistry JSON contains duplicate catalog_key [{$catalogKey}].");
            }

            $rowsByCatalogKey[$catalogKey] = $row;
        }

        return $rowsByCatalogKey;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function requiredString(array $row, string $key, string $context): string
    {
        $value = $this->nullableString($row, $key);

        if ($value === null) {
            throw new RuntimeException("Carrier oil chemistry {$context} is missing required [{$key}].");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new RuntimeException("Carrier oil chemistry field [{$key}] must be a string-compatible scalar.");
        }

        $trimmedValue = trim((string) $value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function nullableFloat(array $row, string $key, string $catalogKey): ?float
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] has a non-numeric [{$key}] value.");
        }

        return round((float) $value, 5);
    }

    /**
     * @param  Collection<int|string, int|string>  $fattyAcidIdsByKey
     * @return array<int, array{fatty_acid_id:int, percentage:float}>
     */
    private function normalizeFattyAcids(mixed $fattyAcids, string $catalogKey, Collection $fattyAcidIdsByKey): array
    {
        if (! is_array($fattyAcids)) {
            throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] must include a fatty_acids object.");
        }

        return collect($fattyAcids)
            ->filter(fn (mixed $percentage): bool => $percentage !== null && $percentage !== '')
            ->map(function (mixed $percentage, mixed $fattyAcidKey) use ($catalogKey, $fattyAcidIdsByKey): array {
                $normalizedKey = trim((string) $fattyAcidKey);

                if ($normalizedKey === '') {
                    throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] contains an empty fatty acid key.");
                }

                $fattyAcidId = $fattyAcidIdsByKey->get($normalizedKey);

                if ($fattyAcidId === null) {
                    throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] references unknown fatty acid [{$normalizedKey}].");
                }

                if (! is_numeric($percentage)) {
                    throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] has a non-numeric percentage for fatty acid [{$normalizedKey}].");
                }

                $normalizedPercentage = round((float) $percentage, 5);

                if ($normalizedPercentage < 0 || $normalizedPercentage > 100) {
                    throw new RuntimeException("Carrier oil chemistry row [{$catalogKey}] has an out-of-range percentage for fatty acid [{$normalizedKey}].");
                }

                return [
                    'fatty_acid_id' => (int) $fattyAcidId,
                    'percentage' => $normalizedPercentage,
                ];
            })
            ->sortBy('fatty_acid_id')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{fatty_acid_id:int, percentage:float}>  $fattyAcids
     */
    private function syncSapProfile(
        Ingredient $ingredient,
        ?float $kohSapValue,
        ?float $iodineValue,
        ?float $insValue,
        ?string $sourceNotes,
        array $fattyAcids,
    ): void {
        $hasChemistryState = $kohSapValue !== null
            || $iodineValue !== null
            || $insValue !== null
            || $sourceNotes !== null
            || $fattyAcids !== [];

        if (! $hasChemistryState) {
            $ingredient->sapProfile()->delete();

            return;
        }

        IngredientSapProfile::query()->updateOrCreate(
            ['ingredient_id' => $ingredient->id],
            [
                'koh_sap_value' => $kohSapValue,
                'iodine_value' => $iodineValue,
                'ins_value' => $insValue,
                'source_notes' => $sourceNotes,
            ],
        );
    }

    /**
     * @param  array<int, array{fatty_acid_id:int, percentage:float}>  $fattyAcids
     */
    private function syncFattyAcids(Ingredient $ingredient, array $fattyAcids, ?string $sourceNotes): void
    {
        IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        foreach ($fattyAcids as $fattyAcid) {
            IngredientFattyAcid::query()->create([
                'ingredient_id' => $ingredient->id,
                'fatty_acid_id' => $fattyAcid['fatty_acid_id'],
                'percentage' => $fattyAcid['percentage'],
                'source_notes' => $sourceNotes,
                'source_data' => null,
            ]);
        }
    }
}
