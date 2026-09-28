<?php

use App\Services\RecipeCompositionExportDataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('projects soap raw inputs on fresh-mixture mass and uses the incorporated INCI variant without normalization', function (): void {
    $snapshot = [
        'draft' => [
            'oilUnit' => 'g',
            'oilWeight' => 1000,
            'kohPurity' => 100,
            'phaseItems' => [
                'saponified_oils' => [[
                    'id' => 101,
                    'ingredient_id' => 501,
                    'name' => 'Olive Oil',
                    'inci_name' => 'Olea Europaea Fruit Oil',
                    'weight' => 1000,
                    'percentage' => 100,
                    'note' => 'cold pressed',
                ]],
                'additives' => [[
                    'id' => 201,
                    'ingredient_id' => 601,
                    'name' => 'Clay A',
                    'inci_name' => 'Silica',
                    'weight' => 10,
                    'percentage' => 1,
                    'note' => 'first source',
                ]],
                'fragrance' => [[
                    'id' => 202,
                    'ingredient_id' => 602,
                    'name' => 'Clay B',
                    'inci_name' => 'Silica',
                    'weight' => 10,
                    'percentage' => 1,
                    'note' => 'second source',
                ]],
                'lye_water' => [[
                    'id' => 303,
                    'ingredient_id' => 703,
                    'name' => 'Hydrosol',
                    'inci_name' => 'Rosa Damascena Flower Water',
                    'weight' => 0,
                    'percentage' => 25,
                    'note' => 'dilution substitution',
                ]],
            ],
        ],
        'calculation' => [
            'lye' => [
                'selected' => ['naoh_weight' => 135, 'koh_to_weigh' => 0],
                'water' => ['weight' => 300],
            ],
        ],
        'labeling' => [
            'default_variant_key' => 'saponified_with_superfat',
            'list_variants' => [
                [
                    'key' => 'saponified_with_superfat',
                    'final_label_text' => 'Selected label list',
                    'ingredient_rows' => [[
                        'label' => 'Selected variant material',
                        'weight' => 1455,
                        'percent_of_formula' => 100,
                        'kind' => 'ingredient',
                        'source_ingredients' => ['Selected only'],
                    ]],
                ],
                [
                    'key' => 'incorporated_ingredients',
                    'final_label_text' => 'Calculated incorporated INCI',
                    'ingredient_rows' => [
                        [
                            'label' => 'Partly disclosed blend component A',
                            'weight' => 200,
                            'percent_of_formula' => 99,
                            'kind' => 'ingredient',
                            'source_ingredients' => ['Blend A', 'Blend B'],
                        ],
                        [
                            'label' => 'Partly disclosed blend component B',
                            'weight' => 100,
                            'percent_of_formula' => 1,
                            'kind' => 'lye_liquid',
                            'source_ingredients' => ['Hydrosol'],
                        ],
                    ],
                ],
            ],
            'declaration_rows' => [[
                'label' => 'Overlapping allergen declaration',
                'weight' => 1455,
            ]],
            'final_ingredient_list' => ['final_text' => 'Authored label text'],
        ],
    ];
    $soapOutput = [
        'basis_weight' => 1034.2,
        'residual_water_percentage' => 11,
        'rows' => [['name' => 'Calculated component', 'weight' => 1034.2, 'percentage' => 100, 'role' => 'base', 'sources' => ['Olive Oil']]],
    ];
    $document = [
        'family' => 'soap',
        'unit' => 'g',
        'basis_weight' => 1000,
        'percentage_basis' => 'oils',
        'identity' => ['name' => 'Test soap', 'state' => 'current_working_copy'],
        'metadata' => ['version_number' => null, 'generated_at' => '2026-09-28 12:00'],
        'settings' => [['label' => 'Water setting', 'value' => '30% of oils']],
        'soap_output' => $soapOutput,
        'label_text' => 'Authored label text',
    ];

    $composition = app(RecipeCompositionExportDataBuilder::class)->build($snapshot, $document);
    $rawRows = collect($composition['incorporated']['raw_material_rows']);
    $rowsByName = $rawRows->keyBy('name');
    $inciRows = collect($composition['incorporated']['inci_rows']);

    expect(round($composition['incorporated']['basis_weight'], 4))->toBe(1455.0)
        ->and(round($rawRows->sum('quantity'), 4))->toBe(1455.0)
        ->and(round($rawRows->sum('percentage'), 8))->toBe(100.0)
        ->and($rowsByName['Olive Oil']['ingredient_id'])->toBe(501)
        ->and($rowsByName['Olive Oil']['recorded_inci'])->toBe('Olea Europaea Fruit Oil')
        ->and($rowsByName['Olive Oil']['note'])->toBe('cold pressed')
        ->and($rowsByName['NaOH']['quantity'])->toBe(135.0)
        ->and($rowsByName['NaOH']['ingredient_id'])->toBeNull()
        ->and($rowsByName['Water']['quantity'])->toBe(225.0)
        ->and($rowsByName['Hydrosol']['quantity'])->toBe(75.0)
        ->and($rowsByName['Hydrosol']['ingredient_id'])->toBe(703)
        ->and($rowsByName['Clay A']['recorded_inci'])->toBe($rowsByName['Clay B']['recorded_inci'])
        ->and($rowsByName['Clay A']['ingredient_id'])->not->toBe($rowsByName['Clay B']['ingredient_id'])
        ->and($inciRows->pluck('name')->all())->toBe([
            'Partly disclosed blend component A',
            'Partly disclosed blend component B',
        ])
        ->and($inciRows->sum('quantity'))->toBe(300.0)
        ->and($composition['incorporated']['accounted_inci_weight'])->toBe(300.0)
        ->and(abs($composition['incorporated']['accounted_inci_percentage'] - (300 / 1455 * 100)))->toBeLessThan(0.0000001)
        ->and($composition['label_context']['generated_inci_text'])->toBe('Calculated incorporated INCI')
        ->and($composition['label_context']['authored_label_text'])->toBe('Authored label text')
        ->and($composition['calculated_output'])->toBe($soapOutput)
        ->and($composition['review_note'])->toBe('Calculation support for independent review. The assessor must verify inputs and recalculate.')
        ->and($composition['assumption_note'])->toContain('11% of cured mass')
        ->and($composition['assumption_note'])->toContain('not measured water content')
        ->and(abs($inciRows->first()['percentage'] - (200 / 1455 * 100)))->toBeLessThan(0.0000001);
});

