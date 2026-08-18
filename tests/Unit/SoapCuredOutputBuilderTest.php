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

it('splits cured residual liquid between water and selected lye liquids', function () {
    $output = app(SoapCuredOutputBuilder::class)->build(
        labeling: [
            'default_variant_key' => 'saponified_with_superfat',
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM OLIVATE', 'weight' => 890, 'kind' => 'saponified_oil'],
                    ['label' => 'AQUA', 'weight' => 190, 'kind' => 'water'],
                    ['label' => 'ROSA DAMASCENA FLOWER WATER', 'weight' => 190, 'kind' => 'lye_liquid'],
                ],
                'final_label_text' => 'SODIUM OLIVATE, AQUA, ROSA DAMASCENA FLOWER WATER',
            ]],
        ],
        curedWeight: 1000,
    );

    $rows = collect($output['rows'])->keyBy('name');

    expect($rows['AQUA']['weight'])->toBe(55.0)
        ->and($rows['ROSA DAMASCENA FLOWER WATER']['weight'])->toBe(55.0)
        ->and($output['basis_weight'])->toBe(1000.0)
        ->and(collect($output['rows'])->sum('weight'))->toBe(1000.0);
});

it('retains non lye mass when merged labels contain both lye liquid and additive contributions', function () {
    $output = app(SoapCuredOutputBuilder::class)->build(
        labeling: [
            'default_variant_key' => 'saponified_with_superfat',
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM OLIVATE', 'weight' => 860, 'lye_liquid_weight' => 0, 'kind' => 'saponified_oil'],
                    ['label' => 'AQUA', 'weight' => 200, 'lye_liquid_weight' => 190, 'kind' => 'water'],
                    ['label' => 'ROSA DAMASCENA FLOWER WATER', 'weight' => 210, 'lye_liquid_weight' => 190, 'kind' => 'lye_liquid'],
                ],
                'final_label_text' => 'SODIUM OLIVATE, AQUA, ROSA DAMASCENA FLOWER WATER',
            ]],
        ],
        curedWeight: 1000,
    );

    $rows = collect($output['rows'])->keyBy('name');

    expect($rows['AQUA']['weight'])->toBe(65.0)
        ->and($rows['ROSA DAMASCENA FLOWER WATER']['weight'])->toBe(75.0)
        ->and(collect($output['rows'])->sum('weight'))->toBe(1000.0);
});
