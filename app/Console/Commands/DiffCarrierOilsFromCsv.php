<?php

namespace App\Console\Commands;

use App\IngredientCategory;
use App\Models\Ingredient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:diff-carrier-oils {--csv= : Path to CSV with common_name column} {--format= : Output format (text or json)}')]
#[Description('Diff CSV common names against existing carrier oils in DB.')]
class DiffCarrierOilsFromCsv extends Command
{
    public function handle(): int
    {
        $csvPath = $this->option('csv');

        if (! $csvPath) {
            $this->error('--csv= option is required.');

            return self::FAILURE;
        }

        if (! file_exists($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");

            return self::FAILURE;
        }

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->error("Cannot read CSV file: {$csvPath}");

            return self::FAILURE;
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);
            $this->error("CSV appears empty: {$csvPath}");

            return self::FAILURE;
        }

        $commonNameIndex = array_search('common_name', $header, true);

        if ($commonNameIndex === false) {
            fclose($handle);
            $this->error("CSV does not contain 'common_name' column: {$csvPath}");

            return self::FAILURE;
        }

        $csvNames = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[$commonNameIndex]) && $row[$commonNameIndex] !== '') {
                $csvNames[] = trim((string) $row[$commonNameIndex]);
            }
        }
        fclose($handle);

        $csvNames = array_unique(array_filter($csvNames));
        sort($csvNames);

        $dbNames = Ingredient::query()
            ->where('category', IngredientCategory::CarrierOil->value)
            ->pluck('display_name')
            ->sort()
            ->values()
            ->toArray();

        $alreadyImported = array_intersect($csvNames, $dbNames);
        $missingFromDb = array_diff($csvNames, $dbNames);
        $extraInDb = array_diff($dbNames, $csvNames);

        if ($this->option('format') === 'json') {
            $this->line(json_encode([
                'already_imported' => array_values($alreadyImported),
                'missing_from_db' => array_values($missingFromDb),
                'extra_in_db' => array_values($extraInDb),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->outputSection('Already imported', $alreadyImported);
        $this->outputSection('Missing from DB', $missingFromDb);
        $this->outputSection('Extra in DB not in CSV', $extraInDb);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function outputSection(string $title, array $items): void
    {
        $count = count($items);
        $this->line('');
        $this->line("{$title} ({$count}):");
        if ($count === 0) {
            $this->line('  (none)');
        } else {
            foreach ($items as $item) {
                $this->line("  - {$item}");
            }
        }
    }
}
