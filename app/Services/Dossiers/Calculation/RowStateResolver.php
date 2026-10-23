<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\AssessmentRoute;
use App\Enums\DossierRowState;

/**
 * Row states (spec §4.2, decision 12). A row is concluded only through a confirmed, justified route whose indicator
 * passes, with its hazard endpoints addressed and — for a fragrance allergen — QRA2 passing. A binding breach makes the
 * row non-conforming whatever else is true.
 */
class RowStateResolver
{
    private const SUGGESTION_ORDER = [
        AssessmentRoute::MarginOfSafety,
        AssessmentRoute::Cmax,
        AssessmentRoute::Adi,
        AssessmentRoute::Ttc,
        AssessmentRoute::Qra2,
    ];

    /**
     * @param  array<string, mixed>  $indicators
     * @param  array{route: string, justification: string, endpoints_addressed?: bool, annex_conditions?: 'met'|'not_met'}|null  $confirmation
     * @return array{state: DossierRowState, reason: string|null, suggested_route: AssessmentRoute|null, confirmed_route: AssessmentRoute|null}
     */
    public function resolve(string $category, bool $hasProfile, array $indicators, ?array $confirmation): array
    {
        $suggested = $hasProfile ? $this->suggestedRoute($indicators) : null;
        $confirmed = $confirmation !== null && filled($confirmation['justification'] ?? null)
            ? AssessmentRoute::tryFrom((string) ($confirmation['route'] ?? ''))
            : null;
        $result = fn (DossierRowState $state, ?string $reason): array => [
            'state' => $state,
            'reason' => $reason,
            'suggested_route' => $suggested,
            'confirmed_route' => $confirmed,
        ];

        if ((bool) data_get($indicators, 'legal.binding_breach', false)) {
            return $result(DossierRowState::NonConforme, 'binding_requirement');
        }

        // A condition recorded as not met is a breach of a binding requirement, whatever route is chosen.
        if ((bool) data_get($indicators, 'legal.needs_verification', false) && ($confirmation['annex_conditions'] ?? null) === 'not_met') {
            return $result(DossierRowState::NonConforme, 'annex_condition_not_met');
        }

        if (! $hasProfile) {
            return $result(DossierRowState::AJustifier, 'no_profile');
        }

        if ($confirmed === null) {
            return $suggested === null
                ? $result(DossierRowState::AJustifier, 'no_route')
                : $result(DossierRowState::RouteSuggere, null);
        }

        $indicator = $indicators[$confirmed->value] ?? null;

        if (! is_array($indicator) || ($indicator['available'] ?? false) !== true) {
            return $result(DossierRowState::AJustifier, 'confirmed_route_unavailable');
        }

        if (($indicator['passes'] ?? false) !== true) {
            return $result(DossierRowState::AJustifier, 'confirmed_route_fails');
        }

        if ($category === 'allergen' && (data_get($indicators, 'qra2.available') !== true || data_get($indicators, 'qra2.passes') !== true)) {
            return $result(DossierRowState::AJustifier, 'qra2_required');
        }

        if (($indicators['hazard_endpoints'] ?? []) !== [] && ($confirmation['endpoints_addressed'] ?? false) !== true) {
            return $result(DossierRowState::AJustifier, 'endpoints_not_addressed');
        }

        if ((bool) data_get($indicators, 'legal.needs_verification', false) && ($confirmation['annex_conditions'] ?? null) !== 'met') {
            return $result(DossierRowState::AJustifier, 'conditions_not_verified');
        }

        return $result(DossierRowState::Conforme, null);
    }

    /**
     * @param  array<string, mixed>  $indicators
     */
    private function suggestedRoute(array $indicators): ?AssessmentRoute
    {
        foreach (self::SUGGESTION_ORDER as $route) {
            if (data_get($indicators, $route->value.'.available') === true) {
                return $route;
            }
        }

        return null;
    }
}
