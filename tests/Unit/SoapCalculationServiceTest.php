<?php

use App\Services\SoapCalculationService;
use App\SoapSap;

it('calculates soap lye, water, glycerine, and quality metrics from oil data', function () {
    $service = new SoapCalculationService;

    $result = $service->calculate([
        [
            'name' => 'Olive Oil',
            'weight' => 500,
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
            'weight' => 500,
            'koh_sap_value' => 0.257,
            'fatty_acid_profile' => [
                'lauric' => 48,
                'myristic' => 19,
                'palmitic' => 9,
                'stearic' => 3,
                'oleic' => 8,
                'linoleic' => 2,
            ],
        ],
    ], [
        'superfat' => 5,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
    ]);

    expect($result['totals']['oils_weight'])->toBe(1000.0)
        ->and($result['lye']['naoh']['theoretical'])->toBe(158.6425)
        ->and($result['lye']['naoh']['adjusted'])->toBe(150.7104)
        ->and($result['lye']['koh']['theoretical'])->toBe(222.5)
        ->and($result['lye']['koh']['adjusted'])->toBe(211.375)
        ->and($result['lye']['water']['weight'])->toBe(380.0)
        ->and($result['lye']['glycerine']['naoh_adjusted'])->toBe(115.67)
        ->and($result['lye']['selected']['type'])->toBe('naoh')
        ->and($result['lye']['selected']['naoh_weight'])->toBe(150.7104)
        ->and($result['lye']['selected']['koh_weight'])->toBe(0.0)
        ->and($result['properties']['fatty_acid_profile']['lauric'])->toBe(24.0)
        ->and($result['properties']['fatty_acid_profile']['oleic'])->toBe(39.5)
        ->and($result['properties']['fatty_acid_groups']['vs'])->toBe(33.5)
        ->and($result['properties']['fatty_acid_groups']['hs'])->toBe(13.5)
        ->and($result['properties']['superfat_effects']['base_cleansing_potential'])->toBe(57.275)
        ->and($result['properties']['superfat_effects']['superfat_buffer'])->toBe(7.4775)
        ->and($result['properties']['superfat_effects']['effective_cleansing'])->toBe(49.7975)
        ->and($result['properties']['qualities']['hardness'])->toBe(47.0)
        ->and($result['properties']['qualities']['cleansing'])->toBe(33.5)
        ->and($result['properties']['qualities']['conditioning'])->toBe(45.5)
        ->and($result['properties']['qualities']['bubbly'])->toBe(33.5)
        ->and($result['properties']['qualities']['creamy'])->toBe(13.5)
        ->and($result['properties']['qualities']['unmolding_firmness'])->toBe(43.5)
        ->and($result['properties']['qualities']['cured_hardness'])->toBe(28.425)
        ->and($result['properties']['qualities']['longevity'])->toBe(17.0)
        ->and($result['properties']['qualities']['cleansing_strength'])->toBe(49.7975)
        ->and($result['properties']['qualities']['mildness'])->toBe(34.5925)
        ->and($result['properties']['qualities']['bubble_volume'])->toBe(31.125)
        ->and($result['properties']['qualities']['creamy_lather'])->toBe(14.12)
        ->and($result['properties']['qualities']['lather_stability'])->toBe(18.56)
        ->and($result['properties']['qualities']['conditioning_feel'])->toBe(27.3161)
        ->and($result['properties']['qualities']['dos_risk'])->toBe(8.1)
        ->and($result['properties']['qualities']['slime_risk'])->toBe(9.51)
        ->and($result['properties']['qualities']['cure_speed'])->toBe(35.385)
        ->and($result['properties']['qualities']['iodine'])->toBe(44.362)
        ->and($result['properties']['qualities']['ins'])->toBe(178.138);
});

it('derives naoh from the canonical koh sap value', function () {
    expect(SoapSap::deriveNaohFromKoh(0.188))->toBe(0.134044)
        ->and(SoapSap::deriveNaohFromKoh(0.257))->toBe(0.183241);
});

it('normalizes professional-style koh sap inputs', function () {
    expect(SoapSap::normalizeKohSapInput(245))->toBe(0.245)
        ->and(round(SoapSap::deriveNaohFromKoh(245), 6))->toBe(0.174685)
        ->and(SoapSap::adjustKohForPurity(100, 90))->toBe(111.11111111111111);
});

it('supports lye ratio and lye concentration water modes', function () {
    $service = new SoapCalculationService;

    $ratioResult = $service->calculate([
        [
            'weight' => 1000,
            'koh_sap_value' => 0.196,
        ],
    ], [
        'superfat' => 0,
        'water_mode' => 'lye_ratio',
        'water_value' => 2.2,
    ]);

    $concentrationResult = $service->calculate([
        [
            'weight' => 1000,
            'koh_sap_value' => 0.196,
        ],
    ], [
        'superfat' => 0,
        'water_mode' => 'lye_concentration',
        'water_value' => 33,
    ]);

    expect($ratioResult['lye']['water']['weight'])->toBe(307.4456)
        ->and($concentrationResult['lye']['water']['weight'])->toBe(283.7308);
});

it('calculates iodine and ins transparently from fatty acids and koh sap', function () {
    $service = new SoapCalculationService;

    $result = $service->calculate([
        [
            'weight' => 1000,
            'koh_sap_value' => 0.196,
            'fatty_acid_profile' => [
                'oleic' => 35,
                'linoleic' => 20,
                'linolenic' => 5,
            ],
        ],
    ]);

    expect($result['properties']['qualities']['iodine'])->toBe(77.82)
        ->and($result['properties']['qualities']['ins'])->toBe(118.18);
});

it('accepts professional-scale koh sap values in calculations', function () {
    $service = new SoapCalculationService;

    $result = $service->calculate([
        [
            'weight' => 1000,
            'koh_sap_value' => 245,
        ],
    ], [
        'superfat' => 5,
    ]);

    expect($result['lye']['koh']['theoretical'])->toBe(245.0)
        ->and($result['lye']['koh']['adjusted'])->toBe(232.75)
        ->and($result['lye']['naoh']['adjusted'])->toBe(165.9507);
});

it('supports dual lye selection and koh purity adjustments', function () {
    $service = new SoapCalculationService;

    $result = $service->calculate([
        [
            'weight' => 1000,
            'koh_sap_value' => 0.245,
        ],
    ], [
        'superfat' => 5,
        'lye_type' => 'dual',
        'dual_lye_koh_percentage' => 40,
        'koh_purity_percentage' => 90,
        'water_mode' => 'lye_ratio',
        'water_value' => 2,
    ]);

    expect($result['lye']['selected']['type'])->toBe('dual')
        ->and($result['lye']['selected']['naoh_weight'])->toBe(99.5704)
        ->and($result['lye']['selected']['koh_weight'])->toBe(93.1)
        ->and($result['lye']['selected']['koh_to_weigh'])->toBe(103.4444)
        ->and($result['lye']['selected']['total_active_lye_weight'])->toBe(192.6705)
        ->and($result['lye']['water']['weight'])->toBe(385.341);
});

it('caps cleansing strength at 100 for very cleansing formulas', function () {
    $service = new SoapCalculationService;

    $result = $service->calculate([
        [
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
    ], [
        'superfat' => 5,
    ]);

    expect($result['properties']['superfat_effects']['effective_cleansing'])->toBeGreaterThan(100)
        ->and($result['properties']['qualities']['cleansing_strength'])->toBe(100.0)
        ->and($result['properties']['qualities']['mildness'])->toBe(0.0);
});
