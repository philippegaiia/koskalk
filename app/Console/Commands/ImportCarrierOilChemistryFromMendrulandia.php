<?php

namespace App\Console\Commands;

use App\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('catalog:import-carrier-oil-chemistry {--csv= : Path to CSV with common_name column}')]
#[Description('Import carrier oil chemistry from Mendrulandia data, matched by CSV common names.')]
class ImportCarrierOilChemistryFromMendrulandia extends Command
{
    public function handle(): int
    {
        $path = database_path('seeders/data/mendrulandia_oils.json');
        $rows = $this->rows($path);

        if ($rows === []) {
            $this->warn('No oils found in JSON.');

            return self::SUCCESS;
        }

        $csvFilter = $this->option('csv');
        $commonNamesToImport = [];

        if ($csvFilter !== null) {
            $commonNamesToImport = $this->loadCsvFilter($csvFilter);
            $this->info('Loaded '.count($commonNamesToImport).' common names from CSV filter.');
        }

        $fattyAcidIdsByKey = FattyAcid::query()->pluck('id', 'key')->all();

        $ingredientsByLowerName = Ingredient::query()
            ->where('category', IngredientCategory::CarrierOil->value)
            ->get()
            ->keyBy(fn ($i) => Str::lower($i->display_name));

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $displayName = $this->slugToDisplayName($row['source_key']);

            if ($csvFilter !== null && ! in_array(Str::lower($displayName), array_map(Str::lower(...), $commonNamesToImport), true)) {
                $skipped++;

                continue;
            }

            $ingredient = $ingredientsByLowerName->get(Str::lower($displayName));

            if (! $ingredient instanceof Ingredient) {
                $this->line("Skipping [{$displayName}]: no matching ingredient found.");
                $skipped++;

                continue;
            }

            $this->line("Importing [{$displayName}]...");

            if (! $ingredient->is_potentially_saponifiable) {
                $ingredient->forceFill(['is_potentially_saponifiable' => true])->save();
            }

            if (! filled($ingredient->inci_name) && filled($row['inci_name'] ?? null)) {
                $ingredient->forceFill(['inci_name' => $row['inci_name']])->save();
            }

            $this->syncSapProfile($ingredient, $row);
            $this->syncFattyAcids($ingredient, $row, $fattyAcidIdsByKey);

            $imported++;
        }

        $this->info("Done. Imported: {$imported}, Skipped: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{source_key:string, fatty_acids:array<string,float|null>, koh_sap_value:float|null, iodine_value:float|null, ins_value:float|null, inci_name?:string|null}>
     */
    private function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->warn("Unable to read JSON at [{$path}].");

            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->warn("Unable to open JSON at [{$path}].");

            return [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->warn('Invalid JSON structure.');

            return [];
        }

        /** @var array<int, array{source_key:string, fatty_acids:array<string,float|null>, koh_sap_value:float|null, iodine_value:float|null, ins_value:float|null}> $decoded */
        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function loadCsvFilter(string $csvPath): array
    {
        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            $this->warn("CSV file not found or not readable: [{$csvPath}].");

            return [];
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->warn("Unable to open CSV: [{$csvPath}].");

            return [];
        }

        $commonNames = [];
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            $this->warn("CSV appears empty: [{$csvPath}].");

            return [];
        }

        $commonNameIndex = array_search('common_name', $header, true);
        if ($commonNameIndex === false) {
            fclose($handle);
            $this->warn("CSV does not contain 'common_name' column: [{$csvPath}].");

            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (! isset($row[$commonNameIndex])) {
                continue;
            }

            $value = trim((string) $row[$commonNameIndex]);
            if ($value !== '') {
                $commonNames[] = $value;
            }
        }

        fclose($handle);

        return $commonNames;
    }

    private function syncSapProfile(Ingredient $ingredient, array $row): void
    {
        IngredientSapProfile::updateOrCreate(
            ['ingredient_id' => $ingredient->id],
            [
                'koh_sap_value' => $this->nullableFloat($row, 'koh_sap_value'),
                'iodine_value' => $this->nullableFloat($row, 'iodine_value'),
                'ins_value' => $this->nullableFloat($row, 'ins_value'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $fattyAcidIdsByKey
     */
    private function syncFattyAcids(Ingredient $ingredient, array $row, array $fattyAcidIdsByKey): void
    {
        IngredientFattyAcid::query()
            ->where('ingredient_id', $ingredient->id)
            ->delete();

        $fattyAcids = $row['fatty_acids'] ?? [];

        if (! is_array($fattyAcids)) {
            return;
        }

        foreach ($fattyAcids as $fattyAcidKey => $percentage) {
            if ($percentage === null || $percentage === '') {
                continue;
            }

            $normalizedKey = trim((string) $fattyAcidKey);
            if ($normalizedKey === '') {
                continue;
            }

            $fattyAcidId = $fattyAcidIdsByKey[$normalizedKey] ?? null;
            if ($fattyAcidId === null) {
                $this->warn("  Unknown fatty acid [{$normalizedKey}] for [{$ingredient->display_name}], skipping.");

                continue;
            }

            if (! is_numeric($percentage)) {
                $this->warn("  Non-numeric percentage for [{$normalizedKey}] on [{$ingredient->display_name}], skipping.");

                continue;
            }

            IngredientFattyAcid::query()->create([
                'ingredient_id' => $ingredient->id,
                'fatty_acid_id' => (int) $fattyAcidId,
                'percentage' => round((float) $percentage, 5),
            ]);
        }
    }

    private function nullableFloat(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 5);
    }

    private function slugToDisplayName(string $slug): string
    {
        return Str::title(str_replace('_', ' ', $slug));
    }
}
