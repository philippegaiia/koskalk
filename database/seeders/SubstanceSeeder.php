<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeSubstanceRule;
use App\Models\Substance;
use Illuminate\Database\Seeder;

class SubstanceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sourceName = 'Platform starter substance catalog';
        $substances = [
            ['name' => 'Beta-asarone', 'entity_type' => 'constituent', 'synonyms' => ['β-Asarone']],
            ['name' => 'Furocoumarins', 'entity_type' => 'group', 'synonyms' => ['Furanocoumarins']],
            ['name' => 'Linalool', 'entity_type' => 'constituent', 'allergen_inci' => 'LINALOOL'],
            ['name' => 'Methyl eugenol', 'entity_type' => 'constituent'],
            ['name' => 'Pulegone', 'entity_type' => 'constituent'],
            ['name' => 'Safrole', 'entity_type' => 'constituent'],
        ];

        foreach ($substances as $substanceData) {
            $allergenInci = $substanceData['allergen_inci'] ?? null;
            $allergenId = is_string($allergenInci)
                ? Allergen::query()->where('inci_name', $allergenInci)->value('id')
                : null;

            $substance = Substance::query()->updateOrCreate(
                [
                    'source_name' => $sourceName,
                    'name' => $substanceData['name'],
                ],
                [
                    'entity_type' => $substanceData['entity_type'],
                    'synonyms' => $substanceData['synonyms'] ?? null,
                    'allergen_id' => $allergenId,
                    'notes' => 'Starter substance catalog entry. Admin must attach and confirm exact market rules, limits, and sources before using it as a release decision.',
                    'source_data' => [
                        'seed_scope' => 'starter_substance_catalog',
                    ],
                ],
            );

            RegulatoryRegime::query()
                ->whereIn('code', ['eu', 'canada_2026', 'canada_expanded_preview', 'us_mocra_preview'])
                ->get()
                ->each(function (RegulatoryRegime $regime) use ($substance): void {
                    RegulatoryRegimeSubstanceRule::query()->updateOrCreate(
                        [
                            'regulatory_regime_id' => $regime->id,
                            'substance_id' => $substance->id,
                        ],
                        [
                            'rule_type' => 'watch',
                            'rinse_off_max_percent' => null,
                            'leave_on_max_percent' => null,
                            'threshold_operator' => 'less_than_or_equal',
                            'exposure_scope' => 'both',
                            'label_warning_text' => 'Check this substance against the supplier documents and current market rule.',
                            'is_active' => true,
                            'source_reference' => 'Platform starter rule. Replace with official source before relying on it.',
                            'source_data' => [
                                'seed_scope' => 'starter_rule',
                            ],
                        ],
                    );
                });
        }
    }
}