it('preserves dual-lye quantities, available canonical identity, and a visible KOH purity remainder', function (): void {
    $snapshot = [
        'draft' => [
            'oilUnit' => 'g',
            'oilWeight' => 1000,
            'kohPurity' => 90,
            'phaseItems' => [
                'saponified_oils' => [[
                    'id' => 101,
                    'ingredient_id' => 501,
                    'name' => 'Olive Oil',
                    'inci_name' => 'Olea Europaea Fruit Oil',
                    'weight' => 1000,
                    'percentage' => 100,
                ]],
            ],
        ],
        'calculation' => [
            'lye' => [
                'selected' => ['naoh_weight' => 15, 'koh_to_weigh' => 44.444],
                'identities' => [
                    'naoh' => ['ingredient_id' => 810, 'name' => 'Canonical sodium hydroxide', 'inci_name' => 'Sodium Hydroxide'],
                    'koh' => ['ingredient_id' => 811, 'name' => 'Canonical potassium hydroxide', 'inci_name' => 'Potassium Hydroxide'],
                ],
                'water' => ['weight' => 0],
            ],
        ],
        'labeling' => ['list_variants' => [['key' => 'incorporated_ingredients', 'ingredient_rows' => []]]],
    ];
    $document = ['family' => 'soap', 'unit' => 'g', 'basis_weight' => 1000, 'percentage_basis' => 'oils', 'soap_output' => null];

    $composition = app(RecipeCompositionExportDataBuilder::class)->build($snapshot, $document);
    $alkaliRows = collect($composition['incorporated']['raw_material_rows'])->where('role', 'alkali')->values();

    expect($composition['incorporated']['basis_weight'])->toBe(1059.444)
        ->and($alkaliRows)->toHaveCount(2)
        ->and($alkaliRows[0]['ingredient_id'])->toBe(810)
        ->and($alkaliRows[0]['name'])->toBe('Canonical sodium hydroxide')
        ->and($alkaliRows[1]['ingredient_id'])->toBe(811)
        ->and($alkaliRows[1]['quantity'])->toBe(44.444)
        ->and($alkaliRows[1]['name'])->toContain('Canonical potassium hydroxide')
        ->and($alkaliRows[1]['name'])->toContain('90% purity')
        ->and($composition['warnings'])->toContain('composition_koh_purity_remainder_unrepresented');

    $snapshot['draft']['kohPurity'] = 100;
    $compositionAtFullPurity = app(RecipeCompositionExportDataBuilder::class)->build($snapshot, $document);
    $alkaliRowsAtFullPurity = collect($compositionAtFullPurity['incorporated']['raw_material_rows'])
        ->where('role', 'alkali')
        ->values();

    expect($alkaliRowsAtFullPurity[1]['name'])->toContain('Canonical potassium hydroxide')
        ->and($alkaliRowsAtFullPurity[1]['name'])->toContain('100% purity')
        ->and($compositionAtFullPurity['warnings'])->not->toContain('composition_koh_purity_remainder_unrepresented');
});

