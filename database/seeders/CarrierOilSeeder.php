<?php

namespace Database\Seeders;

use App\Data\InciNameLookup;
use App\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class CarrierOilSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/mendrulandia_oils.json');
        $rows = $this->rows($path);

        if ($rows === []) {
            return;
        }

        $fattyAcidIdsByKey = FattyAcid::query()->pluck('id', 'key')->all();
        $count = 0;

        foreach ($rows as $row) {
            $sourceKey = $row['source_key'];
            $displayName = $this->toDisplayName($sourceKey);

            $this->command->info("Seeding {$displayName}...");

            $ingredient = Ingredient::query()->updateOrCreate(
                [
                    'source_file' => 'mendrulandia_oils',
                    'source_key' => $sourceKey,
                ],
                [
                    'category' => IngredientCategory::CarrierOil,
                    'display_name' => $displayName,
                    'inci_name' => InciNameLookup::find($displayName),
                    'is_potentially_saponifiable' => true,
                ]
            );

            $fattyAcids = $this->normalizeFattyAcids($row['fatty_acids'] ?? [], $sourceKey, $fattyAcidIdsByKey);
            $this->syncFattyAcids($ingredient, $fattyAcids);

            $this->syncSapProfile(
                ingredient: $ingredient,
                kohSapValue: $row['koh_sap_value'],
                iodineValue: $row['iodine_value'],
                insValue: $row['ins_value'],
            );

            $count++;
        }

        $this->command->info("Seeded {$count} oils.");
    }

    /**
     * @return array<int, array{source_key: string, fatty_acids: array<string, float>, koh_sap_value: ?float, iodine_value: ?float, ins_value: ?float}>
     */
    private function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to read mendrulandia oils JSON at [{$path}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to open mendrulandia oils JSON at [{$path}].");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Mendrulandia oils JSON at [{$path}] is invalid: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException("Mendrulandia oils JSON at [{$path}] must contain a top-level array.");
        }

        $result = [];

        foreach ($decoded as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Mendrulandia oils row [{$index}] must be an object.");
            }

            $sourceKey = $row['source_key'] ?? null;

            if (! is_string($sourceKey) || $sourceKey === '') {
                throw new RuntimeException("Mendrulandia oils row [{$index}] is missing a valid source_key.");
            }

            $result[] = [
                'source_key' => $sourceKey,
                'fatty_acids' => is_array($row['fatty_acids'] ?? null) ? $row['fatty_acids'] : [],
                'koh_sap_value' => $this->nullableFloat($row['koh_sap_value'] ?? null),
                'iodine_value' => $this->nullableFloat($row['iodine_value'] ?? null),
                'ins_value' => $this->nullableFloat($row['ins_value'] ?? null),
            ];
        }

        return $result;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 5);
    }

    private function toDisplayName(string $slug): string
    {
        return Str::title(str_replace('_', ' ', $slug));
    }

    /**
     * @param  array<string, float>  $fattyAcids
     * @param  array<string, int>  $fattyAcidIdsByKey
     * @return array<int, array{fatty_acid_id: int, percentage: float}>
     */
    private function normalizeFattyAcids(array $fattyAcids, string $sourceKey, array $fattyAcidIdsByKey): array
    {
        return collect($fattyAcids)
            ->filter(fn (mixed $percentage): bool => $percentage !== null && $percentage !== '')
            ->map(function (mixed $percentage, mixed $fattyAcidKey) use ($sourceKey, $fattyAcidIdsByKey): array {
                $normalizedKey = trim((string) $fattyAcidKey);

                if ($normalizedKey === '') {
                    throw new RuntimeException("Mendrulandia oils row [{$sourceKey}] contains an empty fatty acid key.");
                }

                $fattyAcidId = $fattyAcidIdsByKey[$normalizedKey] ?? null;

                if ($fattyAcidId === null) {
                    throw new RuntimeException("Mendrulandia oils row [{$sourceKey}] references unknown fatty acid [{$normalizedKey}].");
                }

                if (! is_numeric($percentage)) {
                    throw new RuntimeException("Mendrulandia oils row [{$sourceKey}] has a non-numeric percentage for fatty acid [{$normalizedKey}].");
                }

                $normalizedPercentage = round((float) $percentage, 5);

                if ($normalizedPercentage < 0 || $normalizedPercentage > 100) {
                    throw new RuntimeException("Mendrulandia oils row [{$sourceKey}] has an out-of-range percentage for fatty acid [{$normalizedKey}].");
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
     * @param  array<int, array{fatty_acid_id: int, percentage: float}>  $fattyAcids
     */
    private function syncFattyAcids(Ingredient $ingredient, array $fattyAcids): void
    {
        IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        foreach ($fattyAcids as $fattyAcid) {
            IngredientFattyAcid::query()->create([
                'ingredient_id' => $ingredient->id,
                'fatty_acid_id' => $fattyAcid['fatty_acid_id'],
                'percentage' => $fattyAcid['percentage'],
            ]);
        }
    }

    private function syncSapProfile(
        Ingredient $ingredient,
        ?float $kohSapValue,
        ?float $iodineValue,
        ?float $insValue,
    ): void {
        $hasChemistryState = $kohSapValue !== null
            || $iodineValue !== null
            || $insValue !== null;

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
            ],
        );
    }
}
