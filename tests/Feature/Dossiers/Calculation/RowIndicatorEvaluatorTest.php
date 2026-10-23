<?php

use App\Models\Qra2Parameter;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\Calculation\LegalEntryChecker;
use App\Services\Dossiers\Calculation\RowIndicatorEvaluator;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $profile
 * @return array<string, mixed>
 */
function indicatorsFor(string $category, string $concentration, array $profile, ?Qra2Parameter $qra2 = null): array
{
    return app(RowIndicatorEvaluator::class)->evaluate(
        $category,
        $concentration,
        ToxicologyProfile::factory()->create($profile),
        Fixtures::pilotParameters($qra2),
        LegalEntryChecker::none(),
    );
}

it('passes every indicator exactly at its limit and fails just above', function (string $concentration, bool $passes): void {
    // C 0.6 % with DAp 100 % gives SED exactly 0.01; NOAEL 2 (oral) × 50 % gives NOAELsys 1, so MoS is exactly 100.
    $indicators = indicatorsFor('material', $concentration, [
        'noael_mg_kg_bw_day' => '2',
        'noael_route' => 'oral',
        'cmax_percent' => '0.6',
        'adi_mg_kg_bw_day' => '0.01',
        'ttc_mg_kg_bw_day' => '0.01',
    ]);

    expect($indicators['mos']['passes'])->toBe($passes)
        ->and($indicators['cmax']['passes'])->toBe($passes)
        ->and($indicators['adi']['passes'])->toBe($passes)
        ->and($indicators['ttc']['passes'])->toBe($passes);
})->with([
    'at the limits' => ['0.6', true],
    'just above' => ['0.6000001', false],
]);

it('uses policy defaults when the profile has no absorption data', function (): void {
    $indicators = indicatorsFor('material', '7.515827845474833949', [
        'noael_mg_kg_bw_day' => '1180',
        'noael_route' => 'oral',
        'oral_bioavailability_percent' => null,
        'dermal_absorption_percent' => '7.34',
    ]);

    expect($indicators['dermal_absorption_source'])->toBe('profile')
        ->and(DecimalMath::round($indicators['mos']['oral_bioavailability_percent'], 2))->toBe('50.00')
        ->and(DecimalMath::round($indicators['mos']['noael_systemic'], 2))->toBe('590.00')
        ->and(DecimalMath::round($indicators['mos']['value'], 2))->toBe('64169.75');
});

it('explains why a margin of safety is unavailable', function (array $profile, string $reason): void {
    expect(indicatorsFor('material', '1', $profile)['mos'])->toBe(['available' => false, 'reason' => $reason]);
})->with([
    'no NOAEL' => [['noael_mg_kg_bw_day' => null, 'noael_route' => null], 'no_noael'],
    'inhalation study' => [['noael_mg_kg_bw_day' => '10', 'noael_route' => 'inhalation'], 'route_not_convertible'],
    'dermal study' => [['noael_mg_kg_bw_day' => '10', 'noael_route' => 'dermal'], 'dermal_study_absorption_unknown'],
    'zero absorption' => [['noael_mg_kg_bw_day' => '10', 'noael_route' => 'oral', 'dermal_absorption_percent' => '0'], 'no_exposure'],
]);

it('evaluates QRA2 for allergens only, with the preset factor', function (): void {
    $preset = Qra2Parameter::factory()->create();
    $profile = ['noael_mg_kg_bw_day' => '150', 'noael_route' => 'oral', 'nesil_ug_cm2' => '10000', 'sensitisation_note' => 'Skin sensitiser.', 'cmr_note' => 'Not CMR.'];

    $allergen = indicatorsFor('allergen', '0.922804308977693538', $profile, $preset);
    $withoutPreset = indicatorsFor('allergen', '0.922804308977693538', $profile);

    expect(DecimalMath::round($allergen['qra2']['cmax_percent'], 6))->toBe('8.333333')
        ->and($allergen['qra2']['passes'])->toBeTrue()
        ->and(DecimalMath::round($allergen['sed_mg_kg_bw_day'], 9))->toBe('0.015380072')
        ->and(DecimalMath::round($allergen['mos']['value'], 2))->toBe('4876.44')
        ->and($allergen['hazard_endpoints'])->toBe(['sensitisation', 'cmr'])
        ->and($withoutPreset['qra2'])->toBe(['available' => false, 'reason' => 'no_qra2_preset'])
        ->and(indicatorsFor('material', '1', $profile, $preset)['qra2'])->toBeNull();
});

it('passes QRA2 exactly at its maximum and fails just above', function (string $concentration, bool $passes): void {
    // NESIL 3000 with SAF 300, CEL 0.2 and factor 0.5 gives a QRA2 maximum of exactly 2.5 %.
    $indicators = indicatorsFor('allergen', $concentration, ['nesil_ug_cm2' => '3000'], Qra2Parameter::factory()->create());

    expect(DecimalMath::round($indicators['qra2']['cmax_percent'], 6))->toBe('2.500000')
        ->and($indicators['qra2']['passes'])->toBe($passes);
})->with([
    'at the maximum' => ['2.5', true],
    'just above' => ['2.5000001', false],
]);
