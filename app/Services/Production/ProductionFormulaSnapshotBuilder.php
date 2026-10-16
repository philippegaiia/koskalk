<?php

namespace App\Services\Production;

use App\Enums\ProductionFormulaComponent;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipeVersion;
use App\Services\CanonicalSoapAlkaliResolver;
use App\Services\LiquidSoapFormulaProcessor;
use App\Services\LiquidSoapProcessCalculator;
use App\Services\MassConverter;
use App\Services\RecipeWorkbenchService;
use App\Support\NumberLocale;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class ProductionFormulaSnapshotBuilder
{
    private const int GuardScale = 18;

    private const int StorageScale = 9;

    public function __construct(
        private readonly RecipeWorkbenchService $workbenchService,
        private readonly MassConverter $massConverter,
        private readonly CanonicalSoapAlkaliResolver $alkaliResolver,
        private readonly ProductionRequirementBuilder $requirementBuilder,
        private readonly LiquidSoapFormulaProcessor $liquidSoapFormulaProcessor,
        private readonly LiquidSoapProcessCalculator $liquidSoapProcessCalculator,
    ) {}

    /**
     * Build the complete planned manufactured formula for a production.
     *
     * Ingredient requirements become formula lines in their existing order.
     * For soap formulas, the saved Workbench draft is rescaled to the requested
     * canonical basis so the established calculator recomputes NaOH, KOH, and
     * water; calculated lines are appended in the lye-water phase. Liquid soap
     * paste formulas also get a water line for each dilution and for the
     * neutralizer solution, and their process steps as shares of the basis.
     *
     * @param  Collection<int, array<string, mixed>>  $requirements
     * @return array{context: array<string, mixed>, lines: Collection<int, array<string, mixed>>, requirements: Collection<int, array<string, mixed>>}
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
                'recipe' => __('production_bench.production.validation.formula_snapshot_rebuild_failed'),
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

        // The liquid soap process reads the neutralizer and additions weights.
        // Additions rescale from percentages like the oils and their material
        // requirements; the neutralizer takes its requirement's dose.
        $isLiquidSoap = $recipe->productFamily?->usesLiquidSoapPasteBench() ?? false;
        $unit = $draft['oilUnit'] ?? 'g';
        $neutralizerRequirement = null;

        if ($isLiquidSoap) {
            $neutralizerRequirement = $requirements->first(fn (array $requirement): bool => ($requirement['kind'] ?? null) === 'ingredient'
                && ($requirement['phase_key_snapshot'] ?? null) === 'neutralization');
            $draft = $this->withNeutralizerWeight($draft, $neutralizerRequirement === null
                ? '0'
                : $this->massConverter->fromGrams((string) $neutralizerRequirement['required_mass_grams'], $unit));

            foreach (['additives', 'fragrance'] as $phaseKey) {
                if (isset($draft['phaseItems'][$phaseKey])) {
                    $draft['phaseItems'][$phaseKey] = collect($draft['phaseItems'][$phaseKey])
                        ->map(fn (array $row): array => [...$row, 'weight' => 0])
                        ->values()
                        ->all();
                }
            }
        }

        $recomputed = $this->workbenchService->snapshotFromPersistedWorkbenchDraft($draft);
        $calculation = $recomputed['calculation'] ?? null;

        // A dose saved at the theoretical amount can land just above the free
        // KOH once its storage rounding is scaled up. Within that rounding the
        // batch takes the theoretical amount, for the calculation and the
        // material requirement alike; a larger excess is still refused.
        if ($isLiquidSoap && $neutralizerRequirement !== null && data_get($calculation, 'liquid_process.status') === 'acid_exceeds_free_koh') {
            $theoreticalWeight = $this->theoreticalNeutralizerWeight($version, $neutralizerRequirement, $draft, $calculation['liquid_process'], $unit, $basisQuantityGrams);

            if ($theoreticalWeight !== null) {
                $draft = $this->withNeutralizerWeight($draft, $theoreticalWeight);
                $recomputed = $this->workbenchService->snapshotFromPersistedWorkbenchDraft($draft);
                $calculation = $recomputed['calculation'] ?? null;
                $mass = $this->roundStorage($this->massConverter->toGrams($theoreticalWeight, $unit));
                $requirements = $requirements->map(fn (array $requirement): array => $requirement === $neutralizerRequirement
                    ? [...$requirement, 'required_mass_grams' => $mass, 'percentage_snapshot' => $this->percentageOf($mass, $basisQuantityGrams)]
                    : $requirement);
            }
        }

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

        $liquidContext = [];

        if ($isLiquidSoap) {
            $liquidProcess = $this->liquidProcess($calculation);
            $lines = $lines->merge($this->liquidWaterLines($liquidProcess, $unit, $basisQuantityGrams, ((int) $lines->max('sort_order')) + 1));
            $liquidContext = [
                'soap_process' => 'liquid_paste',
                'liquid_process' => $this->liquidProcessContext($liquidProcess, $unit, $basisQuantityGrams, $requirements),
            ];
        }

        return [
            'context' => [...$this->context($recipe, $isSoap, $draft), ...$liquidContext, ...$this->procedureSnapshot($version->manufacturing_instructions)],
            'lines' => $lines,
            'requirements' => $requirements,
        ];
    }

    /** @return array{manufacturing_procedure_html: string, manufacturing_procedure_illustrations_omitted: bool} */
    private function procedureSnapshot(?string $html): array
    {
        $html ??= '';
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(strlen($html));
        foreach (['p', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'br', 'blockquote'] as $element) {
            $config = $config->allowElement($element);
        }

        return [
            'manufacturing_procedure_html' => (new HtmlSanitizer($config))->sanitize($html),
            'manufacturing_procedure_illustrations_omitted' => preg_match('/<(?:img|picture|video|audio|iframe|object|embed|svg)\b|data-media-asset/i', $html) === 1,
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
                'recipe' => __('production_bench.production.validation.formula_recalculation_failed'),
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
                'recipe' => __('production_bench.production.validation.formula_lye_missing'),
            ]);
        }

        if ($totalLiquidWeight <= 0) {
            throw ValidationException::withMessages([
                'recipe' => __('production_bench.production.validation.formula_water_missing'),
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
     * The draft with its neutralizer row weighing the given amount.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function withNeutralizerWeight(array $draft, string $weight): array
    {
        if (isset($draft['phaseItems']['neutralization'])) {
            $draft['phaseItems']['neutralization'] = collect($draft['phaseItems']['neutralization'])
                ->map(fn (array $row): array => [...$row, 'weight' => $weight])
                ->values()
                ->all();
        }

        return $draft;
    }

    /**
     * The theoretical neutralizer amount, in the draft's unit, when the
     * planned dose exceeds it by no more than the rounding it was saved
     * under: the dose's storage, the calculator's and the free KOH's, scaled
     * to the batch; null when the excess is larger.
     *
     * @param  array<string, mixed>  $neutralizerRequirement
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $process
     */
    private function theoreticalNeutralizerWeight(RecipeVersion $version, array $neutralizerRequirement, array $draft, array $process, string $unit, string $basisQuantityGrams): ?string
    {
        $item = RecipeItem::withoutGlobalScopes()->find($neutralizerRequirement['recipe_item_id'] ?? null);
        $row = collect($draft['phaseItems']['neutralization'] ?? [])->first(fn (mixed $row): bool => is_array($row) && is_numeric($row['ingredient_id'] ?? null));
        $neutralizer = is_array($row) ? $this->liquidSoapFormulaProcessor->neutralizer($row, []) : null;

        if (! $item instanceof RecipeItem || $neutralizer === null) {
            return null;
        }

        $lastDecimal = $this->massConverter->toGrams('0.00005', $unit);
        // Free KOH is reported to four decimals in the draft's unit, so the
        // amount recommended when the dose was saved, and the one here, may
        // each sit that rounding's worth of acid above the true amount.
        $freeKohRounding = bcmul(
            $this->liquidSoapProcessCalculator->recommendedWeight($lastDecimal, $neutralizer['type'], $neutralizer['koh_sap_value']),
            bcadd(bcdiv($basisQuantityGrams, $this->requirementBuilder->savedBasisGrams($version), self::GuardScale), '1', self::GuardScale),
            self::GuardScale,
        );
        $tolerance = bcadd(
            bcadd($this->requirementBuilder->neutralizerDose($version, $item, $basisQuantityGrams)['tolerance_grams'], $freeKohRounding, self::GuardScale),
            // The reported excess is itself rounded to four decimals.
            $lastDecimal,
            self::GuardScale,
        );
        $excess = $this->massConverter->toGrams((string) data_get($process, 'neutralizer.excess_acid_weight', '0'), $unit);

        if (bccomp($excess, $tolerance, self::GuardScale) > 0) {
            return null;
        }

        return $this->liquidSoapProcessCalculator->recommendedWeight(
            (string) data_get($process, 'paste_composition.free_koh_weight', '0'),
            $neutralizer['type'],
            $neutralizer['koh_sap_value'],
        );
    }

    /**
     * The recomputed liquid soap process, which must be fully calculated.
     *
     * @param  array<string, mixed>|null  $calculation
     * @return array<string, mixed>
     */
    private function liquidProcess(?array $calculation): array
    {
        $process = is_array($calculation) ? ($calculation['liquid_process'] ?? null) : null;

        if (! is_array($process) || ($process['status'] ?? null) !== 'ok') {
            throw ValidationException::withMessages([
                'recipe' => __('production_bench.production.validation.formula_recalculation_failed'),
            ]);
        }

        return $process;
    }

    /**
     * Water added after the paste: the first dilution, the water of the
     * neutralizer solution and the final dilution, each in its process phase.
     *
     * @param  array<string, mixed>  $process
     * @return Collection<int, array<string, mixed>>
     */
    private function liquidWaterLines(array $process, string $unit, string $basisQuantityGrams, int $sortOrder): Collection
    {
        $neutralizer = is_array($process['neutralizer'] ?? null) ? $process['neutralizer'] : null;
        $candidates = [
            ['phase' => 'first_dilution', 'name' => 'water', 'weight' => data_get($process, 'stages.first_dilution.add_weight', '0')],
            ['phase' => 'neutralization', 'name' => 'neutralizer_solution_water', 'weight' => $neutralizer === null
                ? '0'
                : bcsub((string) $neutralizer['solution_weight'], (string) $neutralizer['weight'], self::GuardScale)],
            ['phase' => 'final_dilution', 'name' => 'water', 'weight' => data_get($process, 'stages.final_dilution.add_weight', '0')],
        ];
        $lines = collect();

        foreach ($candidates as $candidate) {
            if (bccomp((string) $candidate['weight'], '0', self::GuardScale) <= 0) {
                continue;
            }

            $mass = $this->roundStorage($this->massConverter->toGrams((string) $candidate['weight'], $unit));

            $lines->push([
                'ingredient_id' => null,
                'recipe_item_id' => null,
                'component' => ProductionFormulaComponent::Water,
                'subject_name_snapshot' => __('production_bench.production.formula.'.$candidate['name']),
                'phase_key_snapshot' => $candidate['phase'],
                'phase_name_snapshot' => __('production_bench.production.formula.'.$candidate['phase'].'_phase'),
                'basis_percentage_snapshot' => $this->percentageOf($mass, $basisQuantityGrams),
                'planned_mass_grams' => $mass,
                'note_snapshot' => null,
                'sort_order' => $sortOrder++,
            ]);
        }

        return $lines;
    }

    /**
     * Process steps with every weight stored as a % of the basis, so a
     * rescaled production still prints the steps of its own batch.
     *
     * @param  array<string, mixed>  $process
     * @param  Collection<int, array<string, mixed>>  $requirements
     * @return array<string, mixed>
     */
    private function liquidProcessContext(array $process, string $unit, string $basisQuantityGrams, Collection $requirements): array
    {
        $share = fn (mixed $weight): ?string => $weight === null
            ? null
            : $this->percentageOf($this->massConverter->toGrams((string) $weight, $unit), $basisQuantityGrams);
        $stage = fn (array $values): array => [
            'add_percentage' => $share($values['add_weight'] ?? null),
            'mass_percentage' => $share($values['mass'] ?? $values['weight'] ?? null),
            'anhydrous_percentage' => (string) $values['anhydrous_percentage'],
            'target_percentage' => isset($values['target_percentage']) ? (string) $values['target_percentage'] : null,
        ];
        $neutralizer = is_array($process['neutralizer'] ?? null) ? $process['neutralizer'] : null;

        return [
            'anhydrous_soap_percentage' => $share($process['anhydrous_soap_weight']),
            'final_percentage' => $share($process['final_weight']),
            'final_anhydrous_percentage' => (string) $process['final_anhydrous_percentage'],
            'free_koh_percentage' => $share(data_get($process, 'paste_composition.free_koh_weight', '0')),
            'stages' => [
                'paste' => $stage($process['paste']),
                'first_dilution' => $stage($process['stages']['first_dilution']),
                'neutralization' => $stage($process['stages']['neutralization']),
                'final_dilution' => $stage($process['stages']['final_dilution']),
            ],
            'neutralizer' => $neutralizer === null ? null : [
                'type' => $neutralizer['type'],
                'name' => $requirements->firstWhere('phase_key_snapshot', 'neutralization')['subject_name_snapshot'] ?? '',
                'solution_percentage' => (string) $neutralizer['solution_percentage'],
                'percentage' => $share($neutralizer['weight']),
                'solution_percentage_of_basis' => $share($neutralizer['solution_weight']),
                'recommended_percentage' => $share($neutralizer['recommended_weight']),
            ],
            'warnings' => array_values($process['warnings'] ?? []),
        ];
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
                'production' => __('production_bench.production.validation.basis_input_positive'),
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
