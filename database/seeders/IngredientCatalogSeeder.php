<?php

namespace Database\Seeders;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Services\IngredientIdentitySynchronizer;
use App\Support\IngredientCatalogTaxonomyDataset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IngredientCatalogSeeder extends Seeder
{
    public function __construct(
        private readonly IngredientIdentitySynchronizer $ingredientIdentitySynchronizer,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = (string) config('catalog-imports.ingredients.path');

        foreach ($this->rows($path) as $row) {
            $catalogKey = $this->value($row, 'Code');

            if ($catalogKey === null) {
                continue;
            }

            $sourceCodePrefix = $this->sourceCodePrefix($catalogKey);
            if ($sourceCodePrefix === 'FR') {
                continue;
            }

            $taxonomy = app(IngredientCatalogTaxonomyDataset::class)->assignmentFor($catalogKey);

            $soapInciNaohName = $this->value($row, 'INCI NaOH');
            $soapInciKohName = $this->value($row, 'INCI KOH');
            $displayNameEn = $this->value($row, 'Nom EN');
            $displayNameFr = $this->value($row, 'Name');

            DB::transaction(function () use ($catalogKey, $displayNameEn, $displayNameFr, $row, $soapInciKohName, $soapInciNaohName, $taxonomy): void {
                $ingredient = Ingredient::query()->updateOrCreate(
                    [
                        'catalog_key' => $catalogKey,
                    ],
                    [
                        'category' => $taxonomy['category'] ?? IngredientCategory::Other,
                        'subcategory' => $taxonomy['subcategory'] ?? null,
                        'taxonomy_source' => $taxonomy === null ? 'import_unclassified' : 'platform_curated',
                        'display_name' => $displayNameEn ?? $displayNameFr ?? $this->value($row, 'INCI') ?? $catalogKey,
                        'inci_name' => $this->value($row, 'INCI'),
                        'soap_inci_naoh_name' => $soapInciNaohName,
                        'soap_inci_koh_name' => $soapInciKohName,
                        'unit' => $this->value($row, 'Unit'),
                        'is_soap_saponification_trusted' => $taxonomy['is_soap_saponification_trusted'] ?? false,
                        'requires_aromatic_compliance' => $taxonomy['requires_aromatic_compliance'] ?? false,
                        'requires_admin_review' => true,
                        'is_active' => $this->yesNoToBool($this->value($row, 'Active'), default: true),
                        'is_manufactured' => $this->yesNoToBool($this->value($row, 'Fabriqué'), default: false),
                        'source_data' => $row,
                    ]
                );

                if ($ingredient->wasRecentlyCreated) {
                    $this->syncImportedIdentity($ingredient, $row);
                }
            }, attempts: 5);
        }
    }

    /**
     * @param  array<string, string>  $row
     */
    private function syncImportedIdentity(Ingredient $ingredient, array $row): void
    {
        $casNumbers = $this->identifierValues($this->value($row, 'CAS'));
        $ecNumbers = $this->identifierValues(
            $this->value($row, 'EINECS') ?? $this->value($row, 'CAS EINECS'),
        );
        $additionalIdentifiers = collect([
            ...collect($casNumbers)->skip(1)->map(fn (string $value): array => [
                'scheme' => 'cas',
                'value' => $value,
                'is_primary' => false,
            ]),
            ...collect($ecNumbers)->skip(1)->map(fn (string $value): array => [
                'scheme' => 'ec',
                'value' => $value,
                'is_primary' => false,
            ]),
        ])->values()->all();

        $this->ingredientIdentitySynchronizer->sync($ingredient, [
            'cas_number' => $casNumbers[0] ?? null,
            'ec_number' => $ecNumbers[0] ?? null,
            'additional_identifiers' => $additionalIdentifiers,
            'aliases' => [],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function identifierValues(?string $value): array
    {
        $seen = [];
        $identifiers = [];

        foreach (preg_split('/[,;]+/u', (string) $value) ?: [] as $candidate) {
            $trimmed = trim($candidate);
            $normalized = mb_strtolower($trimmed);

            if ($trimmed === '' || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $identifiers[] = $trimmed;
        }

        return $identifiers;
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
