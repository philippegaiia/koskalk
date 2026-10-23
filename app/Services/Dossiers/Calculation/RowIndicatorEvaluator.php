<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\NoaelRoute;
use App\Models\Qra2Parameter;
use App\Models\ToxicologyProfile;
use App\Support\DecimalMath;

/**
 * Every available indicator for one row (spec §4.2): MoS against the threshold, C against Cmax, SED against ADI and
 * TTC, QRA2 for fragrance allergens, and the annex results. Indicators never conclude a row on their own.
 */
class RowIndicatorEvaluator
{
    /**
     * Profile hazard notes that are endpoints the assessor must address (spec §4.2).
     */
    private const HAZARD_NOTES = [
        'skin_irritation_note' => 'skin_irritation',
        'eye_irritation_note' => 'eye_irritation',
        'sensitisation_note' => 'sensitisation',
        'phototoxicity_note' => 'phototoxicity',
        'cmr_note' => 'cmr',
    ];

    public function __construct(
        private readonly ExposureCalculator $exposure,
        private readonly Qra2Calculator $qra2,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  as returned by AssessmentParameterResolver
     * @param  array<string, mixed>  $legal  as returned by LegalEntryChecker::check()
     * @return array<string, mixed>
     */
    public function evaluate(string $category, string $concentrationPercent, ?ToxicologyProfile $profile, array $parameters, array $legal): array
    {
        $profileDap = $profile?->dermal_absorption_percent;
        $dap = $profileDap === null ? $parameters['policy_dap'] : DecimalMath::of($profileDap);
        $sed = $this->exposure->systemicExposureDose($concentrationPercent, $dap, $parameters['exposure']);

        return [
            'concentration_percent' => $concentrationPercent,
            'concentration_ppm' => DecimalMath::mul($concentrationPercent, '10000'),
            'dermal_absorption_percent' => $dap,
            'dermal_absorption_source' => $profileDap === null ? 'policy_default' : 'profile',
            'sed_mg_kg_bw_day' => $sed,
            'sed_ug_kg_bw_day' => DecimalMath::mul($sed, '1000'),
            'mos' => $this->marginOfSafety($profile, $sed, $dap, $parameters),
            'cmax' => $this->limit($profile?->cmax_percent, $concentrationPercent),
            'adi' => $this->limit($profile?->adi_mg_kg_bw_day, $sed),
            'ttc' => $this->limit($profile?->ttc_mg_kg_bw_day, $sed),
            'qra2' => $category === 'allergen' ? $this->quantitativeRiskAssessment($profile, $concentrationPercent, $parameters['qra2']) : null,
            'legal' => $legal,
            'hazard_endpoints' => $profile === null ? [] : collect(self::HAZARD_NOTES)
                ->filter(fn (string $endpoint, string $column): bool => filled($profile->{$column}))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function marginOfSafety(?ToxicologyProfile $profile, string $sed, string $dap, array $parameters): array
    {
        if ($profile?->noael_mg_kg_bw_day === null || $profile->noael_route === null) {
            return ['available' => false, 'reason' => 'no_noael'];
        }

        $bioavailability = $profile->oral_bioavailability_percent === null
            ? $parameters['policy_oral_bioavailability']
            : DecimalMath::of($profile->oral_bioavailability_percent);
        $systemicNoael = $this->exposure->systemicNoael(DecimalMath::of($profile->noael_mg_kg_bw_day), $profile->noael_route, $bioavailability);

        if ($systemicNoael === null) {
            return ['available' => false, 'reason' => $profile->noael_route === NoaelRoute::Dermal ? 'dermal_study_absorption_unknown' : 'route_not_convertible'];
        }

        $mos = $this->exposure->marginOfSafety($systemicNoael, $sed);

        if ($mos === null) {
            return ['available' => false, 'reason' => 'no_exposure'];
        }

        return [
            'available' => true,
            'noael_route' => $profile->noael_route->value,
            'oral_bioavailability_percent' => $bioavailability,
            'noael_systemic' => $systemicNoael,
            'value' => $mos,
            'threshold' => $parameters['mos_threshold'],
            'passes' => DecimalMath::cmp($mos, $parameters['mos_threshold']) >= 0,
        ];
    }

    /**
     * @return array{available: bool, limit?: string, value?: string, passes?: bool}
     */
    private function limit(mixed $limit, string $value): array
    {
        if ($limit === null) {
            return ['available' => false];
        }

        $limitValue = DecimalMath::of($limit);

        return ['available' => true, 'limit' => $limitValue, 'value' => $value, 'passes' => DecimalMath::cmp($value, $limitValue) <= 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function quantitativeRiskAssessment(?ToxicologyProfile $profile, string $concentrationPercent, ?Qra2Parameter $preset): array
    {
        if ($profile?->nesil_ug_cm2 === null) {
            return ['available' => false, 'reason' => 'no_nesil'];
        }

        if (! $preset instanceof Qra2Parameter) {
            return ['available' => false, 'reason' => 'no_qra2_preset'];
        }

        $result = $this->qra2->forPreset(DecimalMath::of($profile->nesil_ug_cm2), $preset);

        return [
            'available' => true,
            ...$result,
            'nesil_ug_cm2' => DecimalMath::of($profile->nesil_ug_cm2),
            'preset_id' => $preset->id,
            'preset_version' => $preset->version,
            'factor_deviation_reason' => $preset->factor_deviation_reason,
            'value' => $concentrationPercent,
            'passes' => DecimalMath::cmp($concentrationPercent, $result['cmax_percent']) <= 0,
        ];
    }
}
