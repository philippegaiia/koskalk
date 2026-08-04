<?php

use App\Services\SoapCuredOutputBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('preserves theoretical soap output weights while replacing source water with residual water', function () {
    $output = app(SoapCuredOutputBuilder::class)->build(
        labeling: [
            'default_variant_key' => 'saponified_with_superfat',
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM ALMONDATE', 'weight' => 553.8132, 'kind' => 'saponified_oil', 'source_ingredients' => ['Almond oil']],
                    ['label' => 'SODIUM COCOATE', 'weight' => 186.6521, 'kind' => 'saponified_oil', 'source_ingredients' => ['Coconut oil']],
                    ['label' => 'GLYCERIN', 'weight' => 80.4311, 'kind' => 'derived', 'source_ingredients' => ['Saponification']],
                    ['label' => 'PRUNUS AMYGDALUS DULCIS OIL', 'weight' => 40.425, 'kind' => 'theoretical_superfat', 'source_ingredients' => ['Almond oil']],
                    ['label' => 'COCOS NUCIFERA OIL', 'weight' => 13.475, 'kind' => 'theoretical_superfat', 'source_ingredients' => ['Coconut oil']],
                    ['label' => 'LAVANDULA HYBRIDA OIL', 'weight' => 15.4, 'kind' => 'ingredient', 'source_ingredients' => ['Lavandin super essential oil']],
                    ['label' => 'AQUA', 'weight' => 300, 'kind' => 'ingredient', 'source_ingredients' => ['Water']],
                ],
                'declaration_rows' => [[
                    'label' => 'LIMONENE',
                    'percent_of_formula' => 0.2,
                    'included_in_inci' => true,
                ]],
                'final_label_text' => 'SODIUM ALMONDATE, AQUA, LIMONENE',
            ]],
        ],
        curedWeight: 1000.22,
    );

    $rows = collect($output['rows'])->keyBy('name');

    expect($rows['SODIUM ALMONDATE'])->toMatchArray([
        'percentage' => 55.3691,
        'weight' => 553.8132,
    ])->and($rows['AQUA'])->toMatchArray([
        'percentage' => 11.0,
        'weight' => 110.0242,
    ])->and($rows['GLYCERIN'])->toMatchArray([
        'percentage' => 8.0413,
        'weight' => 80.4311,
    ])->and($output['inci'])->toBe('SODIUM ALMONDATE, AQUA, LIMONENE');
});
