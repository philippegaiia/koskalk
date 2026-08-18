<?php

use App\Services\LyeLiquidAllocationService;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the default dilution liquid entirely as water', function () {
    $allocation = app(LyeLiquidAllocationService::class)->allocateFresh(380, []);

    expect($allocation)->toMatchArray([
        'total_weight' => 380.0,
        'water_percentage' => 100.0,
        'water_weight' => 380.0,
        'substitution_percentage' => 0.0,
        'substitutions' => [],
    ]);
});

it('translates allocation validation exceptions exposed by previews', function (): void {
    Lang::addLines([
        'workbench.validation.lye_liquid_negative_cured' => 'Translated negative cured mass.',
    ], 'en');

    expect(fn () => app(LyeLiquidAllocationService::class)->allocateCuredResidual('-1', []))
        ->toThrow(InvalidArgumentException::class, 'Translated negative cured mass.');
});

it('allocates partial and multiple substitutions from the total dilution liquid', function () {
    $allocation = app(LyeLiquidAllocationService::class)->allocateFresh(380, [
        ['ingredient_id' => 10, 'percentage' => 25, 'name' => 'Rose hydrosol'],
        ['ingredient_id' => 11, 'percentage' => 15, 'name' => 'Goat milk'],
    ]);

    expect($allocation['water_percentage'])->toBe(60.0)
        ->and($allocation['water_weight'])->toBe(228.0)
        ->and($allocation['substitution_percentage'])->toBe(40.0)
        ->and($allocation['substitutions'])->toBe([
            ['ingredient_id' => 10, 'percentage' => 25.0, 'name' => 'Rose hydrosol', 'weight' => 95.0],
            ['ingredient_id' => 11, 'percentage' => 15.0, 'name' => 'Goat milk', 'weight' => 57.0],
        ]);
});

it('accepts canonical decimal strings for lye liquid mass arithmetic', function () {
    $allocation = app(LyeLiquidAllocationService::class)->allocateFresh('0.3', [
        ['ingredient_id' => 10, 'percentage' => '33.3333'],
        ['ingredient_id' => 11, 'percentage' => '33.3333'],
        ['ingredient_id' => 12, 'percentage' => '33.3334'],
    ]);

    expect($allocation['total_weight'])->toBe(0.3)
        ->and($allocation['water_weight'])->toBe(0.0)
        ->and(bcadd(
            bcadd((string) $allocation['substitutions'][0]['weight'], (string) $allocation['substitutions'][1]['weight'], 4),
            (string) $allocation['substitutions'][2]['weight'],
            4,
        ))->toBe('0.3000');
});

it('accepts finite primitive floats that stringify with exponents', function () {
    $tinyTotal = app(LyeLiquidAllocationService::class)->allocateFresh(0.00001, []);
    $tinyPercentage = app(LyeLiquidAllocationService::class)->allocateFresh(100000, [
        ['ingredient_id' => 10, 'percentage' => 0.00001],
    ]);

    expect($tinyTotal['total_weight'])->toBe(0.0)
        ->and($tinyPercentage['substitutions'][0]['weight'])->toBe(0.01);
});

it('rejects non finite primitive floats', function () {
    expect(fn () => app(LyeLiquidAllocationService::class)->allocateFresh(INF, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(LyeLiquidAllocationService::class)->allocateFresh(NAN, []))
        ->toThrow(InvalidArgumentException::class);
});

it('allows full replacement of water', function () {
    $allocation = app(LyeLiquidAllocationService::class)->allocateFresh(300, [
        ['ingredient_id' => 10, 'percentage' => 100],
    ]);

    expect($allocation['water_percentage'])->toBe(0.0)
        ->and($allocation['water_weight'])->toBe(0.0)
        ->and($allocation['substitutions'][0]['weight'])->toBe(300.0);
});

it('splits the eleven percent cured residual liquid proportionately', function () {
    $allocation = app(LyeLiquidAllocationService::class)->allocateCuredResidual(1000, [
        ['ingredient_id' => 10, 'percentage' => 50, 'name' => 'Rose hydrosol'],
    ]);

    expect($allocation['total_weight'])->toBe(110.0)
        ->and($allocation['water_weight'])->toBe(55.0)
        ->and($allocation['substitutions'][0]['weight'])->toBe(55.0);
});

it('conserves the exact cured residual pool across a non terminating three way split', function () {
    $allocation = app(LyeLiquidAllocationService::class)->allocateCuredResidual(1, [
        ['ingredient_id' => 10, 'percentage' => 100 / 3],
        ['ingredient_id' => 11, 'percentage' => 100 / 3],
        ['ingredient_id' => 12, 'percentage' => 100 / 3],
    ]);
    $allocatedWeight = array_reduce(
        $allocation['substitutions'],
        fn (string $total, array $substitution): string => bcadd($total, (string) $substitution['weight'], 4),
        number_format($allocation['water_weight'], 4, '.', ''),
    );

    expect($allocatedWeight)->toBe(number_format($allocation['total_weight'], 4, '.', ''));
});

it('rejects invalid substitution percentages', function (array $substitutions) {
    app(LyeLiquidAllocationService::class)->allocateFresh(300, $substitutions);
})->with([
    'negative percentage' => [[['ingredient_id' => 10, 'percentage' => -1]]],
    'total above one hundred' => [[
        ['ingredient_id' => 10, 'percentage' => 60],
        ['ingredient_id' => 11, 'percentage' => 41],
    ]],
])->throws(InvalidArgumentException::class);
