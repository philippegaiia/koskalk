<?php

use App\Services\SoapCalculationService;

it('applies high superfat as a physical softening and lather penalty', function () {
    $service = new SoapCalculationService;

    $lowSuperfat = superfatQualityResult($service, 5);
    $highSuperfat = superfatQualityResult($service, 20);

    expect($highSuperfat['qualities']['cleansing_strength'])->toBeLessThan($lowSuperfat['qualities']['cleansing_strength'])
        ->and($highSuperfat['qualities']['mildness'])->toBeGreaterThan($lowSuperfat['qualities']['mildness'])
        ->and($highSuperfat['qualities']['unmolding_firmness'])->toBeLessThan($lowSuperfat['qualities']['unmolding_firmness'])
        ->and($highSuperfat['qualities']['cured_hardness'])->toBeLessThan($lowSuperfat['qualities']['cured_hardness'])
        ->and($highSuperfat['qualities']['longevity'])->toBeLessThan($lowSuperfat['qualities']['longevity'])
        ->and($highSuperfat['qualities']['bubble_volume'])->toBeLessThan($lowSuperfat['qualities']['bubble_volume'])
        ->and($highSuperfat['qualities']['lather_stability'])->toBeLessThan($lowSuperfat['qualities']['lather_stability'])
        ->and($highSuperfat['superfat_effects']['superfat_softening'])->toBeGreaterThan(0.0)
        ->and($highSuperfat['superfat_effects']['superfat_lather_penalty'])->toBeGreaterThan(0.0);
});

it('treats polyunsaturated fatty acids above fifteen percent as high dos risk', function () {
    $service = new SoapCalculationService;

    $moderatePu = puRiskResult($service, 10);
    $highPu = puRiskResult($service, 16);
    $veryHighPu = puRiskResult($service, 22);

    expect($moderatePu['qualities']['dos_risk'])->toBeLessThan(35.0)
        ->and($highPu['qualities']['dos_risk'])->toBeGreaterThan(45.0)
        ->and($veryHighPu['qualities']['dos_risk'])->toBeGreaterThan($highPu['qualities']['dos_risk'])
        ->and($veryHighPu['qualities']['dos_risk'])->toBeGreaterThan(70.0)
        ->and($highPu['warnings'])->toContain('high_polyunsaturated_dos_risk')
        ->and($veryHighPu['warnings'])->toContain('very_high_polyunsaturated_dos_risk');
});

it('uses lye concentration as a process modifier for unmolding and cure speed', function () {
    $service = new SoapCalculationService;

    $highWater = waterProcessQualityResult($service, 'percent_of_oils', 38);
    $concentrated = waterProcessQualityResult($service, 'lye_concentration', 33);

    expect($concentrated['unmolding_firmness'])->toBeGreaterThan($highWater['unmolding_firmness'])
        ->and($concentrated['cure_speed'])->toBeGreaterThan($highWater['cure_speed']);
});

function superfatQualityResult(SoapCalculationService $service, float $superfat): array
{
    $result = $service->calculate([
        soapQualityTuningCoconutOil(),
    ], [
        'superfat' => $superfat,
        'water_mode' => 'percent_of_oils',
        'water_value' => 33,
    ]);

    return [
        'qualities' => $result['properties']['qualities'],
        'superfat_effects' => $result['properties']['superfat_effects'],
    ];
}

function puRiskResult(SoapCalculationService $service, float $pu): array
{
    $linoleic = $pu - 1;

    $result = $service->calculate([
        [
            'name' => 'PU test blend',
            'weight' => 1000,
            'koh_sap_value' => 0.195,
            'fatty_acid_profile' => [
                'palmitic' => 16,
                'stearic' => 7,
                'oleic' => 55 - max(0, $pu - 10),
                'linoleic' => $linoleic,
                'linolenic' => 1,
            ],
        ],
    ], [
        'superfat' => 5,
    ]);

    return [
        'qualities' => $result['properties']['qualities'],
        'warnings' => $result['properties']['warnings'],
    ];
}

function waterProcessQualityResult(SoapCalculationService $service, string $waterMode, float $waterValue): array
{
    return $service->calculate([
        [
            'name' => 'High oleic test bar',
            'weight' => 1000,
            'koh_sap_value' => 0.19,
            'fatty_acid_profile' => [
                'palmitic' => 12,
                'stearic' => 4,
                'oleic' => 68,
                'linoleic' => 10,
            ],
        ],
    ], [
        'superfat' => 5,
        'water_mode' => $waterMode,
        'water_value' => $waterValue,
    ])['properties']['qualities'];
}

function soapQualityTuningCoconutOil(): array
{
    return [
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
    ];
}
