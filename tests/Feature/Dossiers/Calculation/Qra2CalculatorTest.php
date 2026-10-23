<?php

use App\Models\Qra2Parameter;
use App\Services\Dossiers\Calculation\Qra2Calculator;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the aggregate exposure adjustment factor', function (string $nesil, ?string $factor, string $withoutFactor, string $cmax): void {
    $result = app(Qra2Calculator::class)->maximumConcentration($nesil, '300', '0.2', $factor);

    expect(DecimalMath::round($result['cmax_without_factor_percent'], 6))->toBe($withoutFactor)
        ->and(DecimalMath::round($result['cmax_percent'], 6))->toBe($cmax)
        ->and($result['factor'])->toBe($factor);
})->with([
    'limonene, bar soap' => ['10000', '0.5', '16.666667', '8.333333'],
    'IFRA guidance Table 7 worked example' => ['3500', '0.5', '5.833333', '2.916667'],
    'recorded deviation: no factor' => ['10000', null, '16.666667', '16.666667'],
]);

it('reads the factor from the preset only when the preset applies it', function (): void {
    $applied = Qra2Parameter::factory()->create();
    $deviation = Qra2Parameter::factory()->create([
        'applies_qra2_factor' => false,
        'factor_deviation_reason' => 'Expert deviation recorded by the assessor.',
    ]);
    $calculator = app(Qra2Calculator::class);

    expect(DecimalMath::round($calculator->forPreset('10000', $applied)['cmax_percent'], 6))->toBe('8.333333')
        ->and(DecimalMath::round($calculator->forPreset('10000', $deviation)['cmax_percent'], 6))->toBe('16.666667');
});
