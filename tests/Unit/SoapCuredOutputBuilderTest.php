<?php

use App\Services\SoapCuredOutputBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('normalizes the selected soap output to 89 percent non-water and 11 percent water', function () {
    $output = app(SoapCuredOutputBuilder::class)->build(
        labeling: [
            'default_variant_key' => 'saponified_with_superfat',
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM OLIVATE', 'weight' => 900, 'kind' => 'saponified_oil', 'source_ingredients' => ['Olive oil']],
                    ['label' => 'AQUA', 'weight' => 300, 'kind' => 'ingredient', 'source_ingredients' => ['Water']],
                ],
                'declaration_rows' => [[
                    'label' => 'LIMONENE',
                    'percent_of_formula' => 0.2,
                    'included_in_inci' => true,
                ]],
                'final_label_text' => 'SODIUM OLIVATE, AQUA, LIMONENE',
            ]],
        ],
        curedWeight: 1000,
    );

    expect($output['rows'][0])->toMatchArray([
        'name' => 'SODIUM OLIVATE',
        'percentage' => 89.0,
        'weight' => 890.0,
    ])->and($output['rows'][1])->toMatchArray([
        'name' => 'AQUA',
        'percentage' => 11.0,
        'weight' => 110.0,
    ])->and($output['inci'])->toBe('SODIUM OLIVATE, AQUA, LIMONENE');
});
