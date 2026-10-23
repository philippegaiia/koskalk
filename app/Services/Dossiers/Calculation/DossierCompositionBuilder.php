<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Exceptions\DossierCalculationException;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\MassConverter;
use App\Services\RecipeWorkbenchVersionDataService;
use App\Services\SoapCalculationService;
use App\SoapSap;
use App\Support\DecimalMath;
use Illuminate\Support\Arr;

/**
 * Both compositions of a saved bar-soap formula with the ingredient-tracing ledger (spec §4.1), in grams before
 * normalisation, from the saved weights. The final composition is a theoretical estimate unless a measured cure loss is
 * given. Only the pilot's supported chemistry runs (decision 1).
 */
class DossierCompositionBuilder
{
    private const REACTION_PHASE = 'saponified_oils';

    private const LYE_LIQUID_PHASE = 'lye_water';

    private const REACTION_MASS_PRECISION = 12;

    /**
     * Triglyceride lipids: the only materials whose reaction the shared stoichiometry describes.
     */
    private const TRIGLYCERIDE_SUBCATEGORIES = [
        IngredientSubcategory::VegetableOils,
        IngredientSubcategory::Butters,
        IngredientSubcategory::AnimalFats,
    ];

    public function __construct(
        private readonly RecipeWorkbenchVersionDataService $versionData,
        private readonly SoapCalculationService $soapCalculation,
        private readonly MassConverter $massConverter,
        private readonly IngredientTracingVerifier $tracingVerifier,
    ) {}

    /**
     * @return array{
     *     basis: array{unit: string, oil_weight_g: string, initial_mass_g: string, lye_water_g: string, cured_mass_g: string,
     *                  residual_water_g: string, residual_source: string, residual_water_percent: string|null, measured_cure_loss_percent: string|null},
     *     initial: list<array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, phase: string, grams: string, percent: string}>,
     *     final: list<array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, sources: list<string>, grams: string, percent: string}>,
     *     reactions: list<array<string, int|string>>,
     *     contributions: list<array{from: string, to: string, grams: string}>,
     *     losses: list<array{from: string, kind: string, grams: string}>,
     *     tracing: array{passes: bool, tolerance_g: string, gaps: list<array{type: string, key: string, difference: string}>}
     * }
     */
    public function build(RecipeVersion $version, ?string $measuredCureLossPercent = null, ?string $residualWaterPercent = null): array
    {
        $recipe = Recipe::withoutGlobalScopes()->with('productFamily')->findOrFail($version->recipe_id);
        $this->assertSupportedFamily($recipe, $version);
        $snapshot = $this->versionData->versionSnapshot($recipe, $version->id);
        $calculation = $snapshot['calculation'] ?? null;

        if (! is_array($calculation) || ! is_array($calculation['lye'] ?? null)) {
            throw new DossierCalculationException('calculation_unavailable');
        }

        if (is_array($calculation['liquid_process'] ?? null)) {
            throw new DossierCalculationException('liquid_soap_unsupported');
        }

        $lye = $calculation['lye'];
        $lyeReason = $this->unsupportedLyeReason($lye);

        if ($lyeReason !== null) {
            throw new DossierCalculationException($lyeReason);
        }

        $unit = (string) data_get($snapshot, 'draft.oilUnit', 'g');
        $oilWeight = $this->grams(data_get($snapshot, 'draft.oilWeight', 0), $unit);
        $settings = $this->reactionSettings($lye);
        $initial = [];
        $final = [];
        $contributions = [];
        $reactions = [];

        foreach (Arr::wrap(data_get($snapshot, 'draft.phaseItems', [])) as $phase => $items) {
            foreach (array_values(Arr::wrap($items)) as $index => $item) {
                $grams = $this->rowGrams($item, $oilWeight, $unit);

                if (! DecimalMath::isPositive($grams)) {
                    continue;
                }

                if ($phase === self::LYE_LIQUID_PHASE) {
                    throw new DossierCalculationException('lye_substitute_unsupported', ['ingredient' => (string) ($item['name'] ?? '')]);
                }

                $key = "item:{$phase}:{$index}";
                $initial[] = $this->initialRow($key, 'ingredient', (int) $item['ingredient_id'], (string) $item['name'], $item['inci_name'] ?? null, (string) $phase, $grams);

                if ($phase === self::REACTION_PHASE) {
                    $this->assertTriglyceride($item);
                    $reaction = $this->react($key, $item, $grams, $settings);
                    $reactions[] = $reaction['summary'];
                    $final = $this->mergeFinal($final, $reaction['final']);
                    $contributions = [...$contributions, ...$reaction['contributions']];

                    continue;
                }

                $finalKey = 'carried:ingredient:'.$item['ingredient_id'];
                $final = $this->mergeFinal($final, [
                    $this->finalRow($finalKey, 'carried', (int) $item['ingredient_id'], (string) $item['name'], $item['inci_name'] ?? null, [(string) $item['name']], $grams),
                ]);
                $contributions[] = ['from' => $key, 'to' => $finalKey, 'grams' => $grams];
            }
        }

        $initial = $this->withAlkali($lye, $unit, $initial);
        $water = $this->grams(data_get($lye, 'water.weight', 0), $unit);
        $waterInci = (string) config('dossiers.final_material_inci.residual_water');

        if (DecimalMath::isPositive($water)) {
            $initial[] = $this->initialRow('water', 'water', null, 'Eau', $waterInci, 'lye', $water);
        }

        $initialMass = DecimalMath::add(...array_column($initial, 'grams'));
        [$curedMass, $residual, $residualSource, $residualPercent] = $this->curedMass($snapshot, $initialMass, $water, $measuredCureLossPercent, $residualWaterPercent);
        $losses = [];

        if (DecimalMath::isPositive($water)) {
            $losses[] = ['from' => 'water', 'kind' => 'evaporated', 'grams' => DecimalMath::sub($water, $residual)];

            if (DecimalMath::isPositive($residual)) {
                $final = $this->mergeFinal($final, [$this->finalRow('residual_water', 'residual_water', null, 'Eau résiduelle', $waterInci, ['Eau'], $residual)]);
                $contributions[] = ['from' => 'water', 'to' => 'residual_water', 'grams' => $residual];
            }
        }

        $initial = $this->withPercent($initial, $initialMass);
        $final = $this->withPercent(
            collect($final)->sort(fn (array $left, array $right): int => DecimalMath::cmp($right['grams'], $left['grams']))->values()->all(),
            DecimalMath::add(...array_column($final, 'grams')),
        );

        return [
            'basis' => [
                'unit' => $unit,
                'oil_weight_g' => $oilWeight,
                'initial_mass_g' => $initialMass,
                'lye_water_g' => $water,
                'cured_mass_g' => $curedMass,
                'residual_water_g' => $residual,
                'residual_source' => $residualSource,
                'residual_water_percent' => $residualPercent,
                'measured_cure_loss_percent' => $measuredCureLossPercent === null ? null : DecimalMath::of($measuredCureLossPercent),
            ],
            'initial' => $initial,
            'final' => $final,
            'reactions' => $reactions,
            'contributions' => $contributions,
            'losses' => $losses,
            'tracing' => $this->tracingVerifier->verify($initial, $contributions, $losses, $final),
        ];
    }