it('keeps cosmetic authored totals and percentages incomplete without a duplicate INCI breakdown', function (): void {
    $snapshot = [
        'draft' => [
            'oilUnit' => 'g',
            'oilWeight' => 1000,
            'phases' => [
                ['key' => 'phase_a', 'name' => 'Water phase'],
                ['key' => 'phase_b', 'name' => 'Oil phase'],
            ],
            'phaseItems' => [
                'phase_a' => [[
                    'id' => 1,
                    'ingredient_id' => 101,
                    'name' => 'Glycerin',
                    'inci_name' => 'Glycerin',
                    'weight' => 620,
                    'percentage' => 62,
                ]],
                'phase_b' => [[
                    'id' => 2,
                    'ingredient_id' => 102,
                    'name' => 'Botanical blend',
                    'inci_name' => null,
                    'weight' => 180,
                    'percentage' => 18,
                ]],
            ],
        ],
        'labeling' => [
            'print_ingredient_list_text' => 'Authored cosmetic label',
            'default_variant_key' => 'incorporated_ingredients',
            'list_variants' => [[
                'key' => 'incorporated_ingredients',
                'final_label_text' => 'Calculated expanded cosmetic label',
                'ingredient_rows' => [
                    [
                        'label' => 'Glycerin',
                        'weight' => 620,
                        'percent_of_formula' => 62,
                        'kind' => 'ingredient',
                        'source_ingredients' => ['Glycerin'],
                    ],
                    [
                        'label' => 'Botanical extract component',
                        'weight' => 100,
                        'percent_of_formula' => 10,
                        'kind' => 'ingredient',
                        'source_ingredients' => ['Botanical blend'],
                    ],
                    [
                        'label' => 'Carrier component',
                        'weight' => 50,
                        'percent_of_formula' => 5,
                        'kind' => 'ingredient',
                        'source_ingredients' => ['Botanical blend'],
                    ],
                ],
            ]],
        ],
    ];
    $document = [
        'family' => 'cosmetic',
        'unit' => 'g',
        'basis_weight' => 1000,
        'percentage_basis' => 'formula',
        'soap_output' => null,
    ];

    $composition = app(RecipeCompositionExportDataBuilder::class)->build($snapshot, $document);
    $rawRows = collect($composition['incorporated']['raw_material_rows']);

    expect($composition['family'])->toBe('cosmetic')
        ->and($composition['incorporated']['basis_weight'])->toBe(1000.0)
        ->and($composition['incorporated']['percentage_basis_weight'])->toBe(1000.0)
        ->and($composition['incorporated']['actual_mass'])->toBe(800.0)
        ->and($rawRows->pluck('percentage')->all())->toBe([62.0, 18.0])
        ->and($rawRows->sum('percentage'))->toBe(80.0)
        ->and(collect($composition['incorporated']['inci_rows'])->pluck('percentage')->all())->toBe([62.0, 10.0, 5.0])
        ->and($composition['incorporated']['accounted_inci_weight'])->toBe(770.0)
        ->and($composition['incorporated']['accounted_inci_percentage'])->toBe(77.0)
        ->and($composition['calculated_output'])->toBeNull()
        ->and($composition['label_context']['authored_label_text'])->toBe('Authored cosmetic label')
        ->and($composition['warnings'])->toContain('composition_recorded_inci_missing')
        ->and($rawRows[1]['recorded_inci'])->toBe('')
        ->and($rawRows[1]['recorded_inci_missing'])->toBeTrue();

    $redundantSnapshot = $snapshot;
    $redundantSnapshot['draft']['phaseItems']['phase_b'][0]['inci_name'] = 'Botanical blend INCI';
    $redundantSnapshot['labeling']['list_variants'][0]['ingredient_rows'] = [
        [
            'label' => 'Glycerin',
            'weight' => 620,
            'kind' => 'ingredient',
            'source_ingredients' => ['Glycerin'],
        ],
        [
            'label' => 'Botanical blend INCI',
            'weight' => 180,
            'kind' => 'ingredient',
            'source_ingredients' => ['Botanical blend'],
        ],
    ];
    $redundantComposition = app(RecipeCompositionExportDataBuilder::class)->build($redundantSnapshot, $document);

    expect($redundantComposition['incorporated']['inci_rows'])->toBe([]);
});

