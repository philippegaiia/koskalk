<?php

use App\Services\RecipeVersionCostPreviewBuilder;

it('calculates a scaled ingredient and finished-unit packaging cost from independent values', function (): void {
    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromTransientRows(
        formula: [
            'family' => 'soap',
            'basis_weight' => '500',
            'basis_unit' => 'g',
            'phases' => [
                'saponified_oils' => [
                    'label' => 'Saponified oils',
                    'rows' => [[
                        'ingredient_id' => 11,
                        'position' => 1,
                        'name' => 'Olive oil',
                        'percentage' => '100',
                        'weight' => '500',
                    ]],
                ],
            ],
            'packaging_items' => [[
                'packaging_item_id' => 21,
                'position' => 1,
                'name' => 'Box',
                'components_per_unit' => '1',
            ]],
            'synthetic_rows' => [],
        ],
        costing: [
            'oil_weight_for_costing' => '500',
            'oil_unit_for_costing' => 'g',
            'units_produced' => '2',
            'currency' => 'EUR',
            'items' => [[
                'ingredient_id' => 11,
                'phase_key' => 'saponified_oils',
                'position' => 1,
                'price_per_kg' => '10',
            ]],
            'packaging_items' => [[
                'packaging_item_id' => 21,
                'position' => 1,
                'name' => 'Box',
                'unit_cost' => '0.50',
                'components_per_unit' => '1',
            ]],
        ],
    );

    expect($preview['ingredient_rows'][0]['quantity'])->toBe(500.0)
        ->and($preview['ingredient_rows'][0]['line_cost'])->toBe(5.0)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBe(1.0)
        ->and($preview['total_cost'])->toBe(6.0)
        ->and($preview['cost_per_unit'])->toBe(3.0)
        ->and($preview['currency'])->toBe('EUR');
});

it('preserves zero and missing prices and distinguishes duplicate packaging occurrences', function (): void {
    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromTransientRows(
        formula: [
            'family' => 'cosmetic',
            'basis_weight' => '1',
            'basis_unit' => 'kg',
            'phases' => [
                'ingredients' => [
                    'label' => 'Formula',
                    'rows' => [
                        ['ingredient_id' => 11, 'position' => 1, 'name' => 'Water', 'percentage' => '50', 'weight' => '0.5'],
                        ['ingredient_id' => 12, 'position' => 2, 'name' => 'Extract', 'percentage' => '50', 'weight' => '0.5'],
                    ],
                ],
            ],
            'packaging_items' => [
                ['packaging_item_id' => 21, 'position' => 1, 'name' => 'Jar', 'components_per_unit' => '1'],
                ['packaging_item_id' => 21, 'position' => 2, 'name' => 'Jar', 'components_per_unit' => '2'],
            ],
            'synthetic_rows' => [],
        ],
        costing: [
            'oil_weight_for_costing' => '1',
            'oil_unit_for_costing' => 'kg',
            'units_produced' => '3',
            'currency' => 'EUR',
            'items' => [
                ['ingredient_id' => 11, 'phase_key' => 'ingredients', 'position' => 1, 'price_per_kg' => '0'],
                ['ingredient_id' => 12, 'phase_key' => 'ingredients', 'position' => 2, 'price_per_kg' => null],
            ],
            'packaging_items' => [
                ['packaging_item_id' => 21, 'position' => 1, 'name' => 'Jar', 'unit_cost' => '0.10', 'components_per_unit' => '1'],
                ['packaging_item_id' => 21, 'position' => 2, 'name' => 'Jar', 'unit_cost' => '0.20', 'components_per_unit' => '2'],
            ],
        ],
    );

    expect($preview['ingredient_rows'][0]['price_per_kg'])->toBe(0.0)
        ->and($preview['ingredient_rows'][0]['is_unpriced'])->toBeFalse()
        ->and($preview['ingredient_rows'][1]['price_per_kg'])->toBeNull()
        ->and($preview['ingredient_rows'][1]['is_unpriced'])->toBeTrue()
        ->and($preview['packaging_rows'][0]['unit_cost'])->toBe(0.1)
        ->and($preview['packaging_rows'][1]['unit_cost'])->toBe(0.2)
        ->and($preview['packaging_rows'][0]['components_per_unit'])->toBe(1.0)
        ->and($preview['packaging_rows'][1]['components_per_unit'])->toBe(2.0)
        ->and($preview['has_unpriced_rows'])->toBeTrue();
});

