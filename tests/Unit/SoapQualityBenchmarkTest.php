<?php

use App\Services\SoapCalculationService;

it('tracks expected benchmark archetype relationships for koskalk quality metrics', function () {
    $service = new SoapCalculationService;

    $castile = benchmarkQualities($service, [
        [
            'name' => 'Olive Oil',
            'weight' => 1000,
            'koh_sap_value' => 0.188,
            'fatty_acid_profile' => [
                'palmitic' => 13,
                'stearic' => 2,
                'oleic' => 71,
                'linoleic' => 10,
            ],
        ],
    ]);

    $coconut100 = benchmarkQualities($service, [
        [
            'name' => 'Coconut Oil',
            'weight' => 1000,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
    ]);

    $balanced = benchmarkQualities($service, [
        [
            'name' => 'Olive Oil',
            'weight' => 350,
            'koh_sap_value' => 0.188,
            'fatty_acid_profile' => [
                'palmitic' => 13,
                'stearic' => 2,
                'oleic' => 71,
                'linoleic' => 10,
            ],
        ],
        [
            'name' => 'Coconut Oil',
            'weight' => 250,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
        [
            'name' => 'Palm Oil',
            'weight' => 400,
            'koh_sap_value' => 0.199,
            'fatty_acid_profile' => [
                'palmitic' => 44,
                'stearic' => 5,
                'oleic' => 39,
                'linoleic' => 10,
            ],
        ],
    ]);

    $twentyCoconutPalmOlive = benchmarkQualities($service, [
        [
            'name' => 'Olive Oil',
            'weight' => 400,
            'koh_sap_value' => 0.188,
            'fatty_acid_profile' => [
                'palmitic' => 13,
                'stearic' => 2,
                'oleic' => 71,
                'linoleic' => 10,
            ],
        ],
        [
            'name' => 'Coconut Oil',
            'weight' => 200,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
        [
            'name' => 'Palm Oil',
            'weight' => 400,
            'koh_sap_value' => 0.199,
            'fatty_acid_profile' => [
                'palmitic' => 44,
                'stearic' => 5,
                'oleic' => 39,
                'linoleic' => 10,
            ],
        ],
    ]);

    $veryHighPalmWithCoconut = benchmarkQualities($service, [
        [
            'name' => 'Coconut Oil',
            'weight' => 200,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
        [
            'name' => 'Palm Oil',
            'weight' => 800,
            'koh_sap_value' => 0.199,
            'fatty_acid_profile' => [
                'palmitic' => 44,
                'stearic' => 5,
                'oleic' => 39,
                'linoleic' => 10,
            ],
        ],
    ]);

    $twentyCoconutWithCastor = benchmarkQualities($service, [
        [
            'name' => 'Olive Oil',
            'weight' => 350,
            'koh_sap_value' => 0.188,
            'fatty_acid_profile' => [
                'palmitic' => 13,
                'stearic' => 2,
                'oleic' => 71,
                'linoleic' => 10,
            ],
        ],
        [
            'name' => 'Coconut Oil',
            'weight' => 200,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
        [
            'name' => 'Palm Oil',
            'weight' => 400,
            'koh_sap_value' => 0.199,
            'fatty_acid_profile' => [
                'palmitic' => 44,
                'stearic' => 5,
                'oleic' => 39,
                'linoleic' => 10,
            ],
        ],
        [
            'name' => 'Castor Oil',
            'weight' => 50,
            'koh_sap_value' => 0.18,
            'fatty_acid_profile' => [
                'ricinoleic' => 90,
                'oleic' => 4,
                'linoleic' => 4,
                'palmitic' => 1,
                'stearic' => 1,
            ],
        ],
    ]);

    $excessCastorLowCoconut = benchmarkQualities($service, [
        [
            'name' => 'Olive Oil',
            'weight' => 800,
            'koh_sap_value' => 0.188,
            'fatty_acid_profile' => [
                'palmitic' => 13,
                'stearic' => 2,
                'oleic' => 71,
                'linoleic' => 10,
            ],
        ],
        [
            'name' => 'Coconut Oil',
            'weight' => 50,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
        [
            'name' => 'Castor Oil',
            'weight' => 150,
            'koh_sap_value' => 0.18,
            'fatty_acid_profile' => [
                'ricinoleic' => 90,
                'oleic' => 4,
                'linoleic' => 4,
                'palmitic' => 1,
                'stearic' => 1,
            ],
        ],
    ]);

    $highShea = benchmarkQualities($service, [
        [
            'name' => 'Olive Oil',
            'weight' => 400,
            'koh_sap_value' => 0.188,
            'fatty_acid_profile' => [
                'palmitic' => 13,
                'stearic' => 2,
                'oleic' => 71,
                'linoleic' => 10,
            ],
        ],
        [
            'name' => 'Coconut Oil',
            'weight' => 200,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'caprylic' => 8,
                'capric' => 7,
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
        [
            'name' => 'Shea Butter',
            'weight' => 400,
            'koh_sap_value' => 0.179,
            'fatty_acid_profile' => [
                'palmitic' => 4,
                'stearic' => 41,
                'oleic' => 46,
                'linoleic' => 6,
            ],
        ],
    ]);

    expect($coconut100['cleansing_strength'])->toBe(100.0)
        ->and($coconut100['bubble_volume'])->toBeGreaterThan($balanced['bubble_volume'])
        ->and($coconut100['cure_speed'])->toBeGreaterThan($balanced['cure_speed'])
        ->and($coconut100['mildness'])->toBeLessThan($balanced['mildness'])
        ->and($coconut100['cured_hardness'])->toBeGreaterThan($castile['cured_hardness'])
        ->and($coconut100['longevity'])->toBeGreaterThan(25.0)
        ->and($castile['mildness'])->toBeGreaterThan($balanced['mildness'])
        ->and($castile['slime_risk'])->toBeGreaterThan(35.0)
        ->and($castile['cure_speed'])->toBeLessThan(15.0)
        ->and($twentyCoconutPalmOlive['cure_speed'])->toBeGreaterThanOrEqual(35.0)
        ->and($twentyCoconutPalmOlive['unmolding_firmness'])->toBeGreaterThan(39.0)
        ->and($veryHighPalmWithCoconut['cure_speed'])->toBeBetween(45.0, 50.0)
        ->and($veryHighPalmWithCoconut['unmolding_firmness'])->toBeBetween(54.0, 58.0)
        ->and($twentyCoconutWithCastor['cure_speed'])->toBeGreaterThanOrEqual(33.0)
        ->and($twentyCoconutWithCastor['unmolding_firmness'])->toBeGreaterThan(39.0)
        ->and($twentyCoconutPalmOlive['bubble_volume'])->toBeBetween(23.0, 30.0)
        ->and($veryHighPalmWithCoconut['bubble_volume'])->toBeGreaterThan(18.0)
        ->and($veryHighPalmWithCoconut['lather_stability'])->toBeGreaterThan($twentyCoconutPalmOlive['lather_stability'])
        ->and($twentyCoconutWithCastor['lather_stability'])->toBeGreaterThan($twentyCoconutPalmOlive['lather_stability'])
        ->and($twentyCoconutWithCastor['bubble_volume'])->toBeGreaterThan($twentyCoconutPalmOlive['bubble_volume'])
        ->and($twentyCoconutWithCastor['bubble_volume'] - $twentyCoconutPalmOlive['bubble_volume'])->toBeLessThan(8.0)
        ->and($excessCastorLowCoconut['bubble_volume'])->toBeLessThan($twentyCoconutWithCastor['bubble_volume'])
        ->and($excessCastorLowCoconut['lather_stability'])->toBeLessThan(45.0)
        ->and($highShea['creamy_lather'])->toBeGreaterThan($balanced['creamy_lather'])
        ->and($highShea['cleansing_strength'])->toBeLessThan($balanced['cleansing_strength'])
        ->and($balanced['longevity'])->toBeBetween(35.0, 60.0)
        ->and($balanced['cleansing_strength'])->toBeBetween(15.0, 45.0);
});

/**
 * @param  array<int, array<string, mixed>>  $oils
 * @return array<string, float>
 */
function benchmarkQualities(SoapCalculationService $service, array $oils): array
{
    return $service->calculate($oils, [
        'superfat' => 5,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
    ])['properties']['qualities'];
}
