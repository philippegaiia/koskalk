<?php

use App\Services\SoapCalculationService;

it('derives grouped fatty acid buckets including extended acids', function () {
    $service = new SoapCalculationService;

    $result = $service->calculate([
        [
            'weight' => 1000,
            'koh_sap_value' => 0.2,
            'fatty_acid_profile' => [
                'caprylic' => 1,
                'capric' => 3,
                'lauric' => 10,
                'myristic' => 5,
                'palmitic' => 12,
                'stearic' => 4,
                'arachidic' => 1,
                'behenic' => 1,
                'oleic' => 40,
                'palmitoleic' => 2,
                'gondoic' => 2,
                'erucic' => 1,
                'nervonic' => 2,
                'linoleic' => 10,
                'linolenic' => 2,
                'gamma_linolenic' => 1,
                'punicic' => 1,
                'ricinoleic' => 6,
                'lignoceric' => 1,
            ],
        ],
    ], [
        'superfat' => 8,
    ]);

    expect($result['properties']['fatty_acid_groups'])
        ->toBe([
            'vs' => 19.0,
            'hs' => 19.0,
            'mu' => 47.0,
            'pu' => 14.0,
            'sp' => 6.0,
            'sat' => 38.0,
            'unsat' => 67.0,
        ])
        ->and($result['properties']['superfat_effects']['base_cleansing_potential'])->toBe(28.8)
        ->and($result['properties']['superfat_effects']['superfat_buffer'])->toBe(7.408)
        ->and($result['properties']['superfat_effects']['effective_cleansing'])->toBe(21.392)
        ->and($result['properties']['superfat_effects']['dos_risk_modifier'])->toBe(1.12);
});
