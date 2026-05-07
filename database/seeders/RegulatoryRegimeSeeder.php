<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use Illuminate\Database\Seeder;

class RegulatoryRegimeSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const CANADA_2026_ALLERGENS = [
        'AMYL CINNAMAL',
        'AMYLCINNAMYL ALCOHOL',
        'ANISE ALCOHOL',
        'BENZYL ALCOHOL',
        'BENZYL BENZOATE',
        'BENZYL CINNAMATE',
        'BENZYL SALICYLATE',
        'CINNAMAL',
        'CINNAMYL ALCOHOL',
        'CITRAL',
        'CITRONELLOL',
        'COUMARIN',
        'EUGENOL',
        'FARNESOL',
        'GERANIOL',
        'HEXYL CINNAMAL',
        'HYDROXYCITRONELLAL',
        'ISOEUGENOL',
        'ALPHA-ISOMETHYL IONONE',
        'LIMONENE',
        'LINALOOL',
        'METHYL 2-OCTYNOATE',
        'EVERNIA FURFURACEA EXTRACT',
        'EVERNIA PRUNASTRI EXTRACT',
    ];

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
                'version_label' => 'Full 82 fragrance allergens',
                'status' => 'active',
                'is_default' => true,
                'effective_from' => null,
                'effective_until' => null,
                'source_name' => 'EU fragrance allergen labelling',
                'source_url' => 'https://eur-lex.europa.eu/eli/reg/2023/1545/oj/eng',
                'reviewed_at' => now(),
                'notes' => 'Default practical EU label regime using the full 82 allergen catalog and 0.01% rinse-off / 0.001% leave-on thresholds.',
                'source_data' => [
                    'thresholds' => [
                        'rinse_off_percent' => 0.01,
                        'leave_on_percent' => 0.001,
                    ],
                ],
            ],
        );

        $canada2026Regime = RegulatoryRegime::query()->updateOrCreate(
            ['code' => 'canada_2026'],
            [
                'market_code' => 'ca',
                'name' => 'Canada 2026',
                'version_label' => 'Initial 24 fragrance allergens',
                'status' => 'active',
                'is_default' => false,
                'effective_from' => null,
                'effective_until' => null,
                'source_name' => 'Health Canada cosmetic ingredient labelling',
                'source_url' => 'https://www.canada.ca/en/health-canada/services/consumer-product-safety/reports-publications/industry-professionals/guide-cosmetic-ingredient-labelling.html',
                'reviewed_at' => now(),
                'notes' => 'Initial Canadian disclosure regime for 24 fragrance allergens from April 12, 2026, using 0.01% rinse-off / 0.001% leave-on thresholds.',
                'source_data' => [
                    'thresholds' => [
                        'rinse_off_percent' => 0.01,
                        'leave_on_percent' => 0.001,
                    ],
                    'milestones' => [
                        'list_1_required_from' => '2026-04-12',
                    ],
                ],
            ],
        );

        $canadaExpandedRegime = RegulatoryRegime::query()->updateOrCreate(
            ['code' => 'canada_expanded_preview'],
            [
                'market_code' => 'ca',
                'name' => 'Canada expanded preview',
                'version_label' => 'Expanded Annex III fragrance allergens',
                'status' => 'preview',
                'is_default' => false,
                'effective_from' => null,
                'effective_until' => null,
                'source_name' => 'Health Canada cosmetic ingredient labelling',
                'source_url' => 'https://www.canada.ca/en/health-canada/services/consumer-product-safety/reports-publications/industry-professionals/guide-cosmetic-ingredient-labelling.html',
                'reviewed_at' => now(),
                'notes' => 'Preview regime for Canada List 2 expansion. New cosmetics disclose expanded allergens from August 1, 2026; existing products from August 1, 2028.',
                'source_data' => [
                    'thresholds' => [
                        'rinse_off_percent' => 0.01,
                        'leave_on_percent' => 0.001,
                    ],
                    'milestones' => [
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
                'name' => 'US MoCRA preview',
                'version_label' => 'Fragrance allergen rule pending',
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

        $this->mapAllergenRules($euRegime, null, 'EU full fragrance allergen catalog');
        $this->mapAllergenRules($canada2026Regime, self::CANADA_2026_ALLERGENS, 'Canada List 1 fragrance allergen catalog');
        $this->mapAllergenRules($canadaExpandedRegime, null, 'Canada expanded fragrance allergen catalog preview');
    }

    /**
     * @param  array<int, string>|null  $inciNames
     */
    private function mapAllergenRules(RegulatoryRegime $regime, ?array $inciNames, string $sourceReference): void
    {
        $query = Allergen::query()->orderBy('inci_name');

        if ($inciNames !== null) {
            $query->whereIn('inci_name', $inciNames);
        }

        $query->each(function (Allergen $allergen) use ($regime, $sourceReference): void {
            RegulatoryRegimeAllergen::query()->updateOrCreate(
                [
                    'regulatory_regime_id' => $regime->id,
                    'allergen_id' => $allergen->id,
                ],
                [
                    'declaration_label' => null,
                    'rinse_off_threshold_percent' => 0.01000,
                    'leave_on_threshold_percent' => 0.00100,
                    'threshold_operator' => 'greater_than_or_equal',
                    'group_key' => null,
                    'group_label' => null,
                    'is_active' => true,
                    'effective_from' => null,
                    'effective_until' => null,
                    'source_reference' => $sourceReference,
                    'source_data' => null,
                ],
            );
        });
    }
}
