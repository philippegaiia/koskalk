<?php

use App\Services\LiquidSoapFormulaProcessor;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function dilutionProcess(): array
{
    return [
        'status' => 'ok',
        'stages' => [
            'first_dilution' => ['add_weight' => '1679.3270'],
            'final_dilution' => ['add_weight' => '1327.4700'],
        ],
        'neutralizer' => ['weight' => '6.5062', 'solution_weight' => '32.5310'],
    ];
}

it('splits each dilution into its liquids and plain water', function () {
    $split = app(LiquidSoapFormulaProcessor::class)->dilutionLiquids(dilutionProcess(), [
        'final_dilution' => [['id' => 'h1', 'ingredient_id' => 7, 'percentage' => 30]],
    ]);

    expect($split['first_dilution'])->toBe(['water_weight' => 1679.327, 'liquids' => []])
        ->and($split['final_dilution']['water_weight'])->toBe(929.229)
        ->and($split['final_dilution']['liquids'])->toBe([['id' => 'h1', 'ingredient_id' => 7, 'percentage' => 30.0, 'weight' => 398.241]]);
});

it('counts only the plain water as added dilution water', function () {
    $processor = app(LiquidSoapFormulaProcessor::class);
    $process = [...dilutionProcess(), 'dilution_liquids' => $processor->dilutionLiquids(dilutionProcess(), [
        'final_dilution' => [['ingredient_id' => 7, 'percentage' => 30]],
    ])];

    // 1679.327 + 929.229 + (32.531 − 6.5062)
    expect((float) $processor->addedDilutionWaterWeight($process))->toEqualWithDelta(2634.5808, 0.0001);
});

it('names the step when its liquids exceed 100 percent', function () {
    app(LiquidSoapFormulaProcessor::class)->dilutionLiquids(dilutionProcess(), [
        'first_dilution' => [['ingredient_id' => 7, 'percentage' => 60], ['ingredient_id' => 8, 'percentage' => 50]],
    ]);
})->throws(ValidationException::class);
