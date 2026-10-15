<?php

use App\Enums\LiquidSoapNeutralizer;
use App\Services\LiquidSoapProcessCalculator;
use App\Services\SoapCalculationService;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(FattyAcidSeeder::class);
});

/**
 * 1000 g of lipids needing 190 g of pure KOH at 0% superfat, KOH 90%,
 * alkali solution at 28% unless another water setting is given.
 *
 * @param  array<string, mixed>  $settings
 * @return array<string, string>
 */
function liquidSoapPaste(float $superfat, array $settings = []): array
{
    $soapCalculation = app(SoapCalculationService::class)->calculate(
        [['name' => 'Reference oil', 'weight' => 1000, 'koh_sap_value' => 0.19]],
        [
            'superfat' => $superfat,
            'lye_type' => 'koh',
            'koh_purity_percentage' => 90,
            'water_mode' => 'lye_concentration',
            'water_value' => 28,
            ...$settings,
        ],
    );

    return app(LiquidSoapProcessCalculator::class)->pasteComposition($soapCalculation);
}

/**
 * @param  array<string, string>  $values
 */
function liquidSoapSum(array $values): string
{
    return array_reduce($values, fn (string $total, ?string $value): string => bcadd($total, $value ?? '0', 4), '0');
}

it('reads the anhydrous soap basis and its parts from a KOH paste', function () {
    $paste = liquidSoapPaste(0);

    expect($paste['anhydrous_soap_weight'])->toBe('1190.0000')
        ->and($paste['koh_material_weight'])->toBe('211.1111')
        ->and($paste['unreacted_lipids_weight'])->toBe('0.0000')
        ->and($paste['koh_impurity_weight'])->toBe('21.1111')
        ->and($paste['alkali_liquid_weight'])->toBe('488.5714')
        ->and($paste['theoretical_paste_weight'])->toBe('1699.6825')
        ->and((float) $paste['glycerine_weight'])->toEqualWithDelta(103.9577, 0.0002)
        ->and((float) $paste['soap_salt_weight'])->toEqualWithDelta(1086.0423, 0.0002);
});

it('keeps unsaponified lipids inside the basis at positive superfat', function () {
    $paste = liquidSoapPaste(3);

    expect($paste['anhydrous_soap_weight'])->toBe('1184.3000')
        ->and($paste['unreacted_lipids_weight'])->toBe('30.0000')
        ->and($paste['free_koh_weight'])->toBe('0.0000');
});

it('rejects a paste made with an alkali other than KOH', function (string $lyeType) {
    $soapCalculation = app(SoapCalculationService::class)->calculate(
        [['name' => 'Reference oil', 'weight' => 1000, 'koh_sap_value' => 0.19]],
        ['superfat' => 0, 'lye_type' => $lyeType, 'water_mode' => 'lye_concentration', 'water_value' => 28],
    );

    app(LiquidSoapProcessCalculator::class)->pasteComposition($soapCalculation);
})->with(['naoh' => 'naoh', 'dual' => 'dual'])->throws(InvalidArgumentException::class, 'Liquid soap paste requires KOH');

it('dilutes a neutral paste to the first and final concentrations', function () {
    $paste = liquidSoapPaste(0);

    $result = app(LiquidSoapProcessCalculator::class)->calculate($paste, [
        'first_dilution_percentage' => 35,
        'final_dilution_percentage' => 25,
    ]);

    expect($result['status'])->toBe('ok')
        ->and($result['paste']['anhydrous_percentage'])->toBe('70.0131')
        ->and($result['stages']['first_dilution']['add_weight'])->toBe('1700.3175')
        ->and($result['stages']['first_dilution']['mass'])->toBe('3400.0000')
        ->and($result['stages']['first_dilution']['status'])->toBe('ok')
        ->and($result['stages']['neutralization']['add_weight'])->toBe('0.0000')
        ->and($result['stages']['final_dilution']['add_weight'])->toBe('1360.0000')
        ->and($result['final_weight'])->toBe('4760.0000')
        ->and($result['final_anhydrous_percentage'])->toBe('25.0000')
        ->and($result['neutralizer'])->toBeNull()
        ->and($result['recommended_neutralizer_weights'])->toBe([
            'citric_acid_anhydrous' => '0.0000',
            'citric_acid_monohydrate' => '0.0000',
            'lactic_acid' => '0.0000',
        ])
        ->and($result['warnings'])->toBe([]);
});

