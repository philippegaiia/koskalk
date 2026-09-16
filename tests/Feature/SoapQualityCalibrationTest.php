<?php

use App\Services\SoapCalculationService;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FattyAcidSeeder::class);
});

it('matches the two coconut cleansing anchors without a superfat plateau', function (): void {
    $service = app(SoapCalculationService::class);
    $oils = [calibrationOil('coconut', 1000)];
    $zero = $service->calculate($oils, ['superfat' => 0]);
    $high = $service->calculate($oils, ['superfat' => 20]);

    expect($zero['properties']['qualities']['cleansing_strength'])->toBe(100.0);
    expect($high['properties']['qualities']['cleansing_strength'])->toBeBetween(40.0, 45.0);

    $previous = 101.0;
    foreach (range(0, 25) as $superfat) {
        $score = $service->calculate($oils, ['superfat' => $superfat])['properties']['qualities']['cleansing_strength'];
        expect($score)->toBeLessThan($previous);
        $previous = $score;
    }
});

it('places twenty four to twenty five percent quick cleansing acids near forty at five percent superfat', function (float $quickAcids): void {
    $result = app(SoapCalculationService::class)->calculate([
        ['weight' => 1000, 'koh_sap_value' => 0.2, 'fatty_acid_profile' => [
            'lauric' => $quickAcids * 0.75, 'myristic' => $quickAcids * 0.25,
            'palmitic' => 25, 'oleic' => 75 - $quickAcids,
        ]],
    ], ['superfat' => 5]);

    expect($result['properties']['qualities']['cleansing_strength'])->toBeBetween(39.0, 42.0);
})->with([24.0, 25.0]);

it('puts nouveau savon comfortably inside the unmolding range and preserves dos', function (): void {
    $result = app(SoapCalculationService::class)->calculate(calibrationBalancedOils(), [
        'superfat' => 7, 'water_mode' => 'lye_concentration', 'water_value' => 30,
    ]);

    expect($result['properties']['qualities']['unmolding_firmness'])->toBeBetween(53.0, 63.0);
    expect($result['properties']['qualities']['dos_risk'])->toBe(18.1784);
    expect($result['lye']['naoh']['adjusted'])->toBe(139.0168);
    expect($result['lye']['water']['weight'])->toBe(324.3725);
});

it('keeps high oleic bars physically harder than their coconut blends without giving them long life', function (string $profile): void {
    $service = app(SoapCalculationService::class);
    $pure = $service->calculate([calibrationOil($profile, 1000)])['properties']['qualities'];
    $blend = $service->calculate([calibrationOil($profile, 800), calibrationOil('coconut', 200)])['properties']['qualities'];
    $balanced = $service->calculate(calibrationBalancedOils())['properties']['qualities'];

    expect($pure['cured_hardness'])->toBeGreaterThan($blend['cured_hardness'] + 10)
        ->toBeGreaterThan(65.0);
    expect($pure['longevity'])->toBeLessThan($balanced['longevity']);
})->with(['olive', 'high_oleic']);

it('increases firmness as palmitic or stearic replaces oleic without structure threshold reversals', function (string $acid): void {
    $service = app(SoapCalculationService::class);
    $previousFirmness = -1;
    $previousHardness = -1;
    foreach (range(20, 45) as $hardAcids) {
        $qualities = $service->calculate([
            ['weight' => 1000, 'koh_sap_value' => 0.2, 'fatty_acid_profile' => [
                $acid => $hardAcids, 'oleic' => 70 - $hardAcids, 'lauric' => 20, 'linoleic' => 10,
            ]],
        ])['properties']['qualities'];
        expect($qualities['unmolding_firmness'])->toBeGreaterThan($previousFirmness);
        expect($qualities['cured_hardness'])->toBeGreaterThan($previousHardness);
        $previousFirmness = $qualities['unmolding_firmness'];
        $previousHardness = $qualities['cured_hardness'];
    }
})->with(['palmitic', 'stearic']);

it('reduces physical qualities with superfat and preserves the existing coconut dos penalty', function (): void {
    $service = app(SoapCalculationService::class);
    $oils = [calibrationOil('coconut', 1000)];
    $low = $service->calculate($oils, ['superfat' => 0])['properties']['qualities'];
    $high = $service->calculate($oils, ['superfat' => 20])['properties']['qualities'];

    foreach (['unmolding_firmness', 'cured_hardness', 'longevity', 'bubble_volume', 'lather_stability'] as $metric) {
        expect($high[$metric])->toBeLessThan($low[$metric]);
    }
    expect($low['dos_risk'])->toBe(3.6);
    expect($high['dos_risk'])->toBe(19.6);
});

it('uses liquid consistently across water modes and reflects it in four week hardness and shrinkage', function (): void {
    $service = app(SoapCalculationService::class);
    $oils = calibrationBalancedOils();
    $wet = $service->calculate($oils, ['water_mode' => 'lye_concentration', 'water_value' => 25]);
    $dry = $service->calculate($oils, ['water_mode' => 'lye_concentration', 'water_value' => 33]);
    $ratio = $service->calculate($oils, ['water_mode' => 'lye_ratio', 'water_value' => 3]);
    $percent = $service->calculate($oils, ['water_mode' => 'percent_of_oils', 'water_value' => $wet['lye']['water']['weight'] / 10]);

    foreach (['unmolding_firmness', 'cured_hardness'] as $metric) {
        expect($wet['properties']['qualities'][$metric])->toBeLessThan($dry['properties']['qualities'][$metric]);
    }
    expect($wet['properties']['qualities']['shrinkage_risk'])->toBeGreaterThan(60);
    expect($dry['properties']['qualities']['shrinkage_risk'])->toBeLessThan(20);
    foreach (['unmolding_firmness', 'cured_hardness', 'shrinkage_risk'] as $metric) {
        expect(abs($wet['properties']['qualities'][$metric] - $ratio['properties']['qualities'][$metric]))->toBeLessThan(0.001);
        expect(abs($wet['properties']['qualities'][$metric] - $percent['properties']['qualities'][$metric]))->toBeLessThan(0.001);
    }
});

