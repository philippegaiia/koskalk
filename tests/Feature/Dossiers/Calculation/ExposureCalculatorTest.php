<?php

use App\Enums\NoaelRoute;
use App\Services\Dossiers\Calculation\ExposureCalculator;
use App\Support\DecimalMath;
use Tests\Support\DossierCalculationFixtures;

it('computes the olive oil exposure dose of the pilot reference', function (): void {
    $sed = app(ExposureCalculator::class)->systemicExposureDose('22.694', '100', DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::round($sed, 6))->toBe('0.378233');
});

it('computes margins of safety at full precision', function (string $concentration, string $dap, string $systemicNoael, string $sed, string $mos): void {
    $calculator = app(ExposureCalculator::class);
    $dose = $calculator->systemicExposureDose($concentration, $dap, DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::round($dose, 9))->toBe($sed)
        ->and(DecimalMath::round($calculator->marginOfSafety($systemicNoael, $dose), 2))->toBe($mos);
})->with([
    'limonene' => ['0.09660', '100', '75', '0.001610000', '46583.85'],
    'linalool, not the early-rounded 731,300' => ['0.03262', '14.4', '58.5', '0.000078288', '747240.96'],
    'glycerin' => ['7.944', '7.34', '590', '0.009718160', '60711.08'],
]);

it('converts only an oral NOAEL automatically', function (): void {
    $calculator = app(ExposureCalculator::class);

    expect(DecimalMath::round($calculator->systemicNoael('150', NoaelRoute::Oral, '50'), 6))->toBe('75.000000')
        ->and($calculator->systemicNoael('100', NoaelRoute::Dermal, '50'))->toBeNull()
        ->and($calculator->systemicNoael('100', NoaelRoute::Inhalation, '50'))->toBeNull();
});

it('gives no margin of safety without exposure', function (): void {
    $calculator = app(ExposureCalculator::class);
    $dose = $calculator->systemicExposureDose('1', '0', DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::isZero($dose))->toBeTrue()
        ->and($calculator->marginOfSafety('75', $dose))->toBeNull();
});

it('reports an impurity dose in micrograms', function (): void {
    $dose = app(ExposureCalculator::class)->impurityExposureDoseMicrograms('2', '100', DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::round($dose, 6))->toBe('0.003333');
});
