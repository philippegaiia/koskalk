<?php

use App\Enums\AssessmentRoute;
use App\Enums\DossierRowState;
use App\Services\Dossiers\Calculation\RowStateResolver;

/**
 * Indicators in the evaluator's shape: MoS available and passing unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stateIndicators(array $overrides = []): array
{
    return [
        'mos' => ['available' => true, 'passes' => true],
        'cmax' => ['available' => false],
        'adi' => ['available' => false],
        'ttc' => ['available' => false],
        'qra2' => null,
        'legal' => ['binding_breach' => false, 'needs_verification' => false],
        'hazard_endpoints' => [],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function confirmedRoute(string $route = 'mos', array $extra = []): array
{
    return ['route' => $route, 'justification' => 'Systemic NOAEL from a sourced oral study.', ...$extra];
}

it('resolves each row state from spec §4.2', function (string $category, bool $hasProfile, array $indicators, ?array $confirmation, DossierRowState $state, ?string $reason): void {
    $result = app(RowStateResolver::class)->resolve($category, $hasProfile, $indicators, $confirmation);

    expect($result['state'])->toBe($state)
        ->and($result['reason'])->toBe($reason);
})->with([
    'a binding breach wins over a passing, confirmed MoS' => ['material', true, stateIndicators(['legal' => ['binding_breach' => true, 'needs_verification' => false]]), confirmedRoute(), DossierRowState::NonConforme, 'binding_requirement'],
    'a passing indicator alone is only suggested' => ['material', true, stateIndicators(), null, DossierRowState::RouteSuggere, null],
    'a confirmation without justification counts as none' => ['material', true, stateIndicators(), ['route' => 'mos', 'justification' => ''], DossierRowState::RouteSuggere, null],
    'confirmed, passing, nothing else to address' => ['material', true, stateIndicators(), confirmedRoute(), DossierRowState::Conforme, null],
    'the confirmed route fails' => ['material', true, stateIndicators(['mos' => ['available' => true, 'passes' => false]]), confirmedRoute(), DossierRowState::AJustifier, 'confirmed_route_fails'],
    'the confirmed route is unavailable' => ['material', true, stateIndicators(), confirmedRoute('cmax'), DossierRowState::AJustifier, 'confirmed_route_unavailable'],
    'an allergen with a large MoS but no QRA2' => ['allergen', true, stateIndicators(['qra2' => ['available' => false, 'reason' => 'no_qra2_preset']]), confirmedRoute(), DossierRowState::AJustifier, 'qra2_required'],
    'an allergen whose QRA2 passes' => ['allergen', true, stateIndicators(['qra2' => ['available' => true, 'passes' => true]]), confirmedRoute(), DossierRowState::Conforme, null],
    'hazard endpoints not addressed' => ['material', true, stateIndicators(['hazard_endpoints' => ['sensitisation']]), confirmedRoute(), DossierRowState::AJustifier, 'endpoints_not_addressed'],
    'hazard endpoints addressed' => ['material', true, stateIndicators(['hazard_endpoints' => ['sensitisation']]), confirmedRoute('mos', ['endpoints_addressed' => true]), DossierRowState::Conforme, null],
    'annex conditions not verified' => ['material', true, stateIndicators(['legal' => ['binding_breach' => false, 'needs_verification' => true]]), confirmedRoute(), DossierRowState::AJustifier, 'conditions_not_verified'],
    'annex conditions recorded as met' => ['material', true, stateIndicators(['legal' => ['binding_breach' => false, 'needs_verification' => true]]), confirmedRoute('mos', ['annex_conditions' => 'met']), DossierRowState::Conforme, null],
    'an annex condition recorded as not met' => ['material', true, stateIndicators(['legal' => ['binding_breach' => false, 'needs_verification' => true]]), confirmedRoute('mos', ['annex_conditions' => 'not_met']), DossierRowState::NonConforme, 'annex_condition_not_met'],
    'no indicator available' => ['material', true, stateIndicators(['mos' => ['available' => false]]), null, DossierRowState::AJustifier, 'no_route'],
    'no toxicological profile' => ['material', false, stateIndicators(), confirmedRoute(), DossierRowState::AJustifier, 'no_profile'],
]);

it('suggests MoS first, then Cmax, ADI, TTC and QRA2', function (): void {
    $resolver = app(RowStateResolver::class);

    expect($resolver->resolve('material', true, stateIndicators(), null)['suggested_route'])->toBe(AssessmentRoute::MarginOfSafety)
        ->and($resolver->resolve('material', true, stateIndicators(['mos' => ['available' => false], 'cmax' => ['available' => true, 'passes' => true]]), null)['suggested_route'])->toBe(AssessmentRoute::Cmax)
        ->and($resolver->resolve('allergen', true, stateIndicators(['mos' => ['available' => false], 'qra2' => ['available' => true, 'passes' => true]]), null)['suggested_route'])->toBe(AssessmentRoute::Qra2);
});
