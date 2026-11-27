<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\RecipeVersion;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Services\EuFragranceAllergenCatalogSynchronizer;
use Illuminate\Database\Seeder;

class RegulatoryRegimeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $euRegime = RegulatoryRegime::query()->updateOrCreate(
            ['code' => 'eu'],
            [
                'market_code' => 'eu',
                'name' => 'EU regime',
                'version_label' => 'Fragrance allergens, Regulation (EU) 2023/1545',
                'status' => 'active',
                'is_default' => true,
                'effective_from' => null,
                'effective_until' => null,
                'source_name' => 'EU fragrance allergen labelling',
                'source_url' => 'https://eur-lex.europa.eu/eli/reg/2023/1545/oj/eng',
                'reviewed_at' => now(),
                'notes' => 'EU label regime using the 81 fragrance allergen entries individually labelled under Regulation (EU) 2023/1545, at 0.01% rinse-off / 0.001% leave-on.',
                'source_data' => [
                    'thresholds' => [
                        'rinse_off_percent' => 0.01,
                        'leave_on_percent' => 0.001,
                    ],
                ],
            ],
        );

        // Single Canadian regime: Health Canada adopted the EU expanded
        // fragrance-allergen list (SOR/DORS-63), so one row carries the full
        // catalog. New cosmetics from August 1, 2026; existing from August 1, 2028.
        $canada2026Regime = RegulatoryRegime::query()->updateOrCreate(
            ['code' => 'canada_2026'],
            [
                'market_code' => 'ca',
                'name' => 'Canada',
                'version_label' => 'Expanded fragrance allergens (SOR/DORS-63)',
                'status' => 'active',
                'is_default' => false,
                'effective_from' => null,
                'effective_until' => null,
                'source_name' => 'Health Canada cosmetic ingredient labelling',
                'source_url' => 'https://www.canada.ca/en/health-canada/services/consumer-product-safety/reports-publications/industry-professionals/guide-cosmetic-ingredient-labelling.html',
                'reviewed_at' => now(),
                'notes' => 'Canada adopts the EU expanded fragrance-allergen list with 0.01% rinse-off / 0.001% leave-on thresholds. New cosmetics disclose from August 1, 2026; existing products from August 1, 2028. Declaration names may fall back to EU names; plain-language lists are bilingual English/French.',
                'source_data' => [
                    'thresholds' => [
                        'rinse_off_percent' => 0.01,
                        'leave_on_percent' => 0.001,
                    ],
                    'milestones' => [
                        'list_1_required_from' => '2026-04-12',
                        'new_cosmetics_required_from' => '2026-08-01',
                        'existing_products_required_from' => '2028-08-01',
                    ],
                ],
            ],
        );

        RegulatoryRegime::query()->updateOrCreate(
            ['code' => 'us_mocra_preview'],
            [
                'market_code' => 'us',
                'name' => 'US FDA',
                'version_label' => 'MoCRA fragrance allergen rule pending',
                'status' => 'preview',
                'is_default' => false,
                'effective_from' => null,
                'effective_until' => null,
                'source_name' => 'FDA MoCRA cosmetics law',
                'source_url' => 'https://www.fda.gov/cosmetics/cosmetics-laws-regulations/cosmetics-us-law',
                'reviewed_at' => now(),
                'notes' => 'Preview shell only. MoCRA requires FDA rulemaking for fragrance allergen labelling, but no mandatory allergen mapping is seeded until the final rule is available.',
                'source_data' => [
                    'mapping_status' => 'pending_final_rule',
                ],
            ],
        );

        $this->mapAllergenRules($euRegime, 'EU fragrance allergens, Regulation (EU) 2023/1545');
        $this->mapAllergenRules($canada2026Regime, 'Canada expanded fragrance allergen catalog (SOR/DORS-63)');

        // The former separate preview regime is superseded by canada_2026.
        // Re-point any stored versions first so their regime reference survives.
        RegulatoryRegime::query()
            ->where('code', 'canada_expanded_preview')
            ->get()
            ->each(function (RegulatoryRegime $regime) use ($canada2026Regime): void {
                RecipeVersion::query()
                    ->where('regulatory_regime_id', $regime->id)
                    ->update([
                        'regulatory_regime_id' => $canada2026Regime->id,
                        'regulatory_regime' => $canada2026Regime->code,
                    ]);

                $regime->delete();
            });
    }

    /**
     * Maps every allergen aligned to Regulation (EU) 2023/1545 to the market,
     * leaving rows the regulation does not list unmapped.
     */
    private function mapAllergenRules(RegulatoryRegime $regime, string $sourceReference): void
    {
        Allergen::query()
            ->where('source_file', EuFragranceAllergenCatalogSynchronizer::SOURCE_FILE)
            ->orderBy('inci_name')
            ->each(function (Allergen $allergen) use ($regime, $sourceReference): void {
                RegulatoryRegimeAllergen::query()->updateOrCreate(
                    [
                        'regulatory_regime_id' => $regime->id,
                        'allergen_id' => $allergen->id,
                    ],
                    [
                        ...EuFragranceAllergenCatalogSynchronizer::defaultRuleAttributes(),
                        'source_reference' => $sourceReference,
                    ],
                );
            });
    }
}
