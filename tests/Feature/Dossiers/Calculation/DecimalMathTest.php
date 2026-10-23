<?php

use App\Support\DecimalMath;

it('reads database decimals, integers and calculator floats as canonical decimal strings', function (): void {
    expect(DecimalMath::of('22.694000'))->toBe('22.694000000000000000')
        ->and(DecimalMath::of(10))->toBe('10.000000000000000000')
        ->and(DecimalMath::of(0.000078288))->toBe('0.000078288000000000')
        ->and(DecimalMath::of(-0.0))->toBe('0.000000000000000000');
});

it('refuses missing, non-numeric and non-finite values', function (mixed $value): void {
    DecimalMath::of($value);
})->with([
    'null' => [null],
    'text' => ['abc'],
    'localised' => ['1,5'],
    'infinite' => [INF],
])->throws(InvalidArgumentException::class);

it('computes at full precision and rounds half away from zero only on request', function (): void {
    $third = DecimalMath::div('1', '3');

    expect(DecimalMath::round($third, 6))->toBe('0.333333')
        ->and(DecimalMath::round('2.0000005', 6))->toBe('2.000001')
        ->and(DecimalMath::round('-2.0000005', 6))->toBe('-2.000001')
        ->and(DecimalMath::round('0.0000004', 6))->toBe('0.000000')
        ->and(DecimalMath::round(DecimalMath::mul('3', $third), 9))->toBe('1.000000000')
        ->and(DecimalMath::round(DecimalMath::percentOf('12', '1300.384044943820224719'), 6))->toBe('0.922804');
});

it('compares within an explicit tolerance and refuses division by zero', function (): void {
    expect(DecimalMath::withinTolerance('100.0005', '100', '0.001'))->toBeTrue()
        ->and(DecimalMath::withinTolerance('100.0011', '100', '0.001'))->toBeFalse()
        ->and(DecimalMath::abs('-3.5'))->toBe('3.5')
        ->and(fn () => DecimalMath::div('1', '0'))->toThrow(InvalidArgumentException::class);
});