it('expresses each component as a share of the final batch', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(0), [
        'final_dilution_percentage' => 25,
    ]);

    expect($result['composition_percentages']['koh_impurity_weight'])->toBe('0.4435')
        ->and((float) $result['composition_percentages']['glycerine_weight'])->toEqualWithDelta(2.1840, 0.0002)
        ->and((float) liquidSoapSum($result['composition_percentages']))->toEqualWithDelta(100.0, 0.0005);
});

it('defaults the first dilution to 35 percent', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(0), [
        'final_dilution_percentage' => 25,
    ]);

    expect($result['stages']['first_dilution']['target_percentage'])->toBe('35.0000')
        ->and($result['stages']['first_dilution']['mass'])->toBe('3400.0000');
});

it('recommends the theoretical acid for the free KOH of a lye-heavy paste', function (LiquidSoapNeutralizer $neutralizer, float $gramsPerGramKoh) {
    $paste = liquidSoapPaste(-3);

    $recommended = app(LiquidSoapProcessCalculator::class)->recommendedNeutralizerWeight($paste['free_koh_weight'], $neutralizer);

    expect($paste['free_koh_weight'])->toBe('5.7000')
        ->and((float) $recommended)->toEqualWithDelta(5.7 * $gramsPerGramKoh, 0.0002);
})->with([
    'citric acid anhydrous' => [LiquidSoapNeutralizer::CitricAcidAnhydrous, 1.141440],
    'citric acid monohydrate' => [LiquidSoapNeutralizer::CitricAcidMonohydrate, 1.248470],
    'lactic acid' => [LiquidSoapNeutralizer::LacticAcid, 1.605498],
]);

it('neutralizes free KOH with citric acid into potassium citrate without changing the soap basis', function () {
    $paste = liquidSoapPaste(-3);

    $result = app(LiquidSoapProcessCalculator::class)->calculate($paste, [
        'final_dilution_percentage' => 25,
        'neutralizer' => ['type' => 'citric_acid_anhydrous', 'weight' => '6.5062', 'solution_percentage' => 20],
    ]);

    expect($result['stages']['first_dilution']['add_weight'])->toBe('1679.3270')
        ->and($result['neutralizer']['recommended_weight'])->toBe('6.5062')
        ->and($result['stages']['neutralization']['add_weight'])->toBe('32.5310')
        ->and($result['stages']['neutralization']['anhydrous_percentage'])->toBe('34.6683')
        ->and($result['stages']['final_dilution']['add_weight'])->toBe('1327.4690')
        ->and($result['final_anhydrous_soap_weight'])->toBe('1190.0000')
        ->and($result['final_weight'])->toBe('4760.0000')
        ->and((float) $result['composition']['neutralization_salt_weight'])->toEqualWithDelta(5.7 * 1.820349, 0.0002)
        ->and($result['composition']['free_koh_weight'])->toBe('0.0000')
        ->and($result['warnings'])->toBe(['neutralizer_amount_is_theoretical']);
});

it('forms the same citrate from citric acid monohydrate and counts its crystal water', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(-3), [
        'final_dilution_percentage' => 25,
        'neutralizer' => ['type' => LiquidSoapNeutralizer::CitricAcidMonohydrate, 'weight' => '7.1163'],
    ]);

    expect((float) $result['composition']['neutralization_salt_weight'])->toEqualWithDelta(5.7 * 1.820349, 0.0002)
        ->and($result['composition']['free_koh_weight'])->toBe('0.0000');
});

it('turns free KOH and stearic acid into extra soap that raises the final batch', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(-3), [
        'final_dilution_percentage' => 25,
        'neutralizer' => ['type' => 'fatty_acid', 'weight' => '28.9047', 'koh_sap_value' => 197.2],
    ]);

    expect($result['neutralizer']['recommended_weight'])->toBe('28.9047')
        ->and((float) $result['final_anhydrous_soap_weight'])->toEqualWithDelta(1222.7745, 0.0002)
        ->and((float) $result['final_weight'])->toEqualWithDelta(4891.098, 0.001)
        ->and((float) $result['composition']['soap_salt_weight'])->toEqualWithDelta(1086.0423 + 32.7745, 0.0003)
        ->and($result['composition']['neutralization_salt_weight'])->toBe('0.0000')
        ->and($result['composition']['free_koh_weight'])->toBe('0.0000');
});

