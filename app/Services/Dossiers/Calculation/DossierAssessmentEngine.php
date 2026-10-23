<?php

namespace App\Services\Dossiers\Calculation;

use App\Data\DossierAssessmentOptions;
use App\Enums\AssessmentReviewState;
use App\Enums\DossierRowState;
use App\Enums\WorkspaceModule;
use App\Exceptions\DossierCalculationException;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * The live DIP assessment of a saved formula (spec §4): both compositions, overlays, one assessed row per substance with
 * every contribution summed (decision 7), every indicator, suggested routes, row states, and the blockers and warnings
 * of spec §5. Read-only: nothing is stored; phase 4 snapshots the result.
 */
class DossierAssessmentEngine
{
    /**
     * Final composition kinds that must be found in the substance catalogue (decision 15).
     */
    private const IDENTITY_REQUIRED_KINDS = ['soap_salt', 'glycerin', 'residual_water'];

    /**
     * A blend share its linked components do not account for: it cannot be assessed, so it blocks submission.
     */
    private const UNRESOLVED_BLEND_REASONS = ['unresolved_blend_component', 'blend_components_exceed_parent'];

    public function __construct(
        private readonly WorkspaceAuthorization $authorization,
        private readonly DossierCompositionBuilder $compositions,
        private readonly ConstituentOverlayBuilder $overlays,
        private readonly AssessmentParameterResolver $parameters,
        private readonly AssessedMaterialResolver $materials,
        private readonly LegalEntryChecker $legal,
        private readonly RowIndicatorEvaluator $indicators,
        private readonly RowStateResolver $states,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assess(User $actor, Workspace $workspace, RecipeVersion $version, DossierAssessmentOptions $options = new DossierAssessmentOptions): array
    {
        $this->assertAccess($actor, $workspace, $version);
        $on = ($options->evaluatedOn ?? CarbonImmutable::today())->startOfDay();

        try {
            $composition = $this->compositions->build($version, $options->measuredCureLossPercent, $options->override('residual_water_percent'));
            $recipe = Recipe::withoutGlobalScopes()->findOrFail($version->recipe_id);
            $parameters = $this->parameters->resolve($recipe->product_type_id, $version->ifra_product_category_id, $version->ifra_amendment_id, $options);
        } catch (DossierCalculationException $exception) {
            return [
                'status' => 'cannot_run',
                'evaluated_on' => $on->toDateString(),
                'reason' => $exception->reason,
                'blockers' => [['code' => 'calculation_cannot_run', 'reason' => $exception->reason, 'context' => $exception->context]],
                'warnings' => [],
                'can_submit' => false,
                'can_approve' => false,
            ];
        }

        $overlays = $this->overlays->build($workspace, $composition['initial'], $composition['basis']['initial_mass_g'], $composition['basis']['cured_mass_g']);
        $contributions = [
            ...$this->compositionContributions($composition['final']),
            ...$this->overlayContributions($overlays['rows'], $composition['basis']['cured_mass_g']),
        ];
        $rows = collect($contributions)
            ->groupBy(fn (array $part): string => $this->rowKey($part))
            ->map(fn (Collection $group, string $key): array => $this->assessedRow($key, $group->values()->all(), $parameters, $options, $on))
            ->values()
            ->all();
        $blockers = $this->blockers($composition, $contributions);

        return [
            'status' => 'calculated',
            'evaluated_on' => $on->toDateString(),
            'composition' => $composition,
            'overlays' => $overlays,
            'parameters' => [...$parameters['used'], $this->residualParameter($composition['basis'], $options)],
            'qra2_preset' => $parameters['qra2'] === null ? null : [
                'id' => $parameters['qra2']->id,
                'version' => $parameters['qra2']->version,
                'label' => $parameters['qra2']->preset_label,
            ],
            'rows' => $rows,
            'blockers' => $blockers,
            'warnings' => $this->warnings($composition, $overlays, $parameters, $contributions, $rows),
            'can_submit' => $blockers === [],
            'can_approve' => $blockers === [] && collect($rows)->every(fn (array $row): bool => $row['state'] === DossierRowState::Conforme->value),
        ];
    }

    private function assertAccess(User $actor, Workspace $workspace, RecipeVersion $version): void
    {
        if (! $this->authorization->canViewModule($actor, $workspace->id, WorkspaceModule::ProductDossiers)
            || (int) $version->workspace_id !== (int) $workspace->id) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $final
     * @return list<array<string, mixed>>
     */
    private function compositionContributions(array $final): array
    {
        return collect($final)
            ->flatMap(fn (array $finalRow): array => $this->materials->finalRowContributions($finalRow))
            ->map(fn (array $part): array => [
                ...$part,
                'source' => 'composition',
                'allergen_id' => $part['substance']?->allergen_id,
                'needs_justification' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * One contribution per declaring ingredient, as a % of the cured mass. A declaration of a substance by an ingredient
     * that *is* that substance is skipped: the neat material is already in the composition (decision 7).
     *
     * @param  list<array<string, mixed>>  $overlays
     * @return list<array<string, mixed>>
     */
    private function overlayContributions(array $overlays, string $curedMass): array
    {
        $contributions = [];

        foreach ($overlays as $overlay) {
            $substance = $this->materials->substanceForOverlay($overlay);
            $name = $substance?->name ?? (string) Allergen::query()->whereKey($overlay['allergen_id'])->value('inci_name');

            foreach ($overlay['contributions'] as $part) {
                $source = Ingredient::withoutGlobalScopes()->find($part['ingredient_id']);
                $sourceIdentity = $source instanceof Ingredient ? $this->materials->substanceByInci($source->inci_name)[0] : null;

                if ($substance instanceof Substance && $sourceIdentity?->is($substance)) {
                    continue;
                }

                $contributions[] = [
                    'source' => $overlay['kind'],
                    'source_key' => $overlay['key'].'|'.$part['initial_key'],
                    'final_key' => null,
                    'kind' => $overlay['kind'],
                    'name' => $name,
                    'concentration_percent' => DecimalMath::percentOf($part['grams'], $curedMass),
                    'ingredient' => $source,
                    'substance' => $substance,
                    'unidentified_reason' => $substance === null ? 'not_in_catalogue' : null,
                    'allergen_id' => $overlay['allergen_id'] ?? $substance?->allergen_id,
                    'needs_justification' => $part['source'] === 'platform' || $part['suppliers_without_declaration'] !== [],
                ];
            }
        }

        return $contributions;
    }

    /**
     * Contributions of the same identity share a row; a material without identity keeps its own row.
     *
     * @param  array<string, mixed>  $part
     */
    private function rowKey(array $part): string
    {
        return match (true) {
            $part['substance'] instanceof Substance => 'substance:'.$part['substance']->id,
            $part['source'] === 'composition' => 'material:'.$part['source_key'],
            default => $part['kind'].':'.($part['allergen_id'] ?? $part['source_key']),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $group
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function assessedRow(string $key, array $group, array $parameters, DossierAssessmentOptions $options, CarbonImmutable $on): array
    {
        $identity = $group[0]['substance'];
        $concentration = DecimalMath::add(...array_column($group, 'concentration_percent'));
        $allergenId = $identity?->allergen_id ?? collect($group)->pluck('allergen_id')->filter()->first();
        $sources = collect($group)->map(fn (array $part): string => $part['source'] === 'composition' ? 'material' : $part['source']);
        $category = $allergenId !== null
            ? 'allergen'
            : collect(['material', 'restricted_substance', 'impurity'])->first(fn (string $candidate): bool => $sources->contains($candidate));
        // Read-across comes only from the row's own composition materials, never from an ingredient that merely contains it.
        $coveredMaterials = collect($group)
            ->where('source', 'composition')
            ->map(fn (array $part): Ingredient|Substance|null => $part['ingredient'] ?? $part['substance'])
            ->filter()
            ->unique(fn (Ingredient|Substance $material): string => $material::class.':'.$material->getKey())
            ->values()
            ->all();
        $profile = $this->materials->profileFor($identity, $coveredMaterials);
        $legal = $identity === null ? LegalEntryChecker::none() : $this->legal->check($identity, $concentration, $parameters['is_rinse_off'], $on);
        $indicators = $this->indicators->evaluate($category, $concentration, $profile['profile'], $parameters, $legal);
        $confirmation = $options->confirmations[$key] ?? null;
        $state = $this->states->resolve($category, $profile['profile'] !== null, $indicators, $confirmation);

        return [
            'key' => $key,
            'category' => $category,
            'name' => $identity?->name ?? $group[0]['name'],
            'identity' => $identity === null ? null : ['substance_id' => $identity->id, 'name' => $identity->name, 'inci_name' => $identity->inci_name],
            'unidentified_reason' => $identity === null ? $group[0]['unidentified_reason'] : null,
            'concentration_percent' => $concentration,
            'contributions' => array_map(fn (array $part): array => [
                'source' => $part['source'],
                'source_key' => $part['source_key'],
                'final_key' => $part['final_key'],
                'ingredient_id' => $part['ingredient']?->id,
                'concentration_percent' => $part['concentration_percent'],
                'needs_justification' => $part['needs_justification'],
            ], $group),
            'coverages' => array_values(array_filter(array_map(fn (Ingredient|Substance $material): ?array => $this->materials->coverageSummary($material), $coveredMaterials))),
            'profile' => $profile['profile'] === null ? null : [
                'id' => $profile['profile']->id,
                'version' => $profile['profile']->version,
                'substance_id' => $profile['profile']->substance_id,
                'basis' => $profile['basis'],
            ],
            'profile_reason' => $profile['reason'],
            'indicators' => $indicators,
            'label_declaration' => $allergenId === null ? null : $this->legal->allergenDeclaration((int) $allergenId, $concentration, $parameters['is_rinse_off'], $on),
            'suggested_route' => $state['suggested_route']?->value,
            'confirmed_route' => $state['confirmed_route']?->value,
            'confirmation' => $confirmation,
            'state' => $state['state']->value,
            'state_reason' => $state['reason'],
            'needs_justification' => collect($group)->contains('needs_justification', true),
        ];
    }

    /**
     * Submission blockers of spec §5 that this phase can compute (decision 15).
     *
     * @param  array<string, mixed>  $composition
     * @param  list<array<string, mixed>>  $contributions
     * @return list<array<string, mixed>>
     */
    private function blockers(array $composition, array $contributions): array
    {
        $blockers = [];

        if (! $composition['tracing']['passes']) {
            $blockers[] = ['code' => 'tracing_gap', 'gaps' => $composition['tracing']['gaps']];
        }

        foreach (collect($composition['initial'])->whereNotNull('ingredient_id')->unique('ingredient_id') as $row) {
            $ingredient = Ingredient::withoutGlobalScopes()->find($row['ingredient_id']);
            $state = $ingredient instanceof Ingredient
                ? ($this->materials->coverageSummary($ingredient)['state'] ?? AssessmentReviewState::NotReviewed->value)
                : AssessmentReviewState::NotReviewed->value;

            if ($state !== AssessmentReviewState::Sufficient->value) {
                $blockers[] = ['code' => 'material_'.$state, 'scope' => 'initial', 'key' => $row['key'], 'name' => $row['name']];
            }
        }

        foreach (collect($contributions)->where('source', 'composition') as $part) {
            if (in_array($part['unidentified_reason'], self::UNRESOLVED_BLEND_REASONS, true)) {
                $blockers[] = ['code' => 'blend_component_unresolved', 'scope' => 'final', 'key' => $part['source_key'], 'name' => $part['name'], 'reason' => $part['unidentified_reason']];

                continue;
            }

            if (in_array($part['kind'], self::IDENTITY_REQUIRED_KINDS, true) && $part['substance'] === null) {
                $blockers[] = ['code' => 'final_material_unidentified', 'scope' => 'final', 'key' => $part['final_key'], 'name' => $part['name'], 'reason' => $part['unidentified_reason']];

                continue;
            }

            $material = $part['ingredient'] ?? $part['substance'];
            $state = $material === null
                ? AssessmentReviewState::NotReviewed->value
                : ($this->materials->coverageSummary($material)['state'] ?? AssessmentReviewState::NotReviewed->value);

            if ($state !== AssessmentReviewState::Sufficient->value) {
                $blockers[] = ['code' => 'material_'.$state, 'scope' => 'final', 'key' => $part['final_key'], 'name' => $part['name']];
            }
        }

        return collect($blockers)
            ->unique(fn (array $blocker): string => $blocker['code'].'|'.($blocker['scope'] ?? '').'|'.($blocker['key'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * Visible warnings carried into the snapshot; they never block submission.
     *
     * @param  array<string, mixed>  $composition
     * @param  array<string, mixed>  $overlays
     * @param  array<string, mixed>  $parameters
     * @param  list<array<string, mixed>>  $contributions
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function warnings(array $composition, array $overlays, array $parameters, array $contributions, array $rows): array
    {
        $warnings = [];

        if (in_array($composition['basis']['residual_source'], ['formula_estimate', 'override'], true)) {
            $warnings[] = ['code' => 'final_composition_estimated'];
        }

        foreach ($overlays['rows'] as $overlay) {
            if ($overlay['needs_justification']) {
                $warnings[] = ['code' => 'constituent_needs_justification', 'key' => $overlay['key']];
            }
        }

        foreach ($overlays['undeclared'] as $undeclared) {
            $warnings[] = ['code' => 'suppliers_without_declaration', ...$undeclared];
        }

        $superseded = collect($rows)
            ->flatMap(fn (array $row): array => $row['coverages'])
            ->filter(fn (array $coverage): bool => $coverage['relies_on_superseded_profiles'])
            ->unique('coverage_id');

        foreach ($superseded as $coverage) {
            $warnings[] = ['code' => 'coverage_relies_on_superseded_profiles', 'coverage_id' => $coverage['coverage_id']];
        }

        if ($parameters['qra2'] === null && collect($rows)->contains('category', 'allergen')) {
            $warnings[] = ['code' => 'no_qra2_preset'];
        }

        foreach ($rows as $row) {
            if ($row['identity'] === null) {
                $warnings[] = ['code' => $row['category'] === 'allergen' ? 'allergen_without_assessed_material' : 'no_legal_identity', 'key' => $row['key']];
            }
        }

        $unreviewed = collect($rows)->flatMap(fn (array $row): array => data_get($row, 'indicators.legal.unreviewed_entry_ids', []))->unique()->values()->all();

        if ($unreviewed !== []) {
            $warnings[] = ['code' => 'unreviewed_legal_entries', 'entry_ids' => $unreviewed];
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $basis
     * @return array<string, mixed>
     */
    private function residualParameter(array $basis, DossierAssessmentOptions $options): array
    {
        return [
            'key' => 'residual_water_percent',
            'value' => $basis['residual_water_percent'],
            'unit' => '%',
            'source' => $basis['residual_source'],
            'default' => null,
            'justification' => $options->overrides['residual_water_percent']['justification'] ?? null,
            'measured_cure_loss_percent' => $basis['measured_cure_loss_percent'],
        ];
    }
}
