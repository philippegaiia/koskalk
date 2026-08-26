<?php

namespace App\Services\Production;

use App\Enums\ProductionFormulaComponent;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\CanonicalSoapAlkaliResolver;
use App\Services\MassConverter;
use App\Services\RecipeWorkbenchService;
use App\Support\NumberLocale;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductionFormulaSnapshotBuilder
{
    private const int GuardScale = 18;

    private const int StorageScale = 9;

    public function __construct(
        private readonly RecipeWorkbenchService $workbenchService,
        private readonly MassConverter $massConverter,
        private readonly CanonicalSoapAlkaliResolver $alkaliResolver,
    ) {}

    /**
     * Build the complete planned manufactured formula for a production.
     *
     * Ingredient requirements become formula lines in their existing order.
     * For soap formulas, the saved Workbench draft is rescaled to the requested
     * canonical basis so the established calculator recomputes NaOH, KOH, and
     * water; calculated lines are appended in the lye-water phase.
     *
     * @param  Collection<int, array<string, mixed>>  $requirements
     * @return array{context: array<string, mixed>, lines: Collection<int, array<string, mixed>>}
     */
    public function build(
        Recipe $recipe,
        RecipeVersion $version,
        string $basisQuantityGrams,
        Collection $requirements,
    ): array {
        $snapshot = $this->workbenchService->versionSnapshot($recipe, (int) $version->id);

        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'recipe' => 'The published formula snapshot could not be rebuilt.',
            ]);
        }

        $draft = $snapshot['draft'];
        $draft['oilWeight'] = (float) $this->massConverter->fromGrams($basisQuantityGrams, $draft['oilUnit'] ?? 'g');

        // The preview uses an explicit row weight when present; strip saved
        // weights from saponified oils so the calculation rescales from
        // percentages against the replaced oil weight, matching the way
        // ingredient requirements scale. Only touch the phase when it exists
        // so cosmetic drafts never gain a saponified-oils key.
        if (isset($draft['phaseItems']['saponified_oils'])) {
            $draft['phaseItems']['saponified_oils'] = collect($draft['phaseItems']['saponified_oils'])
                ->map(fn (array $row): array => [...$row, 'weight' => 0])
                ->values()
                ->all();
        }

        $recomputed = $this->workbenchService->snapshotFromPersistedWorkbenchDraft($draft);
        $calculation = $recomputed['calculation'] ?? null;
        $isSoap = ($draft['manufacturingMode'] ?? null) === 'saponify_in_formula'
            || ($draft['phaseItems']['saponified_oils'] ?? []) !== [];

        $lines = collect();
        $sortOrder = 1;

        foreach ($requirements as $requirement) {
            if (($requirement['kind'] ?? null) !== 'ingredient'
                || ($requirement['recipe_item_id'] ?? null) === null) {
                continue;
            }

            $mass = (string) $requirement['required_mass_grams'];

            $lines->push([
                'ingredient_id' => $requirement['ingredient_id'],
                'recipe_item_id' => $requirement['recipe_item_id'],
                'component' => ProductionFormulaComponent::Ingredient,
                'subject_name_snapshot' => $requirement['subject_name_snapshot'],
                'phase_key_snapshot' => $requirement['phase_key_snapshot'],
                'phase_name_snapshot' => $requirement['phase_name_snapshot'],
                'basis_percentage_snapshot' => $this->percentageOf($mass, $basisQuantityGrams),
                'planned_mass_grams' => $mass,
                'note_snapshot' => $requirement['note_snapshot'] ?? null,
                'sort_order' => $sortOrder++,
            ]);
        }

        if ($isSoap) {
            $lines = $lines->merge($this->calculatedLines($draft, $calculation, $basisQuantityGrams, $sortOrder));
        }

        return [
            'context' => $this->context($recipe, $isSoap, $draft),
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $calculation
     * @return Collection<int, array<string, mixed>>
     */
    private function calculatedLines(
        array $draft,
        ?array $calculation,
        string $basisQuantityGrams,
        int $sortOrder,
    ): Collection {
        $lye = is_array($calculation) ? ($calculation['lye'] ?? null) : null;

        if (! is_array($lye)) {
            throw ValidationException::withMessages([
                'recipe' => 'The soap formula could not be recalculated for this production basis.',
            ]);
        }

        $selected = $lye['selected'] ?? [];
        $lyeType = $draft['lyeType'] ?? 'naoh';
        $naohWeight = (float) ($selected['naoh_weight'] ?? 0);
        $kohWeight = (float) ($selected['koh_to_weigh'] ?? 0);
        $totalLiquidWeight = (float) ($lye['water']['weight'] ?? 0);
        $waterWeight = (float) data_get($lye, 'liquid_composition.water_weight', $totalLiquidWeight);
        $dualKohPercentage = (float) ($selected['dual_lye_koh_percentage'] ?? $draft['dualKohPercentage'] ?? 40);
        $hasValidLye = match ($lyeType) {
            'koh' => $kohWeight > 0,
            'dual' => $dualKohPercentage > 0 && $dualKohPercentage < 100
                ? $naohWeight > 0 && $kohWeight > 0
                : $naohWeight > 0 || $kohWeight > 0,
            default => $naohWeight > 0,
        };

        if (! $hasValidLye) {
            throw ValidationException::withMessages([
                'recipe' => 'The soap formula calculation produced no measurable lye for this production basis.',
            ]);
        }

        if ($totalLiquidWeight <= 0) {
            throw ValidationException::withMessages([
                'recipe' => 'The soap formula calculation produced no measurable water for this production basis.',
            ]);
        }

        $candidates = [
            ['component' => ProductionFormulaComponent::Naoh, 'weight' => $selected['naoh_weight'] ?? 0],
            ['component' => ProductionFormulaComponent::Koh, 'weight' => $selected['koh_to_weigh'] ?? 0],
            ['component' => ProductionFormulaComponent::Water, 'weight' => $waterWeight],
        ];

        $unit = $draft['oilUnit'] ?? 'g';
        $kohPurity = NumberLocale::formatAdaptiveDecimal($draft['kohPurity'] ?? 90, 0, 4);
        $lines = collect();

        foreach ($candidates as $candidate) {
            if ((float) $candidate['weight'] <= 0) {
                continue;
            }

            $component = $candidate['component'];
            $ingredient = $this->alkaliIngredientFor($component);

            $mass = $this->roundStorage(
                $this->massConverter->toGrams((string) $candidate['weight'], $unit),
            );

            $lines->push([
                'ingredient_id' => $ingredient?->id,
                'recipe_item_id' => null,
                'component' => $component,
                'subject_name_snapshot' => $this->componentLabel($component, $ingredient, $kohPurity),
                'phase_key_snapshot' => 'lye_water',
                'phase_name_snapshot' => __('production_bench.production.formula.lye_water_phase'),
                'basis_percentage_snapshot' => $this->percentageOf($mass, $basisQuantityGrams),
                'planned_mass_grams' => $mass,
                'note_snapshot' => null,
                'sort_order' => $sortOrder++,
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function context(Recipe $recipe, bool $isSoap, array $draft): array
    {
        $lyeType = $isSoap ? ($draft['lyeType'] ?? 'naoh') : null;

        return [
            'calculation_basis' => $recipe->productFamily?->calculation_basis,
            'lye_type' => $lyeType,
            'superfat_percentage' => $isSoap ? ($draft['superfat'] ?? 5) : null,
            'water_mode' => $isSoap ? ($draft['waterMode'] ?? 'percent_of_oils') : null,
            'water_value' => $isSoap ? ($draft['waterValue'] ?? 38) : null,
            ...($isSoap && in_array($lyeType, ['koh', 'dual'], true) ? [
                'koh_purity_percentage' => (float) ($draft['kohPurity'] ?? 90),
            ] : []),
        ];
    }

    /**
     * The calculated alkali component's canonical material, or null for
     * non-alkali components such as water.
     */
    private function alkaliIngredientFor(ProductionFormulaComponent $component): ?Ingredient
    {
        $lyeType = match ($component) {
            ProductionFormulaComponent::Naoh => 'naoh',
            ProductionFormulaComponent::Koh => 'koh',
            default => null,
        };

        return $lyeType === null
            ? null
            : $this->alkaliResolver->resolve($lyeType);
    }

    private function componentLabel(
        ProductionFormulaComponent $component,
        ?Ingredient $ingredient,
        string $kohPurity,
    ): string {
        return match ($component) {
            ProductionFormulaComponent::Naoh => $ingredient?->localizedDisplayName()
                ?? __('production_bench.production.formula.sodium_hydroxide'),
            ProductionFormulaComponent::Koh => __('ingredients.alkalis.koh_with_purity', [
                'name' => $ingredient?->localizedDisplayName()
                    ?? __('production_bench.production.formula.potassium_hydroxide'),
                'purity' => $kohPurity,
            ]),
            ProductionFormulaComponent::Water => __('production_bench.production.formula.water'),
            ProductionFormulaComponent::Ingredient => '',
        };
    }

    private function percentageOf(string $mass, string $basis): string
    {
        if (bccomp($basis, '0', self::GuardScale) <= 0) {
            throw ValidationException::withMessages([
                'production' => 'The production basis must be greater than zero.',
            ]);
        }

        return $this->roundStorage(
            bcdiv(bcmul($mass, '100', self::GuardScale), $basis, self::GuardScale),
        );
    }

    private function roundStorage(string $value): string
    {
        $increment = '0.'.str_repeat('0', self::StorageScale).'5';
        $adjusted = bcadd($value, $increment, self::StorageScale + 1);

        return bcadd($adjusted, '0', self::StorageScale);
    }
}