it('changes shrinkage and slime smoothly across their former thresholds', function (): void {
    $service = app(SoapCalculationService::class);
    $below = $service->calculate([calibrationOil('olive', 1000)], ['water_mode' => 'lye_concentration', 'water_value' => 29.99]);
    $above = $service->calculate([calibrationOil('olive', 1000)], ['water_mode' => 'lye_concentration', 'water_value' => 30.01]);
    expect(abs($below['properties']['qualities']['shrinkage_risk'] - $above['properties']['qualities']['shrinkage_risk']))->toBeLessThan(0.2);

    $scores = [];
    foreach ([64.99, 65.01] as $oleic) {
        $scores[] = $service->calculate([
            ['weight' => 1000, 'koh_sap_value' => 0.19, 'fatty_acid_profile' => ['oleic' => $oleic, 'palmitic' => 15, 'linoleic' => 85 - $oleic]],
        ])['properties']['qualities']['slime_risk'];
    }
    expect(abs($scores[0] - $scores[1]))->toBeLessThan(0.1);
});

it('responds to koh progressively and hides unsupported finished soap predictions', function (): void {
    $service = app(SoapCalculationService::class);
    $bar = $service->calculate(calibrationBalancedOils());
    $hybrid = $service->calculate(calibrationBalancedOils(), ['lye_type' => 'dual', 'dual_lye_koh_percentage' => 20]);
    $liquid = $service->calculate(calibrationBalancedOils(), ['lye_type' => 'koh', 'superfat' => -5]);

    foreach (['unmolding_firmness', 'cured_hardness', 'longevity'] as $metric) {
        expect($hybrid['properties']['qualities'][$metric])->toBeLessThan($bar['properties']['qualities'][$metric]);
        expect($hybrid['properties']['quality_applicability'][$metric]['display'])->toBe('tendency');
    }
    expect($hybrid['properties']['qualities']['bubble_volume'])->toBeGreaterThan($bar['properties']['qualities']['bubble_volume']);
    foreach (['cured_hardness', 'shrinkage_risk', 'mildness', 'cleansing_strength', 'conditioning_feel'] as $metric) {
        expect($liquid['properties']['quality_applicability'][$metric]['applies'])->toBeFalse();
    }
    expect($liquid['properties']['quality_applicability']['bubble_volume']['display'])->toBe('tendency');
});

it('uses composition independently of ingredient name and batch size and includes caproic', function (): void {
    $service = app(SoapCalculationService::class);
    $oil = calibrationOil('coconut', 1000);
    $renamed = [...$oil, 'name' => 'Different source, identical chemistry', 'weight' => 5000];
    $first = $service->calculate([$oil]);
    $second = $service->calculate([$renamed]);

    foreach ($first['properties']['qualities'] as $key => $score) {
        expect(abs($second['properties']['qualities'][$key] - $score))->toBeLessThan(0.001);
    }
    expect($first['properties']['fatty_acid_groups']['vs'])->toBe(79.2);
    expect($first['properties']['quality_model_version'])->toBe('2026-09-13');
});

/** @return array<int, array<string, mixed>> */
function calibrationBalancedOils(): array
{
    return [calibrationOil('olive', 280), calibrationOil('coconut', 250), calibrationOil('castor', 70), calibrationOil('palm', 400)];
}

/** @return array{name: string, weight: float, koh_sap_value: float, fatty_acid_profile: array<string, float|int>} */
function calibrationOil(string $key, float $weight): array
{
    $profiles = [
        'olive' => [0.19, ['palmitic' => 14, 'palmitoleic' => 0.6, 'stearic' => 3, 'oleic' => 75, 'linoleic' => 7, 'linolenic' => 0.6, 'arachidic' => 0.6, 'gondoic' => 0.4, 'behenic' => 0.2]],
        'coconut' => [0.257, ['caproic' => 0.2, 'caprylic' => 7, 'capric' => 8, 'lauric' => 48, 'myristic' => 16, 'palmitic' => 10, 'palmitoleic' => 1, 'stearic' => 2, 'oleic' => 7, 'linoleic' => 2, 'arachidic' => 0.2]],
        'castor' => [0.18, ['palmitic' => 2, 'stearic' => 1, 'ricinoleic' => 87, 'oleic' => 6, 'linoleic' => 4]],
        'palm' => [0.199, ['lauric' => 0.2, 'myristic' => 1, 'palmitic' => 42, 'palmitoleic' => 0.2, 'stearic' => 5, 'oleic' => 40, 'linoleic' => 10, 'linolenic' => 0.2, 'arachidic' => 0.6, 'gondoic' => 0.2, 'behenic' => 0.2, 'erucic' => 0.2]],
        'high_oleic' => [0.19, ['palmitic' => 5, 'stearic' => 4, 'oleic' => 82, 'linoleic' => 9]],
    ];

    return ['name' => $key, 'weight' => $weight, 'koh_sap_value' => $profiles[$key][0], 'fatty_acid_profile' => $profiles[$key][1]];
}