it('reports free KOH left when less acid than recommended is added', function (?array $neutralizer, float $freeKoh) {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(-3), [
        'final_dilution_percentage' => 25,
        'neutralizer' => $neutralizer,
    ]);

    expect((float) $result['composition']['free_koh_weight'])->toEqualWithDelta($freeKoh, 0.0002)
        ->and($result['status'])->toBe('ok')
        ->and($result['warnings'])->toBe(['neutralizer_amount_is_theoretical', 'free_koh_remains']);
})->with([
    'no neutralizer' => [null, 5.7],
    'zero citric acid' => [['type' => 'citric_acid_anhydrous', 'weight' => 0], 5.7],
    'part of the citric acid' => [['type' => 'citric_acid_anhydrous', 'weight' => 3], 3.0717],
]);

it('withholds the composition and final dilution when acid exceeds the free KOH', function (float $superfat, string $acidWeight, string $excessAcid) {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste($superfat), [
        'final_dilution_percentage' => 25,
        'neutralizer' => ['type' => 'citric_acid_anhydrous', 'weight' => $acidWeight],
    ]);

    expect($result['status'])->toBe('acid_exceeds_free_koh')
        ->and((float) $result['neutralizer']['excess_acid_weight'])->toEqualWithDelta((float) $excessAcid, 0.0002)
        ->and($result['stages']['final_dilution']['status'])->toBe('unsupported')
        ->and($result['stages']['final_dilution']['add_weight'])->toBeNull()
        ->and($result['stages']['neutralization']['anhydrous_percentage'])->toBeNull()
        ->and($result['final_weight'])->toBeNull()
        ->and($result['composition'])->toBeNull()
        ->and($result['composition_percentages'])->toBeNull()
        ->and($result['warnings'])->toContain('excess_acid_splits_soap');
})->with([
    'more citric acid than the free KOH needs' => [-3, '10', '3.4938'],
    'citric acid without free KOH' => [3, '2', '2'],
]);

it('keeps extra stearic acid as unsaponified lipid inside the soap basis', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(-3), [
        'final_dilution_percentage' => 25,
        'neutralizer' => ['type' => 'fatty_acid', 'weight' => 40, 'koh_sap_value' => '0.1972'],
    ]);

    expect($result['status'])->toBe('ok')
        ->and((float) $result['composition']['unreacted_lipids_weight'])->toEqualWithDelta(11.0953, 0.0002)
        ->and((float) $result['final_anhydrous_soap_weight'])->toEqualWithDelta(1233.8698, 0.0002)
        ->and($result['warnings'])->toBe(['neutralizer_amount_is_theoretical', 'excess_fatty_acid_raises_superfat']);
});

it('adds the evaporated water back when the cooked paste was weighed', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(0), [
        'final_dilution_percentage' => 25,
        'measured_paste_weight' => '1650',
    ]);

    expect($result['paste']['evaporated_weight'])->toBe('49.6825')
        ->and($result['stages']['first_dilution']['add_weight'])->toBe('1750.0000')
        ->and($result['final_weight'])->toBe('4760.0000');
});

it('adds no first dilution water when the paste is already below its target', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(
        liquidSoapPaste(0, ['water_mode' => 'percent_of_oils', 'water_value' => 300]),
        ['final_dilution_percentage' => 25],
    );

    expect($result['stages']['first_dilution']['status'])->toBe('already_below_target')
        ->and($result['stages']['first_dilution']['add_weight'])->toBe('0.0000')
        ->and($result['stages']['first_dilution']['anhydrous_percentage'])->toBe('28.2586')
        ->and($result['final_weight'])->toBe('4760.0000');
});

it('reports the reached concentration when additions already exceed the final target', function () {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(-3), [
        'final_dilution_percentage' => 25,
        'additions_weight' => 1400,
        'neutralizer' => ['type' => 'citric_acid_anhydrous', 'weight' => '6.5062', 'solution_percentage' => 20],
    ]);

    expect($result['stages']['final_dilution']['status'])->toBe('target_exceeded')
        ->and($result['stages']['final_dilution']['add_weight'])->toBe('0.0000')
        ->and($result['final_weight'])->toBe('4832.5310')
        ->and($result['final_anhydrous_percentage'])->toBe('24.6248');
});

