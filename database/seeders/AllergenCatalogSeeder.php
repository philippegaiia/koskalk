<?php

namespace Database\Seeders;

use App\Models\Allergen;
use Illuminate\Database\Seeder;
use RuntimeException;

class AllergenCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = (string) config('catalog-imports.allergens.path');
        $sourceName = (string) config('catalog-imports.allergens.source_name');

        foreach ($this->rows($path) as $row) {
            $inciName = $this->value($row, 0);

            if ($inciName === null) {
                continue;
            }

            Allergen::query()->updateOrCreate(
                [
                    'source_file' => $path,
                    'inci_name' => $inciName,
                ],
                [
                    'source_name' => $sourceName,
                    'cas_number' => $this->value($row, 1),
                    'ec_number' => $this->value($row, 2),
                    'common_name_en' => $this->value($row, 3),
                    'common_name_fr' => $this->value($row, 4),
                    'source_data' => $row,
                ]
            );
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to read allergen catalog CSV at [{$path}].");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open allergen catalog CSV at [{$path}].");
        }

        $rows = [];
        $headerFound = false;

        while (($row = fgetcsv($handle)) !== false) {
            if (! $headerFound) {
                $firstColumn = $this->value($row, 0);

                if ($firstColumn === 'Nom INCI (à étiqueter)') {
                    $headerFound = true;
                }

                continue;
            }

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $row
     */
    private function value(array $row, int $index): ?string
    {
        $value = $row[$index] ?? null;

        if ($value === null) {
            return null;
        }

        $trimmedValue = trim(ltrim($value, "\xEF\xBB\xBF"));

        return $trimmedValue === '' ? null : $trimmedValue;
    }
}
