<?php

namespace App\Services\Dossiers\Calculation;

use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\IngredientSubstanceEntry;
use App\Models\MaterialAssessmentCoverage;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Identity and profile of each assessed material (decision 6). The identity is the substance a material *is*: annex
 * checks and label rules follow it. The profile is the identity's own, or a single recorded read-across reference from
 * the material's coverage; a reference never replaces the identity.
 */
class AssessedMaterialResolver
{
    private const MAX_COMPONENT_DEPTH = 3;

    public function __construct(private readonly MaterialAssessmentCoverageService $coverageService) {}

    /**
     * The identity contributions of one final composition row; a blend with components gives one per component.
     *
     * @param  array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, percent: string}  $finalRow
     * @return list<array{source_key: string, final_key: string, kind: string, name: string, concentration_percent: string,
     *                    ingredient: Ingredient|null, substance: Substance|null, unidentified_reason: string|null}>
     */
    public function finalRowContributions(array $finalRow): array
    {
        if (in_array($finalRow['kind'], ['soap_salt', 'glycerin', 'residual_water'], true)) {
            [$substance, $reason] = $this->substanceByInci($finalRow['inci_name']);

            return [$this->contribution($finalRow['key'], $finalRow, $finalRow['name'], $finalRow['percent'], null, $substance, $reason)];
        }

        $ingredient = $finalRow['ingredient_id'] === null ? null : Ingredient::withoutGlobalScopes()->find($finalRow['ingredient_id']);

        return $this->ingredientContributions($finalRow['key'], $finalRow, $ingredient, $finalRow['percent'], 0);
    }

    /**
     * The one substance whose INCI matches, case-insensitive; none or several is a named reason.
     *
     * @return array{0: Substance|null, 1: string|null}
     */
    public function substanceByInci(?string $inci): array
    {
        if (blank($inci)) {
            return [null, 'not_in_catalogue'];
        }

        $matches = Substance::query()
            ->whereRaw('upper(inci_name) = ?', [mb_strtoupper(trim((string) $inci))])
            ->orderBy('id')
            ->limit(2)
            ->get();

        return match ($matches->count()) {
            1 => [$matches->first(), null],
            0 => [null, 'not_in_catalogue'],
            default => [null, 'ambiguous_inci'],
        };
    }

    /**
     * The identity of an overlay row: the allergen's catalogue link, or the impurity or restricted substance.
     *
     * @param  array{allergen_id: int|null, substance_id: int|null}  $overlayRow
     */
    public function substanceForOverlay(array $overlayRow): ?Substance
    {
        return $overlayRow['allergen_id'] !== null
            ? Substance::query()->where('allergen_id', $overlayRow['allergen_id'])->orderBy('id')->first()
            : Substance::query()->find($overlayRow['substance_id']);
    }

    /**
     * The identity's own current profile; otherwise the single whole-material reference of the covered materials, as a
     * recorded read-across; several distinct references are not chosen between.
     *
     * @param  list<Ingredient|Substance>  $coveredMaterials
     * @return array{profile: ToxicologyProfile|null, basis: string|null, reference_substance_id: int|null, reason: string|null}
     */
    public function profileFor(?Substance $identity, array $coveredMaterials): array
    {
        $own = $this->currentProfile($identity);

        if ($own instanceof ToxicologyProfile) {
            return ['profile' => $own, 'basis' => 'own', 'reference_substance_id' => $identity->id, 'reason' => null];
        }

        $references = collect($coveredMaterials)
            ->flatMap(fn (Ingredient|Substance $material): Collection => $this->wholeMaterialReferences($material))
            ->reject(fn (Substance $reference): bool => $identity !== null && $reference->is($identity))
            ->unique('id')
            ->values();

        if ($references->count() > 1) {
            return ['profile' => null, 'basis' => null, 'reference_substance_id' => null, 'reason' => 'ambiguous_reference'];
        }

        $reference = $references->first();
        $profile = $this->currentProfile($reference);

        return $profile instanceof ToxicologyProfile
            ? ['profile' => $profile, 'basis' => 'reference', 'reference_substance_id' => $reference->id, 'reason' => null]
            : ['profile' => null, 'basis' => null, 'reference_substance_id' => null, 'reason' => 'no_profile'];
    }

    /**
     * @return array{coverage_id: int, state: string, covers_whole_material: bool, covers_constituents: bool, relies_on_superseded_profiles: bool}|null
     */
    public function coverageSummary(Ingredient|Substance $material): ?array
    {
        $coverage = $this->coverageService->coverageFor($material);

        return $coverage instanceof MaterialAssessmentCoverage ? [
            'coverage_id' => $coverage->id,
            'state' => $coverage->review_state->value,
            'covers_whole_material' => $coverage->covers_whole_material,
            'covers_constituents' => $coverage->covers_constituents,
            'relies_on_superseded_profiles' => $coverage->reliesOnSupersededProfiles(),
        ] : null;
    }