it('preserves tiny kilogram raw and INCI masses from float and decimal-string inputs', function (): void {
    $snapshot = [
        'draft' => [
            'oilUnit' => 'kg',
            'oilWeight' => '0.00102',
            'phases' => [['key' => 'phase_a', 'name' => 'Formula']],
            'phaseItems' => [
                'phase_a' => [
                    [
                        'id' => 1,
                        'ingredient_id' => 101,
                        'name' => 'Base',
                        'inci_name' => 'Base INCI',
                        'weight' => '0.001',
                        'percentage' => '98.0392156863',
                    ],
                    [
                        'id' => 2,
                        'ingredient_id' => 102,
                        'name' => 'Trace blend',
                        'inci_name' => 'Trace blend INCI',
                        'weight' => null,
                        'percentage' => '1.9607843137',
                    ],
                ],
            ],
        ],
        'labeling' => [
            'list_variants' => [[
                'key' => 'incorporated_ingredients',
                'ingredient_rows' => [
                    [
                        'label' => 'Trace constituent A',
                        'weight' => 0.00001,
                        'kind' => 'ingredient',
                        'source_ingredients' => ['Trace blend'],
                    ],
                    [
                        'label' => 'Trace constituent B',
                        'weight' => '0.00001',
                        'kind' => 'ingredient',
                        'source_ingredients' => ['Trace blend'],
                    ],
                ],
            ]],
        ],
    ];
    $document = [
        'family' => 'cosmetic',
        'unit' => 'kg',
        'basis_weight' => 0.001,
        'percentage_basis' => 'formula',
        'soap_output' => null,
    ];

    foreach ([0.00002, '0.00002'] as $tinyRawMass) {
        $snapshot['draft']['phaseItems']['phase_a'][1]['weight'] = $tinyRawMass;
        $composition = app(RecipeCompositionExportDataBuilder::class)->build($snapshot, $document);
        $rawRows = collect($composition['incorporated']['raw_material_rows'])->keyBy('name');
        $inciRows = collect($composition['incorporated']['inci_rows']);

        expect($rawRows['Trace blend']['quantity'])->toBe(0.00002)
            ->and($composition['incorporated']['actual_mass'])->toBe(0.00102)
            ->and($composition['incorporated']['basis_weight'])->toBe(0.00102)
            ->and($inciRows->pluck('quantity')->all())->toBe([0.00001, 0.00001])
            ->and($composition['incorporated']['accounted_inci_weight'])->toBe(0.00002)
            ->and(abs($composition['incorporated']['accounted_inci_percentage'] - (0.00002 / 0.00102 * 100)))
            ->toBeLessThan(0.0000001);
    }
});
