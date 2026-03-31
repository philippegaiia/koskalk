<?php

namespace Database\Seeders;

use App\IngredientCategory;
use App\Models\Ingredient;
use Illuminate\Database\Seeder;
use RuntimeException;

class IngredientCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = (string) config('catalog-imports.ingredients.path');

        foreach ($this->rows($path) as $row) {
            $sourceKey = $this->value($row, 'Code');

            if ($sourceKey === null) {
                continue;
            }

            $sourceCodePrefix = $this->sourceCodePrefix($sourceKey);
            $category = $this->ingredientCategory(
                $sourceCodePrefix,
                $this->value($row, 'INCI'),
                $this->value($row, 'Name'),
            );

            if ($category === IngredientCategory::FragranceOil) {
                continue;
            }

            $soapInciNaohName = $this->value($row, 'INCI NaOH');
            $soapInciKohName = $this->value($row, 'INCI KOH');
            $displayNameEn = $this->value($row, 'Nom EN');
            $displayNameFr = $this->value($row, 'Name');

            $ingredient = Ingredient::query()->updateOrCreate(
                [
                    'source_file' => $path,
                    'source_key' => $sourceKey,
                ],
                [
                    'source_code_prefix' => $sourceCodePrefix,
                    'category' => $category,
                    'display_name' => $displayNameEn ?? $displayNameFr ?? $this->value($row, 'INCI') ?? $sourceKey,
                    'display_name_en' => $displayNameEn,
                    'inci_name' => $this->value($row, 'INCI'),
                    'soap_inci_naoh_name' => $soapInciNaohName,
                    'soap_inci_koh_name' => $soapInciKohName,
                    'cas_number' => $this->value($row, 'CAS'),
                    'ec_number' => $this->value($row, 'EINECS') ?? $this->value($row, 'CAS EINECS'),
                    'unit' => $this->value($row, 'Unit'),
                    'price_eur' => $this->decimalOrNull($this->value($row, 'Prix (€)')),
                    'is_potentially_saponifiable' => $soapInciNaohName !== null || $soapInciKohName !== null,
                    'requires_admin_review' => true,
                    'is_active' => $this->yesNoToBool($this->value($row, 'Active'), default: true),
                    'is_manufactured' => $this->yesNoToBool($this->value($row, 'Fabriqué'), default: false),
                    'source_data' => $row,
                ]
            );
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Unable to read ingredient catalog CSV at [{$path}].");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open ingredient catalog CSV at [{$path}].");
        }

        $headerRow = fgetcsv($handle);

        if ($headerRow === false) {
            fclose($handle);

            return [];
        }

        $headers = array_map(
            fn (string $header): string => trim(ltrim($header, "\xEF\xBB\xBF")),
            $headerRow
        );

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $mappedRow = [];

            foreach ($headers as $index => $header) {
                $mappedRow[$header] = trim($row[$index] ?? '');
            }

            $rows[] = $mappedRow;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<int, string>|array<string, string>  $row
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
     * @param  array<string, string>  $row
     */
    private function value(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        $trimmedValue = trim($value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }

    private function sourceCodePrefix(string $sourceKey): ?string
    {
        preg_match('/^[A-Za-z]+/', $sourceKey, $matches);

        return $matches[0] ?? null;
    }

    private function ingredientCategory(?string $sourceCodePrefix, ?string $inciName, ?string $ingredientName): IngredientCategory
    {
        $searchText = strtolower(trim(($inciName ?? '').' '.($ingredientName ?? '')));

        return match ($sourceCodePrefix) {
            'OB' => IngredientCategory::CarrierOil,
            'EO' => IngredientCategory::EssentialOil,
            'FR' => IngredientCategory::FragranceOil,
            'BE' => IngredientCategory::BotanicalExtract,
            'CO' => IngredientCategory::Colorant,
            'CH' => str_contains($searchText, 'hydroxide') ? IngredientCategory::Alkali : IngredientCategory::Liquid,
            default => str_contains($searchText, 'co2') && str_contains($searchText, 'extract')
                ? IngredientCategory::Co2Extract
                : (str_contains($searchText, 'extract')
                    ? IngredientCategory::BotanicalExtract
                    : (str_contains($searchText, 'preserv')
                        ? IngredientCategory::Preservative
                        : IngredientCategory::Additive)),
        };
    }

    private function yesNoToBool(?string $value, bool $default): bool
    {
        return match (strtolower($value ?? '')) {
            'yes' => true,
            'no' => false,
            default => $default,
        };
    }

    private function decimalOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }
}