it('accounts for every weighed gram in the final composition', function (float $superfat, array $process) {
    $paste = liquidSoapPaste($superfat);

    $result = app(LiquidSoapProcessCalculator::class)->calculate($paste, $process);

    $weighedIn = liquidSoapSum([
        $paste['lipids_weight'],
        $paste['koh_material_weight'],
        $paste['alkali_liquid_weight'],
        bcsub('0', $result['paste']['evaporated_weight'], 4),
        $result['stages']['first_dilution']['add_weight'],
        $result['stages']['neutralization']['add_weight'],
        $result['stages']['final_dilution']['additions_weight'],
        $result['stages']['final_dilution']['add_weight'],
    ]);

    // Nine components rounded to four decimals can drift by at most 0.00045 g.
    expect((float) liquidSoapSum($result['composition']))->toEqualWithDelta((float) $result['final_weight'], 0.00045)
        ->and((float) $weighedIn)->toEqualWithDelta((float) $result['final_weight'], 0.0004);
})->with([
    'neutral paste' => [0, ['final_dilution_percentage' => 25]],
    'positive superfat with additions' => [3, ['final_dilution_percentage' => 20, 'additions_weight' => 120]],
    'citric acid solution' => [-3, ['final_dilution_percentage' => 25, 'neutralizer' => ['type' => 'citric_acid_anhydrous', 'weight' => '6.5', 'solution_percentage' => 20]]],
    'citric acid monohydrate' => [-3, ['final_dilution_percentage' => 25, 'neutralizer' => ['type' => 'citric_acid_monohydrate', 'weight' => '7']]],
    'stearic acid in excess' => [-3, ['final_dilution_percentage' => 25, 'neutralizer' => ['type' => 'fatty_acid', 'weight' => 40, 'koh_sap_value' => '0.1972']]],
    'measured paste' => [-3, ['final_dilution_percentage' => 25, 'measured_paste_weight' => '1680']],
    'target exceeded' => [-3, ['final_dilution_percentage' => 25, 'additions_weight' => 1400]],
]);

it('rejects process settings that cannot be made', function (array $process, string $message) {
    $paste = liquidSoapPaste(-3);

    expect(fn () => app(LiquidSoapProcessCalculator::class)->calculate($paste, $process))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'final more concentrated than first' => [['first_dilution_percentage' => 30, 'final_dilution_percentage' => 35], 'must not be more concentrated'],
    'missing final percentage' => [[], 'Final dilution percentage'],
    'final at zero' => [['final_dilution_percentage' => 0], 'Final dilution percentage'],
    'first at 100' => [['first_dilution_percentage' => 100, 'final_dilution_percentage' => 25], 'First dilution percentage'],
    'paste lighter than its oils and KOH' => [['final_dilution_percentage' => 25, 'measured_paste_weight' => '1217'], 'cannot be below the oils and KOH weighed in (1217.4444 g)'],
    'negative additions' => [['final_dilution_percentage' => 25, 'additions_weight' => -1], 'Additions weight must be zero or more'],
    'unknown neutralizer' => [['final_dilution_percentage' => 25, 'neutralizer' => ['type' => 'borax', 'weight' => 5]], 'Unsupported neutralizer'],
    'fatty acid without SAP' => [['final_dilution_percentage' => 25, 'neutralizer' => ['type' => 'fatty_acid', 'weight' => 5]], 'KOH SAP value'],
    'solution at zero' => [['final_dilution_percentage' => 25, 'neutralizer' => ['type' => 'lactic_acid', 'weight' => 5, 'solution_percentage' => 0]], 'Neutralizer solution percentage'],
]);

it('rejects numbers that are not finite decimals', function (mixed $value) {
    $paste = liquidSoapPaste(0);

    expect(fn () => app(LiquidSoapProcessCalculator::class)->calculate($paste, [
        'final_dilution_percentage' => 25,
        'additions_weight' => $value,
    ]))->toThrow(InvalidArgumentException::class, 'Additions weight must be a finite number');
})->with([
    'scientific notation overflow' => '1e309',
    'infinity' => INF,
    'not a number' => NAN,
    'text' => 'lots',
]);

it('accepts equal first and final targets given as different numeric types', function (mixed $first, mixed $final) {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(0), [
        'first_dilution_percentage' => $first,
        'final_dilution_percentage' => $final,
    ]);

    expect($result['stages']['final_dilution']['target_percentage'])->toBe('35.1000')
        ->and($result['stages']['final_dilution']['add_weight'])->toBe('0.0000');
})->with([
    'string first, float final' => ['35.1', 35.1],
    'float first, string final' => [35.1, '35.1'],
    'float first, float final' => [35.1, 35.1],
]);

it('reads floats in scientific notation at their exact decimal value', function (float $additions, string $expected) {
    $result = app(LiquidSoapProcessCalculator::class)->calculate(liquidSoapPaste(0), [
        'final_dilution_percentage' => 20,
        'additions_weight' => $additions,
    ]);

    expect($result['composition']['additions_weight'])->toBe($expected);
})->with([
    'tiny value' => [0.00005, '0.0001'],
    'large value' => [1.5e3, '1500.0000'],
]);
