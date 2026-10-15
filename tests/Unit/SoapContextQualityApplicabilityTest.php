<?php

use App\Services\SoapCalculationService;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FattyAcidSeeder::class);
});

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

it('allows negative bar superfat down to -10% and flags the excess lye', function () {
    $result = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -10,
        'lye_type' => 'naoh',
    ]);

    expect($result['lye']['superfat_percentage'])->toBe(-10.0)
        ->and($result['lye']['effective_superfat_percentage'])->toBe(-10.0)
        ->and($result['lye']['superfat_status'])->toBe('excess_lye')
        ->and($result['properties']['warnings'])->toContain('effective_superfat_leaves_excess_lye');

    expect(fn () => (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -10.5,
        'lye_type' => 'naoh',
    ]))->toThrow(InvalidArgumentException::class, 'Bar soap superfat cannot be below -10%.');
});

it('limits liquid soap superfat by KOH purity', function () {
    $koh90 = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -20,
        'lye_type' => 'koh',
        'koh_purity_percentage' => 90,
    ]);

    expect($koh90['lye']['superfat_percentage'])->toBe(-20.0)
        ->and($koh90['properties']['warnings'])->not->toContain('effective_superfat_leaves_excess_lye');

    expect(fn () => (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -20.5,
        'lye_type' => 'koh',
        'koh_purity_percentage' => 90,
    ]))->toThrow(InvalidArgumentException::class, 'Liquid soap superfat cannot be below -20% with KOH 90%.')
        ->and(fn () => (new SoapCalculationService)->calculate([
            soapTestOil(),
        ], [
            'superfat' => -10.5,
            'lye_type' => 'koh',
            'koh_purity_percentage' => 100,
        ]))->toThrow(InvalidArgumentException::class, 'Liquid soap superfat cannot be below -10% with KOH 100%.');
});

it('reads saved formulas below the authoring floors down to the historical limit', function () {
    $saved = (new SoapCalculationService)->calculate([soapTestOil()], [
        'superfat' => -22,
        'lye_type' => 'koh',
        'koh_purity_percentage' => 100,
        'enforce_superfat_floors' => false,
    ]);

    expect($saved['lye']['superfat_percentage'])->toBe(-22.0)
        ->and(fn () => (new SoapCalculationService)->calculate([soapTestOil()], [
            'superfat' => -26,
            'lye_type' => 'koh',
            'enforce_superfat_floors' => false,
        ]))->toThrow(InvalidArgumentException::class, 'The superfat percentage must not be below -25%.');
});

it('saponifies no more than the whole oil when superfat is negative', function () {
    $service = new SoapCalculationService;
    $full = $service->calculateOilReactionProducts(1000, 0.2, ['superfat' => 0, 'lye_type' => 'naoh']);
    $lyeHeavy = $service->calculateOilReactionProducts(1000, 0.2, ['superfat' => -5, 'lye_type' => 'naoh']);

    expect($lyeHeavy['saponified_weight'])->toBe(1000.0)
        ->and($lyeHeavy['soap_salt_weight'])->toBe($full['soap_salt_weight'])
        ->and($lyeHeavy['glycerine_weight'])->toBe($full['glycerine_weight'])
        ->and($lyeHeavy['excess_naoh_weight'])->toBe(round($lyeHeavy['selected_naoh_weight'] - $full['selected_naoh_weight'], 4))
        ->and(round($lyeHeavy['soap_salt_weight'] + $lyeHeavy['glycerine_weight'] + $lyeHeavy['excess_naoh_weight'], 3))
        ->toBe(round(1000 + $lyeHeavy['selected_naoh_weight'], 3));

    $formula = $service->calculate([soapTestOil()], ['superfat' => -5, 'lye_type' => 'naoh']);
    $neutral = $service->calculate([soapTestOil()], ['superfat' => 0, 'lye_type' => 'naoh']);

    expect($formula['lye']['selected']['glycerine_weight'])->toBe($neutral['lye']['selected']['glycerine_weight'])
        ->and($formula['lye']['excess']['naoh_weight'])->toBe(round($formula['lye']['selected']['naoh_weight'] - $neutral['lye']['selected']['naoh_weight'], 4))
        ->and($neutral['lye']['excess'])->toBe(['naoh_weight' => 0.0, 'koh_weight' => 0.0]);
});

