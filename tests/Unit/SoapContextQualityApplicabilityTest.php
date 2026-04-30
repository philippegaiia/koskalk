<?php

use App\Services\SoapCalculationService;

it('derives bar soap context and keeps bar quality metrics applicable', function () {
    $result = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => 5,
        'lye_type' => 'naoh',
    ]);

    expect($result['soap_context'])
        ->toMatchArray([
            'type' => 'bar',
            'koh_percentage' => 0.0,
            'bar_context' => 1.0,
            'liquid_context' => 0.0,
            'bar_metrics_applicable' => true,
        ])
        ->and($result['properties']['quality_applicability']['unmolding_firmness']['display'])->toBe('score')
        ->and($result['properties']['quality_applicability']['cured_hardness']['applies'])->toBeTrue()
        ->and($result['properties']['warnings'])->toBe([]);
});

it('marks high-koh soap as soft or liquid context and hides bar-only metrics', function () {
    $result = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => 5,
        'lye_type' => 'dual',
        'dual_lye_koh_percentage' => 45,
    ]);

    expect($result['soap_context']['type'])->toBe('soft_or_liquid')
        ->and($result['soap_context']['koh_percentage'])->toBe(45.0)
        ->and($result['soap_context']['bar_metrics_applicable'])->toBeFalse()
        ->and($result['properties']['quality_applicability']['unmolding_firmness']['display'])->toBe('not_applicable')
        ->and($result['properties']['quality_applicability']['unmolding_firmness']['reason'])->toContain('liquid/high-KOH')
        ->and($result['properties']['quality_applicability']['cleansing_strength']['display'])->toBe('tendency')
        ->and($result['properties']['warnings'])->toContain('high_koh_context_process_dependent');
});

it('allows negative superfat only for liquid or high-koh soap with neutralization warning', function () {
    expect(fn () => (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -5,
        'lye_type' => 'naoh',
    ]))->toThrow(InvalidArgumentException::class, 'Negative superfat is only supported for liquid or high-KOH soap workflows.');

    $liquidResult = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -5,
        'lye_type' => 'koh',
    ]);

    expect($liquidResult['soap_context']['type'])->toBe('liquid')
        ->and($liquidResult['lye']['superfat_percentage'])->toBe(-5.0)
        ->and($liquidResult['lye']['selected']['koh_weight'])->toBeGreaterThan($liquidResult['lye']['koh']['theoretical'])
        ->and($liquidResult['properties']['warnings'])->toContain('negative_superfat_requires_neutralization_and_ph_control');
});

it('warns for positive liquid soap superfat above practical separation threshold', function () {
    $result = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => 4,
        'lye_type' => 'koh',
    ]);

    expect($result['soap_context']['type'])->toBe('liquid')
        ->and($result['properties']['warnings'])->toContain('positive_liquid_superfat_may_cloud_or_separate');
});

function soapTestOil(): array
{
    return [
        'name' => 'Balanced test oil',
        'weight' => 1000,
        'koh_sap_value' => 0.2,
        'fatty_acid_profile' => [
            'lauric' => 12,
            'myristic' => 5,
            'palmitic' => 25,
            'stearic' => 8,
            'oleic' => 35,
            'linoleic' => 10,
            'linolenic' => 1,
            'ricinoleic' => 2,
        ],
    ];
}
