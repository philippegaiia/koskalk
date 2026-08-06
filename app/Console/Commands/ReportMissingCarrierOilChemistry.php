<?php

namespace App\Console\Commands;

use App\IngredientCategory;
use App\Models\Ingredient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('catalog:report-missing-carrier-oil-chemistry {--json : Output machine-readable JSON instead of a table}')]
#[Description('List carrier oils that still need SAP values or fatty acid profiles.')]
class ReportMissingCarrierOilChemistry extends Command
{
    public function handle(): int
    {
        $missingCarrierOils = $this->missingCarrierOils();

        if ($missingCarrierOils->isEmpty()) {
            $this->info('All carrier oils have SAP values and fatty acid profiles.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->line($missingCarrierOils->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Source key', 'Ingredient', 'Missing SAP', 'Missing Fatty Acids', 'Fatty Acid Count'],
            $missingCarrierOils
                ->map(fn (array $row): array => [
                    $row['source_key'],
                    $row['display_name'],
                    $row['missing_sap'] ? 'yes' : 'no',
                    $row['missing_fatty_acids'] ? 'yes' : 'no',
                    $row['fatty_acid_count'],
                ])
                ->all(),
        );

        $count = $missingCarrierOils->count();
        $this->warn("{$count} carrier oil".($count === 1 ? '' : 's').' still need soap chemistry.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{
     *     source_key:?string,
     *     display_name:?string,
     *     missing_sap:bool,
     *     missing_fatty_acids:bool,
     *     fatty_acid_count:int
     * }>
     */
    private function missingCarrierOils(): Collection
    {
        return Ingredient::query()
            ->where('category', IngredientCategory::CarrierOil->value)
            ->with([
                'sapProfile:id,ingredient_id,koh_sap_value',
                'fattyAcidEntries:id,ingredient_id',
            ])
            ->orderBy('display_name')
            ->get()
            ->filter(function (Ingredient $ingredient): bool {
                return $ingredient->sapProfile?->koh_sap_value === null
                    || $ingredient->fattyAcidEntries->isEmpty();
            })
            ->map(function (Ingredient $ingredient): array {
                return [
                    'source_key' => $ingredient->source_key,
                    'display_name' => $ingredient->display_name,
                    'missing_sap' => $ingredient->sapProfile?->koh_sap_value === null,
                    'missing_fatty_acids' => $ingredient->fattyAcidEntries->isEmpty(),
                    'fatty_acid_count' => $ingredient->fattyAcidEntries->count(),
                ];
            })
            ->values();
    }
}
