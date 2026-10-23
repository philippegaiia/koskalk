<?php

namespace App\Services\Dossiers\Calculation;

use App\Data\DossierAssessmentOptions;
use App\Data\DossierExposureParameters;
use App\Exceptions\DossierCalculationException;
use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\Qra2Parameter;
use App\Support\DecimalMath;

/**
 * The versioned parameters of one calculation (spec §2.3): current policy, current exposure preset of the product type,
 * current QRA2 preset of the formula's IFRA category and amendment, and any justified dossier override. Every value
 * used is listed with its source so the snapshot can show how each figure was obtained.
 */
class AssessmentParameterResolver
{
    /**
     * @return array{policy: AssessmentPolicy, exposure_preset: ExposurePreset, qra2: Qra2Parameter|null,
     *               exposure: DossierExposureParameters, policy_dap: string, policy_oral_bioavailability: string,
     *               mos_threshold: string, is_rinse_off: bool,
     *               used: list<array{key: string, value: string, unit: string, source: string, default: string, justification: string|null}>}
     */
    public function resolve(?int $productTypeId, ?int $ifraCategoryId, ?int $ifraAmendmentId, DossierAssessmentOptions $options): array
    {
        $policy = AssessmentPolicy::query()->current()->orderByDesc('version')->first()
            ?? throw new DossierCalculationException('no_assessment_policy');
        $preset = $productTypeId === null ? null : ExposurePreset::query()
            ->current()
            ->where('product_type_id', $productTypeId)
            ->orderByDesc('version')
            ->first();

        if (! $preset instanceof ExposurePreset) {
            throw new DossierCalculationException('no_exposure_preset');
        }

        $qra2 = $ifraCategoryId === null || $ifraAmendmentId === null ? null : Qra2Parameter::query()
            ->current()
            ->where('ifra_product_category_id', $ifraCategoryId)
            ->where('ifra_amendment_id', $ifraAmendmentId)
            ->orderByDesc('version')
            ->first();
        $presetSource = "exposure_preset:{$preset->id}:v{$preset->version}";
        $policySource = "assessment_policy:{$policy->id}:v{$policy->version}";
        $used = [];
        $value = function (string $key, mixed $default, string $unit, string $source) use ($options, &$used): string {
            $defaultValue = DecimalMath::of($default);
            $override = $options->override($key);

            if (! DossierAssessmentOptions::withinBounds($key, $override ?? $defaultValue)) {
                throw new DossierCalculationException('invalid_parameter', ['key' => $key]);
            }

            $used[] = [
                'key' => $key,
                'value' => $override ?? $defaultValue,
                'unit' => $unit,
                'source' => $override === null ? $source : 'override',
                'default' => $defaultValue,
                'justification' => $override === null ? null : $options->overrides[$key]['justification'],
            ];

            return $override ?? $defaultValue;
        };

        $exposure = new DossierExposureParameters(
            $value('amount_per_use_g', $preset->amount_per_use_g, 'g', $presetSource),
            $value('uses_per_day', $preset->uses_per_day, 'per day', $presetSource),
            $value('retention_factor', $preset->retention_factor, '', $presetSource),
            $value('body_weight_kg', $policy->body_weight_kg, 'kg', $policySource),
        );
        $policyDap = $value('default_dermal_absorption_percent', $policy->default_dermal_absorption_percent, '%', $policySource);
        $policyBioavailability = $value('default_oral_bioavailability_percent', $policy->default_oral_bioavailability_percent, '%', $policySource);
        $mosThreshold = DecimalMath::of($policy->mos_threshold);

        if (! DecimalMath::isPositive($mosThreshold)) {
            throw new DossierCalculationException('invalid_parameter', ['key' => 'mos_threshold']);
        }

        $used[] = ['key' => 'mos_threshold', 'value' => $mosThreshold, 'unit' => '', 'source' => $policySource, 'default' => $mosThreshold, 'justification' => null];

        return [
            'policy' => $policy,
            'exposure_preset' => $preset,
            'qra2' => $qra2,
            'exposure' => $exposure,
            'policy_dap' => $policyDap,
            'policy_oral_bioavailability' => $policyBioavailability,
            'mos_threshold' => $mosThreshold,
            'is_rinse_off' => $preset->is_rinse_off,
            'used' => $used,
        ];
    }
}
