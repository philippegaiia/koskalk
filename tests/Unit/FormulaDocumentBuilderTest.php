<?php

use App\Services\FormulaDocumentBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('places soap lye and water inside the aligned formula sections', function () {
    $document = app(FormulaDocumentBuilder::class)->build(soapFormulaDocumentSnapshot(), [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'saved',
        'saved_at' => '2026-07-21 10:00',
    ]);

    expect($document['percentage_basis'])->toBe('oils')
        ->and(collect($document['sections'])->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'formula_additions'])
        ->and(collect($document['sections'][1]['rows'])->pluck('name')->all())
        ->toBe(['NaOH', 'Water'])
        ->and($document['sections'][1]['rows'][0]['percentage'])->toBe(13.5)
        ->and($document['sections'][1]['rows'][0]['weight'])->toBe(135.0);
});

it('keeps every selected lye in the aligned lye and water section', function (float $naohWeight, float $kohWeight, array $expectedRows) {
    $snapshot = soapFormulaDocumentSnapshot();
    $snapshot['calculation']['lye']['selected']['naoh_weight'] = $naohWeight;
    $snapshot['calculation']['lye']['selected']['koh_to_weigh'] = $kohWeight;

    $document = app(FormulaDocumentBuilder::class)->build($snapshot, [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'saved',
    ]);

    expect(collect($document['sections'][1]['rows'])->pluck('name')->all())->toBe($expectedRows);
})->with([
    'KOH only' => [0.0, 150.0, ['KOH', 'Water']],
    'dual lye' => [80.0, 60.0, ['NaOH', 'KOH', 'Water']],
]);

it('keeps cosmetic phases aligned on total formula percentage', function () {
    $document = app(FormulaDocumentBuilder::class)->build(cosmeticFormulaDocumentSnapshot(), [
        'name' => 'Face cream',
        'calculation_basis' => 'total_formula',
        'state' => 'saved',
    ]);

    expect($document['percentage_basis'])->toBe('formula')
        ->and($document['sections'][0]['label'])->toBe('Phase A')
        ->and($document['sections'][0]['rows'][0])
        ->toMatchArray(['name' => 'Water', 'percentage' => 70.0, 'weight' => 70.0]);
});

it('normalizes equivalent draft and saved snapshots to identical formula values', function () {
    $builder = app(FormulaDocumentBuilder::class);
    $snapshot = soapFormulaDocumentSnapshot();
    $draft = $builder->build($snapshot, [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'current',
    ]);
    $saved = $builder->build($snapshot, [
        'name' => 'Workshop soap',
        'calculation_basis' => 'oil_weight',
        'state' => 'saved',
        'saved_at' => '2026-07-21 10:00',
    ]);

    expect($draft['sections'])->toBe($saved['sections'])
        ->and($draft['results'])->toBe($saved['results'])
        ->and($draft['soap_output'])->toBe($saved['soap_output']);
});

function soapFormulaDocumentSnapshot(): array
{
    return [
        'draft' => [
            'oilWeight' => 1000,
            'oilUnit' => 'g',
            'lyeType' => 'naoh',
            'superfat' => 5,
            'waterMode' => 'percent_of_oils',
            'waterValue' => 30,
            'exposureMode' => 'rinse_off',
            'regulatoryRegime' => 'eu',
            'phaseItems' => [
                'saponified_oils' => [[
                    'name' => 'Olive oil',
                    'percentage' => 100,
                    'note' => null,
                    'is_user_owned' => false,
                ]],
                'additives' => [[
                    'name' => 'Clay',
                    'percentage' => 2,
                    'note' => 'Disperse first',
                    'is_user_owned' => false,
                ]],
                'fragrance' => [],
            ],
        ],
        'calculation' => [
            'lye' => [
                'superfat_percentage' => 5,
                'selected' => [
                    'naoh_weight' => 135,
                    'koh_to_weigh' => 0,
                    'glycerine_weight' => 70,
                ],
                'water' => ['weight' => 300],
            ],
            'properties' => [
                'qualities' => ['hardness' => 42],
                'fatty_acid_profile' => ['oleic' => 72],
            ],
        ],
        'labeling' => [
            'default_variant_key' => 'saponified_with_superfat',
            'print_ingredient_list_text' => 'SODIUM OLIVATE, AQUA',
            'warnings' => [],
            'list_variants' => [[
                'key' => 'saponified_with_superfat',
                'ingredient_rows' => [
                    ['label' => 'SODIUM OLIVATE', 'weight' => 900, 'kind' => 'saponified_oil', 'source_ingredients' => ['Olive oil']],
                    ['label' => 'AQUA', 'weight' => 300, 'kind' => 'ingredient', 'source_ingredients' => ['Water']],
                ],
                'declaration_rows' => [],
                'final_label_text' => 'SODIUM OLIVATE, AQUA',
            ]],
        ],
    ];
}

function cosmeticFormulaDocumentSnapshot(): array
{
    return [
        'draft' => [
            'oilWeight' => 100,
            'oilUnit' => 'g',
            'editMode' => 'percentage',
            'exposureMode' => 'leave_on',
            'regulatoryRegime' => 'eu',
            'phases' => [['key' => 'phase_a', 'name' => 'Phase A']],
            'phaseItems' => [
                'phase_a' => [[
                    'name' => 'Water',
                    'percentage' => 70,
                    'note' => null,
                    'is_user_owned' => false,
                ]],
            ],
        ],
        'calculation' => null,
        'labeling' => [
            'print_ingredient_list_text' => 'AQUA',
            'warnings' => [],
        ],
    ];
}
