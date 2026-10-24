<?php

namespace App\Data;

use App\Enums\AssessmentRoute;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Dossier-level inputs to one DIP calculation (spec §5): overrides of scientific assumptions, each with a mandatory
 * justification and within physical bounds; the owner's confirmed routes per row key, with the answer to any annex
 * condition that cannot be checked automatically; an optional measured cure loss (% of the initial batch); the
 * evaluation date; and a justification when the formula's IFRA category is not the pilot's category 9. Phase 4
 * stores them on the dossier version.
 */
final readonly class DossierAssessmentOptions
{
    /**
     * Physical bounds (decision 11): [minimum, minimum allowed?, maximum or null, maximum allowed?].
     */
    public const BOUNDS = [
        'amount_per_use_g' => ['0', false, null, false],
        'uses_per_day' => ['0', false, null, false],
        'retention_factor' => ['0', false, '1', true],
        'body_weight_kg' => ['0', false, null, false],
        'default_dermal_absorption_percent' => ['0', true, '100', true],
        'default_oral_bioavailability_percent' => ['0', true, '100', true],
        'residual_water_percent' => ['0', true, '30', true],
        'measured_cure_loss_percent' => ['0', true, '100', false],
    ];

    public const ANNEX_CONDITION_ANSWERS = ['met', 'not_met'];

    public const OVERRIDABLE = [
        'amount_per_use_g',
        'uses_per_day',
        'retention_factor',
        'body_weight_kg',
        'default_dermal_absorption_percent',
        'default_oral_bioavailability_percent',
        'residual_water_percent',
    ];

    /**
     * @param  array<string, array{value: string, justification: string}>  $overrides
     * @param  array<string, array{route: string, justification: string, endpoints_addressed?: bool, annex_conditions?: 'met'|'not_met'}>  $confirmations
     */
    public function __construct(
        public array $overrides = [],
        public array $confirmations = [],
        public ?string $measuredCureLossPercent = null,
        public ?CarbonImmutable $evaluatedOn = null,
        public ?string $ifraCategoryJustification = null,
    ) {
        foreach ($overrides as $key => $override) {
            if (! in_array($key, self::OVERRIDABLE, true)) {
                throw new InvalidArgumentException("[{$key}] cannot be overridden in a dossier.");
            }

            if (blank($override['justification'] ?? null)) {
                throw new InvalidArgumentException("The override of [{$key}] needs a justification.");
            }

            if (! self::withinBounds($key, DecimalMath::of($override['value'] ?? null))) {
                throw new InvalidArgumentException("The override of [{$key}] is outside its physical bounds.");
            }
        }

        foreach ($confirmations as $rowKey => $confirmation) {
            if (AssessmentRoute::tryFrom((string) ($confirmation['route'] ?? '')) === null) {
                throw new InvalidArgumentException("The route confirmed for [{$rowKey}] is not an assessment route.");
            }

            if (isset($confirmation['annex_conditions']) && ! in_array($confirmation['annex_conditions'], self::ANNEX_CONDITION_ANSWERS, true)) {
                throw new InvalidArgumentException("The annex conditions of [{$rowKey}] must be recorded as met or not met.");
            }
        }

        if ($measuredCureLossPercent !== null && ! self::withinBounds('measured_cure_loss_percent', DecimalMath::of($measuredCureLossPercent))) {
            throw new InvalidArgumentException('The measured cure loss is outside its physical bounds.');
        }
    }

    public static function withinBounds(string $key, string $value): bool
    {
        [$minimum, $minimumAllowed, $maximum, $maximumAllowed] = self::BOUNDS[$key];
        $low = DecimalMath::cmp($value, $minimum);

        if ($low < 0 || ($low === 0 && ! $minimumAllowed)) {
            return false;
        }

        if ($maximum === null) {
            return true;
        }

        $high = DecimalMath::cmp($value, $maximum);

        return $high < 0 || ($high === 0 && $maximumAllowed);
    }

    public function override(string $key): ?string
    {
        return isset($this->overrides[$key]) ? DecimalMath::of($this->overrides[$key]['value']) : null;
    }
}