    /**
     * The reason the pilot cannot assess these lye settings, or null (decision 1).
     *
     * @param  array<string, mixed>  $lye
     */
    public function unsupportedLyeReason(array $lye): ?string
    {
        return match (true) {
            DecimalMath::cmp(DecimalMath::of($lye['superfat_percentage'] ?? 0), '0') < 0 => 'negative_superfat',
            DecimalMath::isPositive(DecimalMath::of(data_get($lye, 'excess.naoh_weight', 0))),
            DecimalMath::isPositive(DecimalMath::of(data_get($lye, 'excess.koh_weight', 0))) => 'excess_alkali',
            DecimalMath::cmp(DecimalMath::of(data_get($lye, 'selected.koh_to_weigh', 0)), DecimalMath::of(data_get($lye, 'selected.koh_weight', 0))) > 0 => 'koh_non_active_share_unsupported',
            default => null,
        };
    }

    private function assertSupportedFamily(Recipe $recipe, RecipeVersion $version): void
    {
        if ($version->is_current) {
            throw new DossierCalculationException('working_copy');
        }

        $family = $recipe->productFamily;

        if ($family === null || $family->calculation_basis === 'total_formula') {
            throw new DossierCalculationException('unsupported_family');
        }

        if ($family->usesLiquidSoapPasteBench()) {
            throw new DossierCalculationException('liquid_soap_unsupported');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function assertTriglyceride(array $item): void
    {
        $ingredient = Ingredient::withoutGlobalScopes()->find($item['ingredient_id'] ?? null);

        if (! $ingredient instanceof Ingredient
            || $ingredient->category !== IngredientCategory::Lipids
            || ! in_array($ingredient->subcategory, self::TRIGLYCERIDE_SUBCATEGORIES, true)) {
            throw new DossierCalculationException('unsupported_reacting_material', ['ingredient' => (string) ($item['name'] ?? '')]);
        }
    }

    /**
     * A row's mass as the workbench preview reads it: the saved weight when positive, otherwise oil weight × percentage.
     *
     * @param  array<string, mixed>  $item
     */
    private function rowGrams(array $item, string $oilWeight, string $unit): string
    {
        $weight = $item['weight'] ?? null;

        if (is_numeric($weight) && (float) $weight > 0) {
            return $this->grams($weight, $unit);
        }

        return DecimalMath::div(DecimalMath::mul($oilWeight, DecimalMath::of($item['percentage'] ?? 0)), '100');
    }

    /**
     * The formula's own reaction settings, as the label's soap-salt weights use them (InciGenerationService), at
     * 12 gram decimals.
     *
     * @param  array<string, mixed>  $lye
     * @return array{superfat: float, mass_precision: int, lye_type: string, dual_lye_koh_percentage: float, koh_purity_percentage: float}
     */
    private function reactionSettings(array $lye): array
    {
        return [
            'superfat' => (float) ($lye['superfat_percentage'] ?? 0),
            'mass_precision' => self::REACTION_MASS_PRECISION,
            'lye_type' => (string) data_get($lye, 'selected.type', 'naoh'),
            'dual_lye_koh_percentage' => (float) data_get($lye, 'selected.dual_lye_koh_percentage', 50),
            'koh_purity_percentage' => (float) data_get($lye, 'selected.koh_purity_percentage', 100),
        ];
    }

    /**
     * One triglyceride's reaction (decision 4): oil → glycerin (backbone) and soap salt (salt − its alkali), alkali →
     * soap salt, the rest of the oil stays unsaponified.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, float|int|string>  $settings
     * @return array{summary: array<string, int|string>, final: list<array<string, mixed>>, contributions: list<array{from: string, to: string, grams: string}>}
     */
    private function react(string $key, array $item, string $grams, array $settings): array
    {
        $sap = $item['koh_sap_value'] ?? null;
        $name = (string) ($item['name'] ?? '');

        if (! is_numeric($sap) || (float) $sap <= 0) {
            throw new DossierCalculationException('missing_sap', ['ingredient' => $name]);
        }

        $products = $this->soapCalculation->calculateOilReactionProducts((float) $grams, (float) $sap, $settings);
        $saponified = DecimalMath::of($products['saponified_weight']);
        $glycerin = DecimalMath::of($products['glycerine_weight']);
        $unsaponified = DecimalMath::sub($grams, $saponified);
        $salts = [
            'naoh' => [DecimalMath::of($products['naoh_soap_salt_weight']), DecimalMath::of($products['selected_naoh_weight']), $item['soap_inci_naoh_name'] ?? null],
            'koh' => [DecimalMath::of($products['koh_soap_salt_weight']), DecimalMath::of($products['selected_koh_weight']), $item['soap_inci_koh_name'] ?? null],
        ];
        $final = [];
        $contributions = [];

        foreach ($salts as $alkaliKey => [$salt, $alkali, $saltInci]) {
            if (! DecimalMath::isPositive($salt)) {
                continue;
            }

            $saltName = filled($saltInci) ? mb_strtoupper(trim((string) $saltInci)) : null;
            $saltKey = $saltName === null ? "soap_salt:{$alkaliKey}:ingredient:{$item['ingredient_id']}" : "soap_salt:{$saltName}";
            $final[] = $this->finalRow($saltKey, 'soap_salt', null, $saltName ?? "Sel de saponification ({$alkaliKey}) — {$name}", $saltName, [$name], $salt);
            $contributions[] = ['from' => $key, 'to' => $saltKey, 'grams' => DecimalMath::sub($salt, $alkali)];
            $contributions[] = ['from' => $alkaliKey, 'to' => $saltKey, 'grams' => $alkali];
        }

        $glycerinInci = (string) config('dossiers.final_material_inci.glycerin');
        $final[] = $this->finalRow('glycerin', 'glycerin', null, $glycerinInci, $glycerinInci, [$name], $glycerin);
        $contributions[] = ['from' => $key, 'to' => 'glycerin', 'grams' => $glycerin];

        if (DecimalMath::isPositive($unsaponified)) {
            $unsaponifiedKey = 'unsaponified:ingredient:'.$item['ingredient_id'];
            $final[] = $this->finalRow($unsaponifiedKey, 'unsaponified', (int) $item['ingredient_id'], $name, $item['inci_name'] ?? null, [$name], $unsaponified);
            $contributions[] = ['from' => $key, 'to' => $unsaponifiedKey, 'grams' => $unsaponified];
        }

        return [
            'summary' => [
                'initial_key' => $key,
                'ingredient_id' => (int) $item['ingredient_id'],
                'oil_g' => $grams,
                'saponified_g' => $saponified,
                'unsaponified_g' => $unsaponified,
                'naoh_g' => $salts['naoh'][1],
                'koh_g' => $salts['koh'][1],
                'glycerin_g' => $glycerin,
                'naoh_salt_g' => $salts['naoh'][0],
                'koh_salt_g' => $salts['koh'][0],
            ],
            'final' => $final,
            'contributions' => $contributions,
        ];
    }

    /**
     * The weighed alkali (the formula's values) as initial rows. KOH below 100 % purity never reaches this point.
     *
     * @param  array<string, mixed>  $lye
     * @param  list<array<string, mixed>>  $initial
     * @return list<array<string, mixed>>
     */
    private function withAlkali(array $lye, string $unit, array $initial): array
    {
        $naoh = $this->grams(data_get($lye, 'selected.naoh_weight', 0), $unit);
        $koh = $this->grams(data_get($lye, 'selected.koh_to_weigh', 0), $unit);

        if (DecimalMath::isPositive($naoh)) {
            $initial[] = $this->initialRow('naoh', 'alkali', null, 'Hydroxyde de sodium', 'SODIUM HYDROXIDE', 'lye', $naoh);
        }

        if (DecimalMath::isPositive($koh)) {
            $initial[] = $this->initialRow('koh', 'alkali', null, 'Hydroxyde de potassium', 'POTASSIUM HYDROXIDE', 'lye', $koh);
        }

        return $initial;
    }

    /**
     * Cured mass and residual water: a measured cure loss is taken from the water only; otherwise every non-water mass
     * is kept and the residual percentage (formula or justified override) sets the cured mass.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string, 1: string, 2: string, 3: string|null}
     */
    private function curedMass(array $snapshot, string $initialMass, string $water, ?string $measuredLossPercent, ?string $residualOverride): array
    {
        if (! DecimalMath::isPositive($water)) {
            return [$initialMass, '0', 'no_water', null];
        }

        if ($measuredLossPercent !== null) {
            $lost = DecimalMath::div(DecimalMath::mul($initialMass, DecimalMath::of($measuredLossPercent)), '100');

            if (DecimalMath::cmp($lost, '0') < 0 || DecimalMath::cmp($lost, $water) > 0) {
                throw new DossierCalculationException('measured_loss_out_of_range');
            }

            return [DecimalMath::sub($initialMass, $lost), DecimalMath::sub($water, $lost), 'measured', null];
        }

        $percent = $residualOverride !== null
            ? DecimalMath::of($residualOverride)
            : DecimalMath::of(SoapSap::curedWaterPercentage(data_get($snapshot, 'draft.curedWaterPercentage')));
        $retained = DecimalMath::sub($initialMass, $water);
        $cured = DecimalMath::div($retained, DecimalMath::sub('1', DecimalMath::div($percent, '100')));
        $residual = DecimalMath::sub($cured, $retained);

        if (DecimalMath::cmp($residual, $water) > 0) {
            throw new DossierCalculationException('residual_exceeds_water');
        }

        return [$cured, $residual, $residualOverride !== null ? 'override' : 'formula_estimate', $percent];
    }

    /**
     * @param  array<string, array<string, mixed>>  $final
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function mergeFinal(array $final, array $rows): array
    {
        foreach ($rows as $row) {
            $existing = $final[$row['key']] ?? null;
            $final[$row['key']] = $existing === null ? $row : [
                ...$existing,
                'grams' => DecimalMath::add($existing['grams'], $row['grams']),
                'sources' => array_values(array_unique([...$existing['sources'], ...$row['sources']])),
            ];
        }

        return $final;
    }

    /**
     * @return array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, phase: string, grams: string}
     */
    private function initialRow(string $key, string $kind, ?int $ingredientId, string $name, ?string $inci, string $phase, string $grams): array
    {
        return ['key' => $key, 'kind' => $kind, 'ingredient_id' => $ingredientId, 'name' => $name, 'inci_name' => $inci, 'phase' => $phase, 'grams' => $grams];
    }

    /**
     * @param  list<string>  $sources
     * @return array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, sources: list<string>, grams: string}
     */
    private function finalRow(string $key, string $kind, ?int $ingredientId, string $name, ?string $inci, array $sources, string $grams): array
    {
        return ['key' => $key, 'kind' => $kind, 'ingredient_id' => $ingredientId, 'name' => $name, 'inci_name' => $inci, 'sources' => $sources, 'grams' => $grams];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withPercent(array $rows, string $total): array
    {
        return array_values(array_map(fn (array $row): array => [...$row, 'percent' => DecimalMath::percentOf($row['grams'], $total)], $rows));
    }

    private function grams(mixed $quantity, string $unit): string
    {
        return DecimalMath::of($this->massConverter->toGrams(DecimalMath::of($quantity), $unit));
    }
}
