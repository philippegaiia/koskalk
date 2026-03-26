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
                'linoleic' => 10,
                'linolenic' => 2,
                'ricinoleic' => 6,
            ],
        ],
    ], [
        'superfat' => 8,
    ]);

    expect($result['properties']['fatty_acid_groups'])
        ->toBe([
            'vs' => 19.0,
            'hs' => 18.0,
            'mu' => 45.0,
            'pu' => 12.0,
            'sp' => 6.0,
            'sat' => 37.0,
            'unsat' => 63.0,
        ])
        ->and($result['properties']['superfat_effects']['base_cleansing_potential'])->toBe(28.9)
        ->and($result['properties']['superfat_effects']['superfat_buffer'])->toBe(7.424)
        ->and($result['properties']['superfat_effects']['effective_cleansing'])->toBe(21.476)
        ->and($result['properties']['superfat_effects']['dos_risk_modifier'])->toBe(0.96);
});