it('keeps known costing subtotals when ingredient and packaging prices are missing', function (): void {
    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromTransientRows(
        formula: [
            'family' => 'cosmetic',
            'basis_weight' => '1',
            'basis_unit' => 'kg',
            'phases' => [
                'ingredients' => [
                    'label' => 'Formula',
                    'rows' => [
                        ['ingredient_id' => 11, 'position' => 1, 'name' => 'Oil', 'percentage' => '50', 'weight' => '0.5'],
                        ['ingredient_id' => 12, 'position' => 2, 'name' => 'Extract', 'percentage' => '50', 'weight' => '0.5'],
                        ['ingredient_id' => 13, 'position' => 3, 'name' => 'Water', 'percentage' => '100', 'weight' => '1'],
                    ],
                ],
            ],
            'packaging_items' => [
                ['packaging_item_id' => 21, 'position' => 1, 'name' => 'Jar', 'components_per_unit' => '1'],
                ['packaging_item_id' => 21, 'position' => 2, 'name' => 'Cap', 'components_per_unit' => '2'],
            ],
            'synthetic_rows' => [],
        ],
        costing: [
            'oil_weight_for_costing' => '1',
            'oil_unit_for_costing' => 'kg',
            'units_produced' => '2',
            'currency' => 'EUR',
            'items' => [
                ['ingredient_id' => 11, 'phase_key' => 'ingredients', 'position' => 1, 'price_per_kg' => '10'],
                ['ingredient_id' => 12, 'phase_key' => 'ingredients', 'position' => 2, 'price_per_kg' => null],
                ['ingredient_id' => 13, 'phase_key' => 'ingredients', 'position' => 3, 'price_per_kg' => '0'],
            ],
            'packaging_items' => [
                ['packaging_item_id' => 21, 'position' => 1, 'name' => 'Jar', 'unit_cost' => null, 'components_per_unit' => '1'],
                ['packaging_item_id' => 21, 'position' => 2, 'name' => 'Cap', 'unit_cost' => '0.25', 'components_per_unit' => '2'],
            ],
        ],
    );

    expect($preview['ingredient_rows'][0]['line_cost'])->toBe(5.0)
        ->and($preview['ingredient_rows'][1]['line_cost'])->toBeNull()
        ->and($preview['ingredient_rows'][2]['line_cost'])->toBe(0.0)
        ->and($preview['ingredient_total'])->toBe(5.0)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBeNull()
        ->and($preview['packaging_rows'][1]['line_cost'])->toBe(1.0)
        ->and($preview['packaging_total'])->toBe(1.0)
        ->and($preview['total_cost'])->toBe(6.0)
        ->and($preview['cost_per_unit'])->toBe(3.0)
        ->and($preview['has_unpriced_rows'])->toBeTrue();
});

it('scales costing quantities across display units and marks packaging totals incomplete without a unit count', function (): void {
    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromTransientRows(
        formula: [
            'family' => 'cosmetic',
            'basis_weight' => '1000',
            'basis_unit' => 'g',
            'phases' => [
                'ingredients' => [
                    'label' => 'Formula',
                    'rows' => [[
                        'ingredient_id' => 11,
                        'position' => 1,
                        'name' => 'Oil',
                        'percentage' => '100',
                        'weight' => '1000',
                    ]],
                ],
            ],
            'packaging_items' => [[
                'packaging_item_id' => 21,
                'position' => 1,
                'name' => 'Bottle',
                'components_per_unit' => '1',
            ]],
            'synthetic_rows' => [],
        ],
        costing: [
            'oil_weight_for_costing' => '0.5',
            'oil_unit_for_costing' => 'kg',
            'units_produced' => null,
            'currency' => 'EUR',
            'items' => [['ingredient_id' => 11, 'phase_key' => 'ingredients', 'position' => 1, 'price_per_kg' => '10']],
            'packaging_items' => [['packaging_item_id' => 21, 'position' => 1, 'name' => 'Bottle', 'unit_cost' => '0.50', 'components_per_unit' => '1']],
        ],
    );

    expect($preview['ingredient_rows'][0]['quantity'])->toBe(0.5)
        ->and($preview['ingredient_rows'][0]['unit'])->toBe('kg')
        ->and($preview['ingredient_total'])->toBe(5.0)
        ->and($preview['packaging_total'])->toBeNull()
        ->and($preview['total_cost'])->toBeNull()
        ->and($preview['cost_per_unit'])->toBeNull();
});

it('preserves tiny nonzero dose costs through decimal-string aggregation', function (): void {
    $preview = app(RecipeVersionCostPreviewBuilder::class)->buildFromTransientRows(
        formula: [
            'family' => 'cosmetic',
            'basis_weight' => '1',
            'basis_unit' => 'g',
            'phases' => [
                'ingredients' => [
                    'label' => 'Formula',
                    'rows' => [[
                        'ingredient_id' => 11,
                        'position' => 1,
                        'name' => 'Concentrate',
                        'percentage' => '0.1',
                        'weight' => '0.001',
                    ]],
                ],
            ],
            'packaging_items' => [],
            'synthetic_rows' => [],
        ],
        costing: [
            'oil_weight_for_costing' => '1',
            'oil_unit_for_costing' => 'g',
            'units_produced' => null,
            'currency' => 'EUR',
            'items' => [['ingredient_id' => 11, 'phase_key' => 'ingredients', 'position' => 1, 'price_per_kg' => '20']],
            'packaging_items' => [],
        ],
    );

    expect($preview['ingredient_rows'][0]['quantity'])->toBe(0.001)
        ->and($preview['ingredient_rows'][0]['line_cost'])->toBe(0.00002)
        ->and($preview['ingredient_total'])->toBe(0.00002)
        ->and($preview['total_cost'])->toBe(0.00002);
});