it('shares the lye proportionally between main and post-reaction oils', function () {
    $service = new SoapCalculationService;
    $neutral = $service->calculate([soapTestOil()], ['superfat' => 0, 'lye_type' => 'naoh']);

    $absorbed = $service->calculate([soapTestOil()], ['superfat' => -5, 'lye_type' => 'naoh'], [
        ['weight' => 100, 'koh_sap_value' => 0.2],
    ]);

    expect($absorbed['lye']['post_reaction_saponified_fraction'])->toBe(0.95454545)
        ->and($absorbed['lye']['saponified_fraction'])->toBe(0.95454545)
        ->and($absorbed['lye']['effective_superfat_percentage'])->toBe(4.5455)
        ->and($absorbed['lye']['excess'])->toBe(['naoh_weight' => 0.0, 'koh_weight' => 0.0])
        ->and($absorbed['lye']['selected']['glycerine_weight'])
        ->toEqualWithDelta($neutral['lye']['selected']['glycerine_weight'] * 1.05, 0.0002);

    $positive = $service->calculate([soapTestOil()], ['superfat' => 5, 'lye_type' => 'naoh'], [
        ['weight' => 100, 'koh_sap_value' => 0.2],
    ]);

    expect($positive['lye']['saponified_fraction'])->toBe(0.86363636)
        ->and($positive['lye']['excess'])->toBe(['naoh_weight' => 0.0, 'koh_weight' => 0.0])
        ->and($positive['lye']['selected']['glycerine_weight'])
        ->toEqualWithDelta($neutral['lye']['selected']['glycerine_weight'] * 0.95, 0.0002);

    $overflow = $service->calculate([soapTestOil()], ['superfat' => -10, 'lye_type' => 'naoh'], [
        ['weight' => 20, 'koh_sap_value' => 0.2],
    ]);

    expect($overflow['lye']['post_reaction_saponified_fraction'])->toBe(1.0)
        ->and($overflow['lye']['excess']['naoh_weight'])->toBe(11.408)
        ->and($overflow['lye']['effective_superfat_percentage'])->toBeLessThan(0);
});

it('measures effective superfat against oils added after the reaction', function () {
    $result = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => -5,
        'lye_type' => 'naoh',
    ], [
        ['weight' => 100, 'koh_sap_value' => 0.2],
    ]);

    expect($result['lye']['superfat_percentage'])->toBe(-5.0)
        ->and($result['lye']['effective_superfat_percentage'])->toBe(4.5455)
        ->and($result['lye']['post_reaction_oils_weight'])->toBe(100.0)
        ->and($result['lye']['superfat_status'])->toBe('normal')
        ->and($result['properties']['warnings'])->not->toContain('effective_superfat_leaves_excess_lye');
});

it('marks a thin effective superfat as a low safety margin', function () {
    $result = (new SoapCalculationService)->calculate([
        soapTestOil(),
    ], [
        'superfat' => 1.5,
        'lye_type' => 'naoh',
    ]);

    expect($result['lye']['effective_superfat_percentage'])->toBe(1.5)
        ->and($result['lye']['superfat_status'])->toBe('low_margin');

    $boundary = (new SoapCalculationService)->calculate([soapTestOil()], ['superfat' => 2, 'lye_type' => 'naoh']);
    $normal = (new SoapCalculationService)->calculate([soapTestOil()], ['superfat' => 2.5, 'lye_type' => 'naoh']);

    expect($boundary['lye']['superfat_status'])->toBe('low_margin')
        ->and($normal['lye']['superfat_status'])->toBe('normal');
});

it('warns about neutralization for negative liquid soap superfat', function () {
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