    private function currentProfile(?Substance $substance): ?ToxicologyProfile
    {
        return $substance === null
            ? null
            : ToxicologyProfile::query()->current()->where('substance_id', $substance->id)->orderByDesc('version')->first();
    }

    /**
     * @param  array<string, mixed>  $finalRow
     * @return list<array<string, mixed>>
     */
    private function ingredientContributions(string $sourceKey, array $finalRow, ?Ingredient $ingredient, string $percent, int $depth): array
    {
        if (! $ingredient instanceof Ingredient) {
            return [$this->contribution($sourceKey, $finalRow, $finalRow['name'], $percent, null, null, 'not_in_catalogue')];
        }

        $components = IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->with(['componentIngredient' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->get();

        if ($components->isNotEmpty() && $depth < self::MAX_COMPONENT_DEPTH) {
            $resolved = $components->filter(fn (IngredientComponent $component): bool => $component->componentIngredient instanceof Ingredient);
            $contributions = $resolved
                ->flatMap(fn (IngredientComponent $component): array => $this->ingredientContributions(
                    "{$sourceKey}|component:{$component->component_ingredient_id}",
                    $finalRow,
                    $component->componentIngredient,
                    DecimalMath::div(DecimalMath::mul($percent, DecimalMath::of($component->percentage_in_parent)), '100'),
                    $depth + 1,
                ))
                ->values()
                ->all();

            return [...$contributions, ...$this->unresolvedShare($sourceKey, $finalRow, $ingredient, $percent, $resolved)];
        }

        [$substance, $reason] = $this->substanceByInci($ingredient->inci_name);

        return [$this->contribution($sourceKey, $finalRow, (string) $ingredient->display_name, $percent, $ingredient, $substance, $reason)];
    }

    /**
     * The share of a blend that its linked components do not account for (an unlinked component, or components below
     * 100 %). It is kept as an unidentified contribution so it blocks submission instead of disappearing.
     *
     * @param  array<string, mixed>  $finalRow
     * @param  Collection<int, IngredientComponent>  $resolved
     * @return list<array<string, mixed>>
     */
    private function unresolvedShare(string $sourceKey, array $finalRow, Ingredient $blend, string $percent, Collection $resolved): array
    {
        $resolvedShare = DecimalMath::add(...$resolved->map(fn (IngredientComponent $component): string => DecimalMath::of($component->percentage_in_parent))->all());
        $remainder = DecimalMath::sub('100', $resolvedShare);
        $tolerance = DecimalMath::of(config('dossiers.percent_tolerance'));

        if (DecimalMath::withinTolerance($remainder, '0', $tolerance)) {
            return [];
        }

        $exceeds = DecimalMath::cmp($remainder, '0') < 0;

        return [$this->contribution(
            "{$sourceKey}|unresolved",
            $finalRow,
            "{$blend->display_name} — part non identifiée",
            $exceeds ? '0' : DecimalMath::div(DecimalMath::mul($percent, $remainder), '100'),
            null,
            null,
            $exceeds ? 'blend_components_exceed_parent' : 'unresolved_blend_component',
        )];
    }

    /**
     * Supporting substances of the material's coverage that are not tracked constituents: no allergen link and, for an
     * ingredient, not among its substance entries. Constituents are assessed in their own rows.
     *
     * @return Collection<int, Substance>
     */
    private function wholeMaterialReferences(Ingredient|Substance $material): Collection
    {
        $coverage = $this->coverageService->coverageFor($material);

        if (! $coverage instanceof MaterialAssessmentCoverage) {
            return collect();
        }

        $constituentIds = $material instanceof Ingredient
            ? IngredientSubstanceEntry::query()->where('ingredient_id', $material->id)->pluck('substance_id')->all()
            : [];

        return $coverage->supportingSubstances()
            ->orderBy('substance_catalog.id')
            ->get()
            ->filter(fn (Substance $substance): bool => $substance->allergen_id === null && ! in_array($substance->id, $constituentIds, true))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $finalRow
     * @return array<string, mixed>
     */
    private function contribution(string $sourceKey, array $finalRow, string $name, string $percent, ?Ingredient $ingredient, ?Substance $substance, ?string $reason): array
    {
        return [
            'source_key' => $sourceKey,
            'final_key' => $finalRow['key'],
            'kind' => $finalRow['kind'],
            'name' => $name,
            'concentration_percent' => $percent,
            'ingredient' => $ingredient,
            'substance' => $substance,
            'unidentified_reason' => $substance === null ? $reason : null,
        ];
    }
}
