<?php

use App\Data\DossierAssessmentOptions;
use App\Exceptions\DossierCalculationException;
use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\Qra2Parameter;
use App\Services\Dossiers\Calculation\AssessmentParameterResolver;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the exposure preset, the policy and the QRA2 preset with their sources', function (): void {
    $policy = AssessmentPolicy::factory()->create();
    $preset = ExposurePreset::factory()->create();
    $qra2 = Qra2Parameter::factory()->create();
    ExposurePreset::factory()->create(['product_type_id' => $preset->product_type_id, 'version' => 2, 'is_current' => false, 'amount_per_use_g' => '5']);

    $parameters = app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, $qra2->ifra_product_category_id, $qra2->ifra_amendment_id, new DossierAssessmentOptions);
    $used = collect($parameters['used'])->keyBy('key');

    expect(DecimalMath::round($parameters['exposure']->dailyAmountGrams(), 2))->toBe('10.00')
        ->and(DecimalMath::round($parameters['policy_dap'], 2))->toBe('100.00')
        ->and(DecimalMath::round($parameters['mos_threshold'], 2))->toBe('100.00')
        ->and($parameters['is_rinse_off'])->toBeTrue()
        ->and($parameters['qra2']->is($qra2))->toBeTrue()
        ->and($used['amount_per_use_g']['source'])->toBe("exposure_preset:{$preset->id}:v1")
        ->and($used['body_weight_kg']['source'])->toBe("assessment_policy:{$policy->id}:v1");
});

it('applies a justified override and records the default it replaced', function (): void {
    AssessmentPolicy::factory()->create();
    $preset = ExposurePreset::factory()->create();

    $parameters = app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, null, null, new DossierAssessmentOptions(
        overrides: ['amount_per_use_g' => ['value' => '2', 'justification' => 'Large bar: weighed use of 2 g.']],
    ));
    $override = collect($parameters['used'])->firstWhere('key', 'amount_per_use_g');

    expect(DecimalMath::round($parameters['exposure']->dailyAmountGrams(), 2))->toBe('20.00')
        ->and($override['source'])->toBe('override')
        ->and(DecimalMath::round($override['default'], 2))->toBe('1.00')
        ->and($override['justification'])->toBe('Large bar: weighed use of 2 g.')
        ->and($parameters['qra2'])->toBeNull();
});

it('cannot run without a policy or an exposure preset', function (bool $withPolicy, string $reason): void {
    if ($withPolicy) {
        AssessmentPolicy::factory()->create();
    }

    $preset = ExposurePreset::factory()->create(['is_current' => false]);

    expect(fn () => app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, null, null, new DossierAssessmentOptions))
        ->toThrow(function (DossierCalculationException $exception) use ($reason): void {
            expect($exception->reason)->toBe($reason);
        });
})->with([
    'no policy' => [false, 'no_assessment_policy'],
    'no current preset' => [true, 'no_exposure_preset'],
]);

it('refuses unknown, unjustified or physically impossible inputs', function (array $arguments): void {
    new DossierAssessmentOptions(...$arguments);
})->with([
    'unknown key' => [['overrides' => ['mos_threshold' => ['value' => '50', 'justification' => 'x']]]],
    'no justification' => [['overrides' => ['retention_factor' => ['value' => '0.1', 'justification' => ' ']]]],
    'not a number' => [['overrides' => ['retention_factor' => ['value' => 'ten', 'justification' => 'x']]]],
    'negative retention' => [['overrides' => ['retention_factor' => ['value' => '-0.01', 'justification' => 'x']]]],
    'zero retention' => [['overrides' => ['retention_factor' => ['value' => '0', 'justification' => 'x']]]],
    'retention above 1' => [['overrides' => ['retention_factor' => ['value' => '1.5', 'justification' => 'x']]]],
    'zero body weight' => [['overrides' => ['body_weight_kg' => ['value' => '0', 'justification' => 'x']]]],
    'zero amount per use' => [['overrides' => ['amount_per_use_g' => ['value' => '0', 'justification' => 'x']]]],
    'zero uses per day' => [['overrides' => ['uses_per_day' => ['value' => '0', 'justification' => 'x']]]],
    'absorption above 100 %' => [['overrides' => ['default_dermal_absorption_percent' => ['value' => '100.1', 'justification' => 'x']]]],
    'negative absorption' => [['overrides' => ['default_dermal_absorption_percent' => ['value' => '-1', 'justification' => 'x']]]],
    'bioavailability above 100 %' => [['overrides' => ['default_oral_bioavailability_percent' => ['value' => '101', 'justification' => 'x']]]],
    'residual water above 30 %' => [['overrides' => ['residual_water_percent' => ['value' => '31', 'justification' => 'x']]]],
    'measured loss of 100 %' => [['measuredCureLossPercent' => '100']],
    'unknown route' => [['confirmations' => ['glycerin' => ['route' => 'guess', 'justification' => 'x']]]],
    'unknown annex answer' => [['confirmations' => ['glycerin' => ['route' => 'mos', 'justification' => 'x', 'annex_conditions' => 'maybe']]]],
])->throws(InvalidArgumentException::class);

it('accepts the limits themselves, zero absorption included', function (array $arguments): void {
    expect(new DossierAssessmentOptions(...$arguments))->toBeInstanceOf(DossierAssessmentOptions::class);
})->with([
    'zero absorption' => [['overrides' => ['default_dermal_absorption_percent' => ['value' => '0', 'justification' => 'Measured: not absorbed.']]]],
    'retention of 1' => [['overrides' => ['retention_factor' => ['value' => '1', 'justification' => 'Leave-on worst case.']]]],
    'residual water of 30 %' => [['overrides' => ['residual_water_percent' => ['value' => '30', 'justification' => 'Freshly cut bars.']]]],
    'no measured loss' => [['measuredCureLossPercent' => '0']],
]);

it('does not run with a stored preset value outside its physical bounds', function (): void {
    AssessmentPolicy::factory()->create();
    $preset = ExposurePreset::factory()->create(['retention_factor' => '0']);

    expect(fn () => app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, null, null, new DossierAssessmentOptions))
        ->toThrow(function (DossierCalculationException $exception): void {
            expect($exception->reason)->toBe('invalid_parameter')
                ->and($exception->context)->toBe(['key' => 'retention_factor']);
        });
});
