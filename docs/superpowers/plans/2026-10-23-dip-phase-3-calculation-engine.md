# DIP Phase 3 — Calculation Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compute, for a saved bar-soap formula, everything Part A of the DIP needs: both compositions with a mass balance and an ingredient-tracing ledger, allergen/impurity/restricted-substance overlays, exposure (SED), systemic NOAEL, MoS and the other indicators, QRA2 with the preset's adjustment factor, EU annex checks, suggested routes and row states — at full precision, with every input recorded, and with the blockers and warnings phase 4 will show and snapshot.

**Architecture:** Pure calculators (`ExposureCalculator`, `Qra2Calculator`, `IngredientTracingVerifier`, `RowStateResolver`) do the arithmetic and the state rules on decimal strings and are tested against hand-calculated values. Builders read the data: `DossierCompositionBuilder` turns a saved formula's workbench snapshot into the initial composition, the final (cured) composition and the ledger, from the saved weights, reusing `SoapCalculationService::calculateOilReactionProducts` for triglyceride oils only; `ConstituentOverlayBuilder` sums the supplier worst cases from `SupplierConstituentResolver`; `AssessedMaterialResolver` separates each material's legal identity from the profile it is assessed with; `AssessmentParameterResolver` and `LegalEntryChecker` load bounded parameters and annex entries. `DossierAssessmentEngine` sums every contribution of the same substance into one row, then evaluates it, and returns one array. **Read-only:** no tables, no migrations, no screens. Route confirmations and dossier overrides are engine *inputs*; phase 4 stores them on dossier versions.

**Tech Stack:** Laravel 13, PHP 8.5 (bcmath), Pest 4. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md` §4 (engine), §5 (blockers), §12 (tests). Phase 3 "done when": independent fixtures, boundary and failure cases pass.

---

## Before you start

- Work on branch `codex/dip-pilot`. `export LANG=en_US.UTF-8` before running tests (see `.ai/rules/tests.md`).
- Read `.ai/rules/index.md` and the rule files for the paths you touch: `app.md`, `services.md`, `models-services.md`, `tests.md`. Two rules shape this plan:
  - *Compute money and mass with bcmath on decimal strings.* All engine arithmetic uses `App\Support\DecimalMath`. `SoapCalculationService` is float-based; its per-oil results are converted at that boundary with `DecimalMath::of()` (the same fixed-decimal conversion as `LyeLiquidAllocationService::decimal()`), at `mass_precision` 12.
  - *Oils added after the reaction stay as added at non-negative superfat.* Only the `saponified_oils` phase reacts; every other phase is carried at unchanged mass.
- Run Pint after PHP changes (`vendor/bin/pint --dirty --format agent`). Do not touch the owner's local database.

## Decisions taken while planning

Revised on 2026-10-23 after Codex's plan review (six P1 and three P2 findings, all verified against the code and accepted; see "Review changes" at the end).

1. **Pilot scope: bar soap with supported chemistry only.** The calculation **does not run**, with a named reason, for:
   - a cosmetic family (`unsupported_family`), a liquid-soap family (`liquid_soap_unsupported`) or the working copy (`working_copy`);
   - a negative superfat (`negative_superfat`) or any excess alkali (`excess_alkali`);
   - a reacting material that is not a triglyceride oil, butter or animal fat (`unsupported_reacting_material`). The shared reaction helper uses triglyceride stoichiometry, including glycerin release; a fatty acid such as stearic acid or a liquid wax such as jojoba has no glycerol backbone and needs another reaction model. Allowed: category `lipids` with subcategory `vegetable_oils`, `butters` or `animal_fats`;
   - any lye-water substitute (`lye_substitute_unsupported`). The workbench accepts substitutes other than hydrosols (glycerin, for example), and without a known water fraction the evaporated mass cannot be stated;
   - KOH below 100 % purity (`koh_non_active_share_unsupported`). The non-active share of technical KOH has no evidence-backed composition, so it can be neither assessed nor assumed to evaporate.
   These are spec blockers ("a calculation that cannot run"). Leave-on and liquid products, other reaction models, lye substitutes and KOH 90 % come after the pilot.
2. **Quantities come from the saved weights**, as the workbench preview does: a row's saved weight (in the formula's unit) when positive, otherwise oil weight × percentage. In weight-editing mode, weights and percentages are rounded independently (verified: 1.2345 g is saved with 0.0123 %, and 0.001 g with 0.0000 %), so percentages alone would change or drop ingredients.
3. **Final composition model** (verified on a probe formula: 1000 g olive oil, 3 % lavender → cured 1300.384 g, sodium olivate 979.6072 g, glycerin 97.7346 g):
   - each oil → its soap salt(s), glycerin and the unsaponified share, from `calculateOilReactionProducts` with the formula's own superfat, lye type and dual-lye share;
   - NaOH and KOH (100 %) → the soap salts;
   - the lye water keeps the residual share and loses the rest by evaporation; nothing else evaporates;
   - cured mass = non-water mass ÷ (1 − residual %), residual % from the formula (`curedWaterPercentage`) or a justified override; with a **measured cure loss** (% of the initial batch) the lost mass is taken from the water only, and nothing else changes (spec §4.1);
   - every other phase is carried at unchanged mass.
4. **Ledger convention.** Contributions in grams before normalisation: oil → glycerin (the glycerol backbone), oil → each soap salt (salt − its reacting alkali), alkali → each soap salt, oil → unsaponified share, water → residual water, water → evaporated (a loss), carried ingredient → itself. The initial NaOH/KOH rows hold the amounts actually weighed (the formula's values); the per-oil reacting alkali is recomputed at 12 decimals, so a difference beyond the tolerance shows as an unallocated gap.
5. **Tolerances are explicit and bounded:** `dossiers.mass_balance_tolerance_g` = `0.001` g (inputs carry 4-decimal gram values), never above `dossiers.mass_balance_tolerance_max_g` = `0.01` g; `dossiers.percent_tolerance` = `0.000001`. The verifier refuses a larger tolerance, so the column-J regression (≈ 3.81 g displaced) cannot be made to pass by configuration. The ledger proves conservation of mass; it does not prove the source weights or the chemistry, which decisions 1–3 guard.
6. **Identity and profile are separate.** Each assessed row has:
   - an **identity**: the substance the material *is*, used for annex checks and label rules: soap salt, glycerin, water → the `substance_catalog` row whose `inci_name` matches (case-insensitive); an ingredient → the substance with the ingredient's INCI, when exactly one exists; a constituent → the allergen's linked substance, or the impurity or restricted substance itself;
   - a **profile**: the identity's own current profile; otherwise, as a recorded read-across, the single whole-material reference of the material's coverage (supporting substances that are not tracked constituents); several distinct references → no profile (`ambiguous_reference`).
   A material with no identity (an INCI that is not in the catalogue) is assessed on its own row through its coverage references and gets no annex check (warning `no_legal_identity`). A soap salt, glycerin or water row without an identity blocks submission.
7. **One assessed row per substance, summed across every source.** Contributions of the same identity are added before any indicator or limit is evaluated: a neat ingredient and the same substance declared in another ingredient, reaction glycerin and added glycerin, an allergen from several oils. Each row keeps the list of its contributions. Contributions are grouped by the recipe ingredient they come from: that ingredient's mass in the composition and each kind of supplier declaration (allergen declaration, RCE, a blend's own declaration) describe the same physical amount, so only the largest counts; different ingredients are added. A neat material or a blend is therefore never counted twice. (Changed after Codex's code review: the first version only skipped a neat material's declaration of itself.) A blend with components is assessed through its components (concentration × `percentage_in_parent`); any share its linked components do not account for stays as an unidentified contribution and blocks submission (`blend_component_unresolved`).
8. **Profiles: the current version is used while the dossier is live** (spec D10). A coverage that relied on a superseded version is a warning (`coverage_relies_on_superseded_profiles`); phase 4's out-of-date detection covers the frozen case.
9. **Overlays** use `SupplierConstituentResolver` per ingredient (supplier worst case first, flagged platform fallback). Allergen mass in a saponified oil is carried on that oil's initial mass (conservative). A blend with components and no validated supplier of its own is read through its components. `SupplierConstituentResolver` gains `restrictedSubstances()` (RCE values first, the platform `ingredient_substance_entries` with a concentration as flagged fallback).
10. **Indicators are all computed and shown; none concludes a row on its own.** MoS passes at ≥ threshold, C at ≤ Cmax, SED at ≤ ADI or TTC, C at ≤ QRA2 Cmax. NOAELsys is computed automatically **only for an oral NOAEL** (NOAEL × oral bioavailability). A dermal NOAEL is not converted (`dermal_study_absorption_unknown`): reusing the product DAp would cancel it out of the MoS, and the study's own absorption is not recorded; the assessor confirms another route or the policy is agreed with them (spec open question added). An inhalation NOAEL is not converted (`route_not_convertible`). Impurities are compared in % and reported in ppm and µg/kg bw/day; ADI and TTC are the SED limits (the profile has no separate SEDmax).
11. **Physical bounds are enforced before any calculation:** amount per use > 0 g, uses per day > 0, 0 < retention factor ≤ 1, body weight > 0 kg, 0 ≤ DAp ≤ 100 %, 0 ≤ oral bioavailability ≤ 100 % (zero absorption is legitimate), 0 ≤ residual water ≤ 30 % (the workbench limit), 0 ≤ measured cure loss < 100 %. An override outside its bounds is refused when the options are built (`InvalidArgumentException`); a stored policy or preset value outside them makes the calculation not run (`invalid_parameter`).
12. **Row states** follow spec §4.2: binding breach → `non_conforme`; an annex condition recorded as **not met** → `non_conforme`; no confirmation → `route_suggere` when an indicator is available, else `a_justifier`; a confirmed route → `conforme` only when its indicator passes, the profile's hazard endpoints are addressed, every annex condition that cannot be checked automatically is recorded as **met**, and — for an allergen — QRA2 is available and passes; otherwise `a_justifier` with the reason. A confirmation without a justification counts as none.
13. **Annex checks** use reviewed `regulatory_legal_entries` of the `eu` regime in force on the evaluation date, on the row's identity at its summed concentration. Annex II present → banned. A maximum exceeded → breach (exactly at the maximum passes). `rinse_off_only` / `leave_on_only` follow the preset's `is_rinse_off`. `see_conditions`, `other_limitations` or `product_type_body_parts` → `needs_verification`. Transition dates are shown, not evaluated, in the pilot. Draft entries linked to a present substance are a warning, never used.
14. **Label declaration of allergens** uses the active `eu` `regulatory_regime_allergens` row (rinse-off or leave-on threshold by preset, with its operator) against the summed **final** concentration.
15. **Submission blockers computed now** (phase 4 enforces them): calculation cannot run; tracing gap; an initial recipe ingredient or a final material whose coverage is not `sufficient`; an unidentified soap salt, glycerin or water. Initial NaOH, KOH and water are transformed materials: they are assessed through what they become (soap salts, residual water) and get no finished-product row of their own. Task 13 states this in spec §5.
16. **Peroxide value unit (open since phase 2b):** not used by the engine; settled with the 2.A.2 raw-material table in phase 4.
17. **Output is plain arrays with documented shapes**, enum values as strings, decimals as full-precision strings (rounded only for display with `DecimalMath::round()`), so phase 4 can snapshot it as JSON.

## File structure

| Area | Files |
| --- | --- |
| Decimal maths, config | create `app/Support/DecimalMath.php`; modify `config/dossiers.php`; test `tests/Feature/Dossiers/Calculation/DecimalMathTest.php` |
| Exposure | create `app/Data/DossierExposureParameters.php`, `app/Services/Dossiers/Calculation/ExposureCalculator.php`, `tests/Support/DossierCalculationFixtures.php` (extended in Tasks 5, 9, 11, 12); test `tests/Feature/Dossiers/Calculation/ExposureCalculatorTest.php` |
| QRA2 | create `app/Services/Dossiers/Calculation/Qra2Calculator.php`; test `tests/Feature/Dossiers/Calculation/Qra2CalculatorTest.php` |
| Tracing | create `app/Services/Dossiers/Calculation/IngredientTracingVerifier.php`; test `tests/Feature/Dossiers/Calculation/IngredientTracingVerifierTest.php` |
| Compositions | create `app/Exceptions/DossierCalculationException.php`, `app/Services/Dossiers/Calculation/DossierCompositionBuilder.php`; test `tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php` |
| Overlays | modify `app/Services/Dossiers/SupplierConstituentResolver.php` (add `restrictedSubstances()`); create `app/Services/Dossiers/Calculation/ConstituentOverlayBuilder.php`; test `tests/Feature/Dossiers/Calculation/ConstituentOverlayBuilderTest.php` (covers the new resolver method too) |
| Parameters | create `app/Enums/AssessmentRoute.php`, `app/Data/DossierAssessmentOptions.php`, `app/Services/Dossiers/Calculation/AssessmentParameterResolver.php`; test `tests/Feature/Dossiers/Calculation/AssessmentParameterResolverTest.php` |
| Annex checks | create `app/Services/Dossiers/Calculation/LegalEntryChecker.php`; test `tests/Feature/Dossiers/Calculation/LegalEntryCheckerTest.php` |
| Indicators | create `app/Services/Dossiers/Calculation/RowIndicatorEvaluator.php`; test `tests/Feature/Dossiers/Calculation/RowIndicatorEvaluatorTest.php` |
| Routes and states | create `app/Enums/DossierRowState.php`, `app/Services/Dossiers/Calculation/RowStateResolver.php`; test `tests/Feature/Dossiers/Calculation/RowStateResolverTest.php` |
| Identity and profile | create `app/Services/Dossiers/Calculation/AssessedMaterialResolver.php`; test `tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php` |
| Engine | create `app/Services/Dossiers/Calculation/DossierAssessmentEngine.php`; test `tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php` |

## Reference values (computed by hand, not by the implementation)

Exposure inputs from DIP.GA.007: A = 1 g × 10/day, R = 0.01, bw = 60 kg. Codex reproduced every value independently on 2026-10-23.

| Case | Inputs | Expected |
| --- | --- | --- |
| Olive oil SED | C 22.694 %, DAp 100 % | 0.378233 (6 dp) |
| Limonene | C 0.09660 %, DAp 100 %, NOAELsys 75 | SED 0.001610000, MoS 46583.85 |
| Linalool | C 0.03262 %, DAp 14.4 %, NOAELsys 58.5 | SED 0.000078288, MoS 747240.96 (not the printed 731,300) |
| Glycerin | C 7.944 %, DAp 7.34 %, NOAELsys 590 | SED 0.009718160, MoS 60711.08 |
| QRA2 limonene | NESIL 10000, SAF 300, CEL 0.2 | 16.666667 % without factor, 8.333333 % with 0.5 |
| QRA2 Table 7 | NESIL 3500, SAF 300, CEL 0.2, factor 0.5 | 5.833333 % → 2.916667 % |
| Boundary | C 0.6 %, DAp 100 % | SED exactly 0.01 |
| Lead impurity | 2 ppm, DAp 100 % | 0.003333 µg/kg bw/day |

Probe soap: 1000 g olive oil (KOH SAP 0.188 → NaOH SAP 0.134044), NaOH, superfat 5 %, water 38 % of oils, residual water 11 %.

| Value | Olive only | With 30 g lavender oil |
| --- | --- | --- |
| NaOH weighed | 127.3418 g | 127.3418 g |
| Initial mass | 1507.3418 g | 1537.3418 g |
| Cured mass | 1266.676180 g | 1300.384045 g |
| Sodium olivate | 979.607174 g = 77.336828 % | 75.332143 % |
| Glycerin | 97.734626 g = 7.715834 % | 7.515828 % |
| Unsaponified olive oil | 50 g = 3.947339 % | 3.845018 % |
| Residual water | 139.334380 g (11 %) | 143.042245 g (11 %) |
| Water evaporated | 240.665620 g | 236.957755 g |
| Lavender oil | — | 2.307011 % |
| Limonene at 40 % in lavender | — | 12 g: 0.922804 % final, 0.780568 % initial; SED 0.015380072; MoS (NOAELsys 75) 4876.44 |
| Glycerin row (DAp 7.34 %, NOAELsys 590) | — | MoS 64169.75 |

Variants of the olive-only soap: measured cure loss 15 % → 226.101270 g lost, residual water 153.898730 g, cured mass 1281.240530 g (water 12.011697 %). Residual-water override 9 % → cured mass 1238.837143 g, residual 111.495343 g.

Summing across sources (decision 7):

| Formula | Values |
| --- | --- |
| Lavender soap (olive soap + 30 g lavender) + 20 g glycerin in the additives | cured mass 1322.855955 g; glycerin 8.900034 % in total = 7.388153 % from the reaction + 1.511880 % added |
| Lavender soap (40 % limonene) + 6 g neat limonene in the fragrance | cured mass 1307.125618 g; limonene 1.377067 % in total = 0.918045 % from lavender + 0.459022 % neat |

---

### Task 1: Decimal maths and tolerances

**Files:**
- Create: `app/Support/DecimalMath.php`
- Modify: `config/dossiers.php`
- Test: `tests/Feature/Dossiers/Calculation/DecimalMathTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Support\DecimalMath;

it('reads database decimals, integers and calculator floats as canonical decimal strings', function (): void {
    expect(DecimalMath::of('22.694000'))->toBe('22.694000000000000000')
        ->and(DecimalMath::of(10))->toBe('10.000000000000000000')
        ->and(DecimalMath::of(0.000078288))->toBe('0.000078288000000000')
        ->and(DecimalMath::of(-0.0))->toBe('0.000000000000000000');
});

it('refuses missing, non-numeric and non-finite values', function (mixed $value): void {
    DecimalMath::of($value);
})->with([
    'null' => [null],
    'text' => ['abc'],
    'localised' => ['1,5'],
    'infinite' => [INF],
])->throws(InvalidArgumentException::class);

it('computes at full precision and rounds half away from zero only on request', function (): void {
    $third = DecimalMath::div('1', '3');

    expect(DecimalMath::round($third, 6))->toBe('0.333333')
        ->and(DecimalMath::round('2.0000005', 6))->toBe('2.000001')
        ->and(DecimalMath::round('-2.0000005', 6))->toBe('-2.000001')
        ->and(DecimalMath::round('0.0000004', 6))->toBe('0.000000')
        ->and(DecimalMath::round(DecimalMath::mul('3', $third), 9))->toBe('1.000000000')
        ->and(DecimalMath::round(DecimalMath::percentOf('12', '1300.384044943820224719'), 6))->toBe('0.922804');
});

it('compares within an explicit tolerance and refuses division by zero', function (): void {
    expect(DecimalMath::withinTolerance('100.0005', '100', '0.001'))->toBeTrue()
        ->and(DecimalMath::withinTolerance('100.0011', '100', '0.001'))->toBeFalse()
        ->and(DecimalMath::abs('-3.5'))->toBe('3.5')
        ->and(fn () => DecimalMath::div('1', '0'))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DecimalMathTest.php`
Expected: FAIL, `Class "App\Support\DecimalMath" not found`.

- [ ] **Step 3: Implement**

`app/Support/DecimalMath.php`:

```php
<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Full-precision decimal arithmetic for the DIP calculations: bcmath on canonical decimal strings, never floats.
 * Values are rounded only for display, through round().
 */
final class DecimalMath
{
    public const SCALE = 18;

    /**
     * A canonical decimal string from a database decimal, an integer, or a float produced by a shared calculator.
     * Locale formats are refused: these are machine values, not user input.
     */
    public static function of(mixed $value): string
    {
        if ($value === null) {
            throw new InvalidArgumentException('A calculation value is missing.');
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('A calculation value must be finite.');
            }

            $value = rtrim(rtrim(sprintf('%.18F', $value), '0'), '.');
        }

        $string = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $string) !== 1) {
            throw new InvalidArgumentException("[{$string}] is not a decimal number.");
        }

        return bcadd($string, '0', self::SCALE);
    }

    public static function add(string ...$values): string
    {
        return array_reduce($values, fn (string $sum, string $value): string => bcadd($sum, $value, self::SCALE), '0');
    }

    public static function sub(string $minuend, string $subtrahend): string
    {
        return bcsub($minuend, $subtrahend, self::SCALE);
    }

    public static function mul(string ...$factors): string
    {
        return array_reduce($factors, fn (string $product, string $factor): string => bcmul($product, $factor, self::SCALE), '1');
    }

    public static function div(string $dividend, string $divisor): string
    {
        if (self::isZero($divisor)) {
            throw new InvalidArgumentException('Division by zero in a DIP calculation.');
        }

        return bcdiv($dividend, $divisor, self::SCALE);
    }

    public static function percentOf(string $part, string $whole): string
    {
        return self::div(self::mul($part, '100'), $whole);
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp($left, $right, self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isPositive(string $value): bool
    {
        return self::cmp($value, '0') > 0;
    }

    public static function abs(string $value): string
    {
        return self::cmp($value, '0') < 0 ? ltrim($value, '-') : $value;
    }

    public static function withinTolerance(string $left, string $right, string $tolerance): bool
    {
        return self::cmp(self::abs(self::sub($left, $right)), $tolerance) <= 0;
    }

    /**
     * Display rounding, half away from zero. Never feed a rounded value back into a calculation.
     */
    public static function round(string $value, int $places): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($places + 1), 0), $places + 1);
        $rounded = self::cmp($value, '0') < 0
            ? bcsub($value, $half, $places)
            : bcadd($value, $half, $places);

        return self::isZero($rounded) ? bcadd('0', '0', $places) : $rounded;
    }
}
```

Append to the array in `config/dossiers.php`, after `evidence_mimes`:

```php

    /*
     * Ingredient tracing (spec §4.1). Formula inputs carry 4-decimal gram values, so differences within this many
     * grams are representation only. A tolerance never absorbs an unexplained difference.
     */
    'mass_balance_tolerance_g' => '0.001',
    // The verifier refuses any tolerance above this bound: a larger one could hide a displaced ingredient.
    'mass_balance_tolerance_max_g' => '0.01',
    'percent_tolerance' => '0.000001',

    // INCI names under which these final soap materials are found in the substance catalogue.
    'final_material_inci' => [
        'glycerin' => 'GLYCERIN',
        'residual_water' => 'AQUA',
    ],
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DecimalMathTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/DecimalMath.php config/dossiers.php tests/Feature/Dossiers/Calculation/DecimalMathTest.php
git commit -m "Add full-precision decimal maths and tracing tolerances for the DIP engine"
```

---

### Task 2: Exposure, systemic NOAEL and margin of safety

**Files:**
- Create: `app/Data/DossierExposureParameters.php`, `app/Services/Dossiers/Calculation/ExposureCalculator.php`, `tests/Support/DossierCalculationFixtures.php`
- Test: `tests/Feature/Dossiers/Calculation/ExposureCalculatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NoaelRoute;
use App\Services\Dossiers\Calculation\ExposureCalculator;
use App\Support\DecimalMath;
use Tests\Support\DossierCalculationFixtures;

it('computes the olive oil exposure dose of the pilot reference', function (): void {
    $sed = app(ExposureCalculator::class)->systemicExposureDose('22.694', '100', DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::round($sed, 6))->toBe('0.378233');
});

it('computes margins of safety at full precision', function (string $concentration, string $dap, string $systemicNoael, string $sed, string $mos): void {
    $calculator = app(ExposureCalculator::class);
    $dose = $calculator->systemicExposureDose($concentration, $dap, DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::round($dose, 9))->toBe($sed)
        ->and(DecimalMath::round($calculator->marginOfSafety($systemicNoael, $dose), 2))->toBe($mos);
})->with([
    'limonene' => ['0.09660', '100', '75', '0.001610000', '46583.85'],
    'linalool, not the early-rounded 731,300' => ['0.03262', '14.4', '58.5', '0.000078288', '747240.96'],
    'glycerin' => ['7.944', '7.34', '590', '0.009718160', '60711.08'],
]);

it('converts only an oral NOAEL automatically', function (): void {
    $calculator = app(ExposureCalculator::class);

    expect(DecimalMath::round($calculator->systemicNoael('150', NoaelRoute::Oral, '50'), 6))->toBe('75.000000')
        ->and($calculator->systemicNoael('100', NoaelRoute::Dermal, '50'))->toBeNull()
        ->and($calculator->systemicNoael('100', NoaelRoute::Inhalation, '50'))->toBeNull();
});

it('gives no margin of safety without exposure', function (): void {
    $calculator = app(ExposureCalculator::class);
    $dose = $calculator->systemicExposureDose('1', '0', DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::isZero($dose))->toBeTrue()
        ->and($calculator->marginOfSafety('75', $dose))->toBeNull();
});

it('reports an impurity dose in micrograms', function (): void {
    $dose = app(ExposureCalculator::class)->impurityExposureDoseMicrograms('2', '100', DossierCalculationFixtures::pilotExposure());

    expect(DecimalMath::round($dose, 6))->toBe('0.003333');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/ExposureCalculatorTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`tests/Support/DossierCalculationFixtures.php` (Task 5 adds the saved formulas, Task 12 the reference data):

```php
<?php

namespace Tests\Support;

use App\Data\DossierExposureParameters;

/**
 * Inputs for the DIP engine tests. The expected values that go with them are in the phase 3 plan, computed by hand.
 */
final class DossierCalculationFixtures
{
    /**
     * DIP.GA.007's stated inputs: A = 1 g × 10 uses/day, R = 0.01, bw = 60 kg.
     */
    public static function pilotExposure(): DossierExposureParameters
    {
        return new DossierExposureParameters('1', '10', '0.01', '60');
    }
}
```

`app/Data/DossierExposureParameters.php`:

```php
<?php

namespace App\Data;

use App\Support\DecimalMath;

/**
 * Exposure inputs of one DIP calculation, as decimal strings: amount per use (g), uses per day, retention factor R and
 * body weight (kg). Taken from the product type's exposure preset and the assessment policy, or a justified override.
 */
final readonly class DossierExposureParameters
{
    public function __construct(
        public string $amountPerUseGrams,
        public string $usesPerDay,
        public string $retentionFactor,
        public string $bodyWeightKg,
    ) {}

    /**
     * A [g/day] = amount per use × frequency.
     */
    public function dailyAmountGrams(): string
    {
        return DecimalMath::mul($this->amountPerUseGrams, $this->usesPerDay);
    }
}
```

`app/Services/Dossiers/Calculation/ExposureCalculator.php`:

```php
<?php

namespace App\Services\Dossiers\Calculation;

use App\Data\DossierExposureParameters;
use App\Enums\NoaelRoute;
use App\Support\DecimalMath;

/**
 * SCCS exposure arithmetic for the DIP (spec §4.2), at full precision.
 */
class ExposureCalculator
{
    /**
     * SED [mg/kg bw/day] = A [g/day] × 1000 × C/100 × DAp/100 × R ÷ bw.
     */
    public function systemicExposureDose(string $concentrationPercent, string $dermalAbsorptionPercent, DossierExposureParameters $exposure): string
    {
        return DecimalMath::div(
            DecimalMath::mul(
                $exposure->dailyAmountGrams(),
                '1000',
                DecimalMath::div($concentrationPercent, '100'),
                DecimalMath::div($dermalAbsorptionPercent, '100'),
                $exposure->retentionFactor,
            ),
            $exposure->bodyWeightKg,
        );
    }

    /**
     * Impurities are expressed in ppm (1 % = 10,000 ppm) and their dose in µg/kg bw/day.
     */
    public function impurityExposureDoseMicrograms(string $ppm, string $dermalAbsorptionPercent, DossierExposureParameters $exposure): string
    {
        return DecimalMath::mul(
            $this->systemicExposureDose(DecimalMath::div($ppm, '10000'), $dermalAbsorptionPercent, $exposure),
            '1000',
        );
    }

    /**
     * NOAELsys = NOAEL × oral bioavailability for an oral study. A dermal NOAEL is not converted (decision 10): the
     * study's own absorption is not recorded, and reusing the product DAp would cancel it out of the MoS. An inhalation
     * NOAEL is not converted either.
     */
    public function systemicNoael(string $noael, NoaelRoute $route, string $oralBioavailabilityPercent): ?string
    {
        return $route === NoaelRoute::Oral
            ? DecimalMath::mul($noael, DecimalMath::div($oralBioavailabilityPercent, '100'))
            : null;
    }

    /**
     * MoS = NOAELsys ÷ SED; none when there is no exposure.
     */
    public function marginOfSafety(string $systemicNoael, string $systemicExposureDose): ?string
    {
        return DecimalMath::isPositive($systemicExposureDose)
            ? DecimalMath::div($systemicNoael, $systemicExposureDose)
            : null;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/ExposureCalculatorTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/DossierExposureParameters.php app/Services/Dossiers/Calculation/ExposureCalculator.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/ExposureCalculatorTest.php
git commit -m "Compute DIP exposure doses, systemic NOAEL and margins of safety"
```

---

### Task 3: QRA2 maximum concentration

**Files:**
- Create: `app/Services/Dossiers/Calculation/Qra2Calculator.php`
- Test: `tests/Feature/Dossiers/Calculation/Qra2CalculatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Qra2Parameter;
use App\Services\Dossiers\Calculation\Qra2Calculator;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies the aggregate exposure adjustment factor', function (string $nesil, ?string $factor, string $withoutFactor, string $cmax): void {
    $result = app(Qra2Calculator::class)->maximumConcentration($nesil, '300', '0.2', $factor);

    expect(DecimalMath::round($result['cmax_without_factor_percent'], 6))->toBe($withoutFactor)
        ->and(DecimalMath::round($result['cmax_percent'], 6))->toBe($cmax)
        ->and($result['factor'])->toBe($factor);
})->with([
    'limonene, bar soap' => ['10000', '0.5', '16.666667', '8.333333'],
    'IFRA guidance Table 7 worked example' => ['3500', '0.5', '5.833333', '2.916667'],
    'recorded deviation: no factor' => ['10000', null, '16.666667', '16.666667'],
]);

it('reads the factor from the preset only when the preset applies it', function (): void {
    $applied = Qra2Parameter::factory()->create();
    $deviation = Qra2Parameter::factory()->create([
        'applies_qra2_factor' => false,
        'factor_deviation_reason' => 'Expert deviation recorded by the assessor.',
    ]);
    $calculator = app(Qra2Calculator::class);

    expect(DecimalMath::round($calculator->forPreset('10000', $applied)['cmax_percent'], 6))->toBe('8.333333')
        ->and(DecimalMath::round($calculator->forPreset('10000', $deviation)['cmax_percent'], 6))->toBe('16.666667');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/Qra2CalculatorTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Dossiers\Calculation;

use App\Models\Qra2Parameter;
use App\Support\DecimalMath;

/**
 * IFRA QRA2 for fragrance allergens (IFRA 51st Amendment guidance, Table 6 p. 28 and worked example Table 7 p. 29).
 * QRA2 addresses the induction of sensitisation only; it does not protect people already sensitised.
 */
class Qra2Calculator
{
    /**
     * AEL [µg/cm²/day] = NESIL ÷ SAF; Cmax % = AEL ÷ (CEL [mg/cm²/day] × 1000) × 100 × aggregate adjustment factor.
     *
     * @return array{ael_ug_cm2: string, cmax_without_factor_percent: string, factor: string|null, cmax_percent: string}
     */
    public function maximumConcentration(string $nesil, string $sensitizationAssessmentFactor, string $consumerExposureLevel, ?string $factor): array
    {
        $ael = DecimalMath::div($nesil, $sensitizationAssessmentFactor);
        $withoutFactor = DecimalMath::mul(DecimalMath::div($ael, DecimalMath::mul($consumerExposureLevel, '1000')), '100');

        return [
            'ael_ug_cm2' => $ael,
            'cmax_without_factor_percent' => $withoutFactor,
            'factor' => $factor,
            'cmax_percent' => $factor === null ? $withoutFactor : DecimalMath::mul($withoutFactor, $factor),
        ];
    }

    /**
     * The factor is applied unless the preset records an expert deviation (enforced when the preset is published).
     *
     * @return array{ael_ug_cm2: string, cmax_without_factor_percent: string, factor: string|null, cmax_percent: string}
     */
    public function forPreset(string $nesil, Qra2Parameter $preset): array
    {
        return $this->maximumConcentration(
            $nesil,
            DecimalMath::of($preset->sensitization_assessment_factor),
            DecimalMath::of($preset->consumer_exposure_level_mg_cm2_day),
            $preset->applies_qra2_factor && $preset->qra2_factor !== null ? DecimalMath::of($preset->qra2_factor) : null,
        );
    }
}
```

Note: `maximumConcentration()` returns the factor exactly as passed in, so the dataset above compares `'0.5'` with `'0.5'`. `forPreset()` passes canonical strings.

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/Qra2CalculatorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/Qra2Calculator.php tests/Feature/Dossiers/Calculation/Qra2CalculatorTest.php
git commit -m "Compute QRA2 maximum concentrations with the preset's adjustment factor"
```

---

### Task 4: Ingredient-tracing verifier

**Files:**
- Create: `app/Services/Dossiers/Calculation/IngredientTracingVerifier.php`
- Test: `tests/Feature/Dossiers/Calculation/IngredientTracingVerifierTest.php`

The verifier is independent of how a composition was built: it checks that every initial gram lands in exactly one final material or one documented loss, that every final gram is explained by contributions, that the mass balance closes, and that the percentages match the grams and total 100 %.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\Dossiers\Calculation\IngredientTracingVerifier;

/**
 * A small consistent ledger in round grams: oil 1000, alkali 100, water 400, juniper oil 5.
 *
 * @return array{0: list<array<string, string>>, 1: list<array<string, string>>, 2: list<array<string, string>>, 3: list<array<string, string>>}
 */
function consistentLedger(): array
{
    $initial = [
        ['key' => 'oil', 'grams' => '1000'],
        ['key' => 'alkali', 'grams' => '100'],
        ['key' => 'water', 'grams' => '400'],
        ['key' => 'juniper', 'grams' => '5'],
    ];
    $contributions = [
        ['from' => 'oil', 'to' => 'salt', 'grams' => '800'],
        ['from' => 'oil', 'to' => 'glycerin', 'grams' => '150'],
        ['from' => 'oil', 'to' => 'unsaponified', 'grams' => '50'],
        ['from' => 'alkali', 'to' => 'salt', 'grams' => '100'],
        ['from' => 'water', 'to' => 'aqua', 'grams' => '200'],
        ['from' => 'juniper', 'to' => 'juniper', 'grams' => '5'],
    ];
    $losses = [['from' => 'water', 'kind' => 'evaporated', 'grams' => '200']];
    $final = collect(['salt' => '900', 'glycerin' => '150', 'unsaponified' => '50', 'aqua' => '200', 'juniper' => '5'])
        ->map(fn (string $grams, string $key): array => ['key' => $key, 'grams' => $grams, 'percent' => bcdiv(bcmul($grams, '100', 18), '1305', 18)])
        ->values()
        ->all();

    return [$initial, $contributions, $losses, $final];
}

it('accepts a ledger where every gram is accounted for', function (): void {
    $result = app(IngredientTracingVerifier::class)->verify(...consistentLedger());

    expect($result['passes'])->toBeTrue()
        ->and($result['gaps'])->toBe([]);
});

it('reports an initial ingredient that is not fully allocated', function (): void {
    [$initial, $contributions, $losses, $final] = consistentLedger();
    $initial[3]['grams'] = '6';

    $result = app(IngredientTracingVerifier::class)->verify($initial, $contributions, $losses, $final);

    expect($result['passes'])->toBeFalse()
        ->and(collect($result['gaps'])->pluck('type')->all())->toContain('unallocated_initial', 'mass_balance');
});

it('fails a composition shaped like the DIP.GA.007 workbook column J at any permitted tolerance', function (?string $tolerance): void {
    [$initial, $contributions, $losses, $final] = consistentLedger();
    // The juniper row is rescaled as in column J (0.743 % instead of a consistent 0.4215 %) and the soap salt row absorbs
    // the difference, so the grams and the percentages still total exactly as before.
    $wrongJuniper = bcmul('5', bcdiv('0.743', '0.4215', 18), 18);
    $final = collect($final)->map(fn (array $row): array => match ($row['key']) {
        'juniper' => [...$row, 'grams' => $wrongJuniper, 'percent' => bcdiv(bcmul($wrongJuniper, '100', 18), '1305', 18)],
        'salt' => [...$row, 'grams' => bcsub('905', $wrongJuniper, 18), 'percent' => bcdiv(bcmul(bcsub('905', $wrongJuniper, 18), '100', 18), '1305', 18)],
        default => $row,
    })->all();

    $result = app(IngredientTracingVerifier::class)->verify($initial, $contributions, $losses, $final, $tolerance);

    expect($result['passes'])->toBeFalse()
        ->and(collect($result['gaps'])->where('type', 'unexplained_final')->pluck('key')->sort()->values()->all())->toBe(['juniper', 'salt']);
})->with(['configured tolerance' => [null], 'the largest permitted tolerance' => ['0.01']]);

it('refuses a tolerance large enough to hide a displaced ingredient', function (string $tolerance): void {
    app(IngredientTracingVerifier::class)->verify(...[...consistentLedger(), $tolerance]);
})->with(['4 g' => ['4'], 'negative' => ['-0.001']])->throws(InvalidArgumentException::class);

it('reports percentages that do not match the grams, unknown keys and negative amounts', function (): void {
    [$initial, $contributions, $losses, $final] = consistentLedger();
    $final[0]['percent'] = '70';
    $contributions[] = ['from' => 'ghost', 'to' => 'salt', 'grams' => '0'];
    $contributions[] = ['from' => 'oil', 'to' => 'nowhere', 'grams' => '-1'];

    $types = collect(app(IngredientTracingVerifier::class)->verify($initial, $contributions, $losses, $final)['gaps'])->pluck('type');

    expect($types->all())->toContain('percent_mismatch', 'percent_total', 'unknown_initial', 'unknown_final', 'negative_amount');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/IngredientTracingVerifierTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Dossiers\Calculation;

use App\Support\DecimalMath;
use InvalidArgumentException;

/**
 * Ingredient tracing before normalisation (spec §4.1): a total of 100 % does not prove the allocation is right.
 * Any difference beyond the representation tolerance is a gap that blocks submission.
 */
class IngredientTracingVerifier
{
    /**
     * @param  list<array{key: string, grams: string}>  $initial
     * @param  list<array{from: string, to: string, grams: string}>  $contributions
     * @param  list<array{from: string, kind: string, grams: string}>  $losses
     * @param  list<array{key: string, grams: string, percent?: string}>  $final
     * @return array{passes: bool, tolerance_g: string, gaps: list<array{type: string, key: string, difference: string}>}
     */
    public function verify(array $initial, array $contributions, array $losses, array $final, ?string $toleranceGrams = null): array
    {
        $tolerance = DecimalMath::of($toleranceGrams ?? config('dossiers.mass_balance_tolerance_g'));

        if (DecimalMath::cmp($tolerance, '0') < 0 || DecimalMath::cmp($tolerance, DecimalMath::of(config('dossiers.mass_balance_tolerance_max_g'))) > 0) {
            throw new InvalidArgumentException('The tracing tolerance must stay within the representation bound.');
        }

        $percentTolerance = DecimalMath::of(config('dossiers.percent_tolerance'));
        $initialGrams = collect($initial)->mapWithKeys(fn (array $row): array => [$row['key'] => DecimalMath::of($row['grams'])]);
        $finalGrams = collect($final)->mapWithKeys(fn (array $row): array => [$row['key'] => DecimalMath::of($row['grams'])]);
        $gaps = [];
        $allocated = [];
        $received = [];

        foreach ($contributions as $contribution) {
            $grams = DecimalMath::of($contribution['grams']);
            $gaps = [...$gaps, ...$this->amountGaps($contribution['from'], $grams)];

            if (! $initialGrams->has($contribution['from'])) {
                $gaps[] = ['type' => 'unknown_initial', 'key' => $contribution['from'], 'difference' => $grams];
            }

            if (! $finalGrams->has($contribution['to'])) {
                $gaps[] = ['type' => 'unknown_final', 'key' => $contribution['to'], 'difference' => $grams];
            }

            $allocated[$contribution['from']] = DecimalMath::add($allocated[$contribution['from']] ?? '0', $grams);
            $received[$contribution['to']] = DecimalMath::add($received[$contribution['to']] ?? '0', $grams);
        }

        foreach ($losses as $loss) {
            $grams = DecimalMath::of($loss['grams']);
            $gaps = [...$gaps, ...$this->amountGaps($loss['from'], $grams)];

            if (! $initialGrams->has($loss['from'])) {
                $gaps[] = ['type' => 'unknown_initial', 'key' => $loss['from'], 'difference' => $grams];
            }

            $allocated[$loss['from']] = DecimalMath::add($allocated[$loss['from']] ?? '0', $grams);
        }

        foreach ($initialGrams as $key => $grams) {
            $difference = DecimalMath::sub($grams, $allocated[$key] ?? '0');

            if (! DecimalMath::withinTolerance($difference, '0', $tolerance)) {
                $gaps[] = ['type' => 'unallocated_initial', 'key' => $key, 'difference' => $difference];
            }
        }

        foreach ($finalGrams as $key => $grams) {
            $difference = DecimalMath::sub($grams, $received[$key] ?? '0');

            if (! DecimalMath::withinTolerance($difference, '0', $tolerance)) {
                $gaps[] = ['type' => 'unexplained_final', 'key' => $key, 'difference' => $difference];
            }
        }

        $totalFinal = DecimalMath::add(...$finalGrams->values()->all());
        $totalLosses = DecimalMath::add(...collect($losses)->map(fn (array $loss): string => DecimalMath::of($loss['grams']))->all());
        $balance = DecimalMath::sub(DecimalMath::sub(DecimalMath::add(...$initialGrams->values()->all()), $totalLosses), $totalFinal);

        if (! DecimalMath::withinTolerance($balance, '0', $tolerance)) {
            $gaps[] = ['type' => 'mass_balance', 'key' => 'total', 'difference' => $balance];
        }

        $gaps = [...$gaps, ...$this->percentGaps($final, $totalFinal, $percentTolerance)];

        return ['passes' => $gaps === [], 'tolerance_g' => $tolerance, 'gaps' => $gaps];
    }

    /**
     * @return list<array{type: string, key: string, difference: string}>
     */
    private function amountGaps(string $key, string $grams): array
    {
        return DecimalMath::cmp($grams, '0') < 0
            ? [['type' => 'negative_amount', 'key' => $key, 'difference' => $grams]]
            : [];
    }

    /**
     * @param  list<array{key: string, grams: string, percent?: string}>  $final
     * @return list<array{type: string, key: string, difference: string}>
     */
    private function percentGaps(array $final, string $totalFinal, string $percentTolerance): array
    {
        $withPercent = collect($final)->filter(fn (array $row): bool => isset($row['percent']));

        if ($withPercent->isEmpty() || ! DecimalMath::isPositive($totalFinal)) {
            return [];
        }

        $gaps = $withPercent
            ->map(function (array $row) use ($totalFinal): array {
                $expected = DecimalMath::percentOf(DecimalMath::of($row['grams']), $totalFinal);

                return ['type' => 'percent_mismatch', 'key' => $row['key'], 'difference' => DecimalMath::sub(DecimalMath::of($row['percent']), $expected)];
            })
            ->reject(fn (array $gap): bool => DecimalMath::withinTolerance($gap['difference'], '0', $percentTolerance))
            ->values()
            ->all();

        $percentTotal = DecimalMath::add(...$withPercent->map(fn (array $row): string => DecimalMath::of($row['percent']))->all());

        if (! DecimalMath::withinTolerance($percentTotal, '100', $percentTolerance)) {
            $gaps[] = ['type' => 'percent_total', 'key' => 'total', 'difference' => DecimalMath::sub($percentTotal, '100')];
        }

        return $gaps;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/IngredientTracingVerifierTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/IngredientTracingVerifier.php tests/Feature/Dossiers/Calculation/IngredientTracingVerifierTest.php
git commit -m "Verify DIP ingredient tracing before normalisation"
```

---
### Task 5: Both compositions and the tracing ledger

**Files:**
- Create: `app/Exceptions/DossierCalculationException.php`, `app/Services/Dossiers/Calculation/DossierCompositionBuilder.php`
- Modify: `tests/Support/DossierCalculationFixtures.php`
- Test: `tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php`

- [ ] **Step 1: Add the saved-formula fixtures**

Add these imports and methods to `tests/Support/DossierCalculationFixtures.php`:

```php
use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Services\RecipeWorkbenchService;
```

```php
    /**
     * 1000 g olive oil (KOH SAP 0.188), NaOH, superfat 5 %, water 38 % of oils, saved through the workbench in a
     * workspace that has the DIP module. Extra rows come through $phaseItems (e.g. 'fragrance', 'additives').
     *
     * @param  array<string, list<array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}>>  $phaseItems
     * @param  array<string, mixed>  $payloadOverrides
     * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, workingCopy: RecipeVersion, olive: Ingredient}
     */
    public static function oliveSoap(array $phaseItems = [], array $payloadOverrides = [], ?Ingredient $oil = null, int|float $oilWeight = 1000): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        WorkspaceDossierGrant::factory()->for($workspace)->create();
        $family = ProductFamily::query()->where('slug', 'soap')->first()
            ?? ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap', 'calculation_basis' => 'oil_weight']);
        $olive = $oil ?? self::oil('Olive Oil', 'OLEA EUROPAEA FRUIT OIL', 'SODIUM OLIVATE', 'POTASSIUM OLIVATE', 0.188);

        $payload = [
            'name' => 'DIP engine soap',
            'product_type_id' => testProductTypeIdForFamily('soap'),
            'oil_unit' => 'g',
            'oil_weight' => $oilWeight,
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'eu',
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 100,
            'dual_lye_koh_percentage' => 40,
            'water_mode' => 'percent_of_oils',
            'water_value' => 38,
            'superfat' => 5,
            'ifra_product_category_id' => null,
            'phase_items' => [
                'saponified_oils' => [self::row($olive, 100, $oilWeight)],
                'additives' => [],
                'fragrance' => [],
                ...$phaseItems,
            ],
            ...$payloadOverrides,
        ];

        $service = app(RecipeWorkbenchService::class);
        $draft = $service->save($owner, $family, $payload);
        $recipe = Recipe::withoutGlobalScopes()->findOrFail($draft->recipe_id);
        $service->saveAsNewVersion($owner, $family, $payload, $recipe);
        $versions = RecipeVersion::withoutGlobalScopes()->where('recipe_id', $recipe->id)->get();

        return [
            'owner' => $owner,
            'workspace' => $workspace,
            'recipe' => $recipe,
            'version' => $versions->firstWhere('is_current', false),
            'workingCopy' => $versions->firstWhere('is_current', true),
            'olive' => $olive,
        ];
    }

    /**
     * A trusted saponifiable lipid with its SAP profile; a vegetable oil unless another subcategory is given.
     */
    public static function oil(string $name, string $inci, string $sodiumSalt, string $potassiumSalt, float $kohSap, ?IngredientSubcategory $subcategory = IngredientSubcategory::VegetableOils): Ingredient
    {
        $oil = self::ingredient($name, $inci, IngredientCategory::Lipids, [
            'subcategory' => $subcategory,
            'soap_inci_naoh_name' => $sodiumSalt,
            'soap_inci_koh_name' => $potassiumSalt,
            'is_soap_saponification_trusted' => true,
        ]);
        IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => $kohSap]);

        return $oil;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function ingredient(string $name, string $inci, IngredientCategory $category, array $attributes = []): Ingredient
    {
        return Ingredient::factory()->create([
            'category' => $category,
            'display_name' => $name,
            'inci_name' => $inci,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @return array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}
     */
    public static function row(Ingredient $ingredient, int|float $percentage, int|float $weight): array
    {
        return ['ingredient_id' => $ingredient->id, 'percentage' => $percentage, 'weight' => $weight, 'note' => null];
    }
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\IngredientCategory;
use App\Enums\IngredientSubcategory;
use App\Exceptions\DossierCalculationException;
use App\Models\ProductFamily;
use App\Services\Dossiers\Calculation\DossierCompositionBuilder;
use App\Services\FormulaDocumentBuilder;
use App\Services\RecipeWorkbenchVersionDataService;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $composition
 * @return array<string, mixed>
 */
function compositionFinal(array $composition, string $key): array
{
    return collect($composition['final'])->firstWhere('key', $key) ?? throw new RuntimeException("No final row [{$key}].");
}

/**
 * @param  array<string, mixed>  $row
 * @return array{0: string, 1: string}
 */
function gramsAndPercent(array $row): array
{
    return [DecimalMath::round($row['grams'], 6), DecimalMath::round($row['percent'], 6)];
}

/**
 * @param  Closure(): mixed  $build
 */
function expectCalculationRefused(Closure $build, string $reason): void
{
    expect($build)->toThrow(function (DossierCalculationException $exception) use ($reason): void {
        expect($exception->reason)->toBe($reason);
    });
}

it('builds the initial and final composition of a simple olive soap', function (): void {
    $soap = Fixtures::oliveSoap();

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $initial = collect($composition['initial'])->keyBy('key');

    expect(DecimalMath::round($initial['item:saponified_oils:0']['grams'], 4))->toBe('1000.0000')
        ->and(DecimalMath::round($initial['naoh']['grams'], 4))->toBe('127.3418')
        ->and(DecimalMath::round($initial['water']['grams'], 4))->toBe('380.0000')
        ->and(DecimalMath::round($composition['basis']['initial_mass_g'], 4))->toBe('1507.3418')
        ->and(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1266.676180')
        ->and($composition['basis']['residual_source'])->toBe('formula_estimate')
        ->and(gramsAndPercent(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')))->toBe(['979.607174', '77.336828'])
        ->and(gramsAndPercent(compositionFinal($composition, 'glycerin')))->toBe(['97.734626', '7.715834'])
        ->and(gramsAndPercent(compositionFinal($composition, 'unsaponified:ingredient:'.$soap['olive']->id)))->toBe(['50.000000', '3.947339'])
        ->and(gramsAndPercent(compositionFinal($composition, 'residual_water')))->toBe(['139.334380', '11.000000'])
        ->and(DecimalMath::round(collect($composition['losses'])->firstWhere('from', 'water')['grams'], 6))->toBe('240.665620')
        ->and(DecimalMath::round($composition['reactions'][0]['naoh_g'], 4))->toBe('127.3418')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('carries a fragrance at unchanged mass and agrees with the label cured output', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $soap = Fixtures::oliveSoap(['fragrance' => [Fixtures::row($lavender, 3, 30)]]);

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $snapshot = app(RecipeWorkbenchVersionDataService::class)->versionSnapshot($soap['recipe'], $soap['version']->id);
    $label = collect(app(FormulaDocumentBuilder::class)->build($snapshot, ['calculation_basis' => 'oil_weight'])['soap_output']['rows'])
        ->mapWithKeys(fn (array $row): array => [$row['name'] => number_format($row['weight'], 4, '.', '')]);

    expect(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1300.384045')
        ->and(gramsAndPercent(compositionFinal($composition, 'carried:ingredient:'.$lavender->id)))->toBe(['30.000000', '2.307011'])
        ->and(DecimalMath::round(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['percent'], 6))->toBe('75.332143')
        ->and(DecimalMath::round(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['grams'], 4))->toBe($label['SODIUM OLIVATE'])
        ->and(DecimalMath::round(compositionFinal($composition, 'glycerin')['grams'], 4))->toBe($label['GLYCERIN'])
        ->and(DecimalMath::round(compositionFinal($composition, 'residual_water')['grams'], 4))->toBe($label['AQUA'])
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('uses the saved weights, not the independently rounded percentages', function (): void {
    // In weight-editing mode the workbench stores 1.2345 g with 0.0123 % and 0.001 g with 0.0000 % on a 10 kg basis.
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $mint = Fixtures::ingredient('Peppermint Oil', 'MENTHA PIPERITA OIL', IngredientCategory::AromaticMaterials);
    $soap = Fixtures::oliveSoap(
        ['fragrance' => [Fixtures::row($lavender, 0.0123, 1.2345), Fixtures::row($mint, 0, 0.001)]],
        ['editing_mode' => 'weight'],
        oilWeight: 10000,
    );

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);

    expect(DecimalMath::round(compositionFinal($composition, 'carried:ingredient:'.$lavender->id)['grams'], 4))->toBe('1.2345')
        ->and(DecimalMath::round(compositionFinal($composition, 'carried:ingredient:'.$mint->id)['grams'], 4))->toBe('0.0010')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('takes a measured cure loss from the water only', function (): void {
    $soap = Fixtures::oliveSoap();

    $composition = app(DossierCompositionBuilder::class)->build($soap['version'], measuredCureLossPercent: '15');

    expect($composition['basis']['residual_source'])->toBe('measured')
        ->and(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1281.240530')
        ->and(gramsAndPercent(compositionFinal($composition, 'residual_water')))->toBe(['153.898730', '12.011697'])
        ->and(DecimalMath::round(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['grams'], 6))->toBe('979.607174')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('uses a justified residual water percentage', function (): void {
    $soap = Fixtures::oliveSoap();

    $composition = app(DossierCompositionBuilder::class)->build($soap['version'], residualWaterPercent: '9');

    expect($composition['basis']['residual_source'])->toBe('override')
        ->and(DecimalMath::round($composition['basis']['cured_mass_g'], 6))->toBe('1238.837143')
        ->and(DecimalMath::round(compositionFinal($composition, 'residual_water')['grams'], 6))->toBe('111.495343');
});

it('traces a dual-lye soap with KOH 100 % into both soap salts', function (): void {
    $soap = Fixtures::oliveSoap(payloadOverrides: ['lye_type' => 'dual', 'dual_lye_koh_percentage' => 40, 'koh_purity_percentage' => 100]);

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $initial = collect($composition['initial'])->keyBy('key');

    expect($composition['tracing']['passes'])->toBeTrue()
        ->and(DecimalMath::isPositive(compositionFinal($composition, 'soap_salt:SODIUM OLIVATE')['grams']))->toBeTrue()
        ->and(DecimalMath::isPositive(compositionFinal($composition, 'soap_salt:POTASSIUM OLIVATE')['grams']))->toBeTrue()
        ->and(DecimalMath::round($composition['reactions'][0]['koh_g'], 4))->toBe(DecimalMath::round($initial['koh']['grams'], 4));
});

it('refuses formulas and materials the pilot engine does not support', function (string $reason): void {
    $builder = app(DossierCompositionBuilder::class);

    // Pest calls closures placed in a dataset, so each case is selected by its expected reason.
    match ($reason) {
        'working_copy' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap()['workingCopy']), $reason),
        'unsupported_family' => expectCalculationRefused(function () use ($builder) {
            $soap = Fixtures::oliveSoap();
            ProductFamily::query()->whereKey($soap['recipe']->product_family_id)->update(['calculation_basis' => 'total_formula']);

            return $builder->build($soap['version']);
        }, $reason),
        'measured_loss_out_of_range' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap()['version'], measuredCureLossPercent: '30'), $reason),
        'unsupported_reacting_material' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap(oil: Fixtures::oil(
            'Fractionated Coconut Oil', 'CAPRYLIC/CAPRIC TRIGLYCERIDE', 'SODIUM CAPRYLATE', 'POTASSIUM CAPRYLATE', 0.33, IngredientSubcategory::FractionatedModifiedLipids,
        ))['version']), $reason),
        'lye_substitute_unsupported' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap([
            'lye_water' => [Fixtures::row(Fixtures::ingredient('Glycerin', 'GLYCERIN', IngredientCategory::HumectantsPolyols), 25, 95)],
        ])['version']), $reason),
        'koh_non_active_share_unsupported' => expectCalculationRefused(fn () => $builder->build(Fixtures::oliveSoap(
            payloadOverrides: ['lye_type' => 'koh', 'koh_purity_percentage' => 90],
        )['version']), $reason),
    };
})->with([
    'working_copy',
    'unsupported_family',
    'measured_loss_out_of_range',
    'unsupported_reacting_material',
    'lye_substitute_unsupported',
    'koh_non_active_share_unsupported',
]);

it('names the lye settings it cannot assess', function (array $lye, ?string $reason): void {
    expect(app(DossierCompositionBuilder::class)->unsupportedLyeReason($lye))->toBe($reason);
})->with([
    'supported' => [['superfat_percentage' => 5, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0], 'selected' => ['koh_weight' => 0, 'koh_to_weigh' => 0]], null],
    'negative superfat' => [['superfat_percentage' => -1, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0], 'selected' => []], 'negative_superfat'],
    'excess NaOH' => [['superfat_percentage' => 0, 'excess' => ['naoh_weight' => 0.5, 'koh_weight' => 0], 'selected' => []], 'excess_alkali'],
    'excess KOH' => [['superfat_percentage' => 0, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0.5], 'selected' => []], 'excess_alkali'],
    'KOH 90 %' => [['superfat_percentage' => 5, 'excess' => ['naoh_weight' => 0, 'koh_weight' => 0], 'selected' => ['koh_weight' => 90, 'koh_to_weigh' => 100]], 'koh_non_active_share_unsupported'],
]);
```

Note for the weight-mode test: if the workbench recomputes a weight-mode row from its percentage, the test fails at its first assertion; that would mean the saved weight is not the authority and decision 2 must be revisited with the owner. (Probe on 2026-10-23: the weights above are stored as given.)

- [ ] **Step 3: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php`
Expected: FAIL, class `DossierCompositionBuilder` not found.

- [ ] **Step 4: Implement**

`app/Exceptions/DossierCalculationException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The DIP calculation cannot run for a named reason (spec §5: "a calculation that cannot run" blocks submission).
 */
class DossierCalculationException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $context
     */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct("The DIP calculation cannot run: {$reason}.");
    }
}
```

`app/Services/Dossiers/Calculation/DossierCompositionBuilder.php`:

```php
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
```

The residual-water bounds (0–30 %) and the measured-loss bounds are enforced earlier, by `DossierAssessmentOptions` (Task 7); the checks left here protect the builder when it is called directly.

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php`
Expected: PASS (17 tests). If the label cross-check differs in the 4th decimal, compare the label row's own rounding (`SoapCuredOutputBuilder::roundMass`) before changing anything: the engine's grams are the full-precision reference.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Exceptions/DossierCalculationException.php app/Services/Dossiers/Calculation/DossierCompositionBuilder.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php
git commit -m "Build both DIP soap compositions from saved weights with an ingredient-tracing ledger"
```

---

### Task 6: Allergen, impurity and restricted-substance overlays

**Files:**
- Modify: `app/Services/Dossiers/SupplierConstituentResolver.php`
- Create: `app/Services/Dossiers/Calculation/ConstituentOverlayBuilder.php`
- Test: `tests/Feature/Dossiers/Calculation/ConstituentOverlayBuilderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ConstituentKind;
use App\Enums\EvidenceType;
use App\Models\Allergen;
use App\Models\DossierEvidenceConstituent;
use App\Models\DossierEvidenceItem;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\IngredientComponent;
use App\Models\IngredientSubstanceEntry;
use App\Models\Substance;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\Calculation\ConstituentOverlayBuilder;
use App\Services\Dossiers\SupplierConstituentResolver;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function overlayDeclaration(Workspace $workspace, Ingredient $ingredient, EvidenceType $type, ConstituentKind $kind, array $constituent): void
{
    $qualification = SupplierQualification::factory()->create(['workspace_id' => $workspace->id, 'ingredient_id' => $ingredient->id]);
    $item = DossierEvidenceItem::factory()->create([
        'supplier_qualification_id' => $qualification->id,
        'type' => $type,
        'constituents_complete' => true,
    ]);
    DossierEvidenceConstituent::factory()->create(['dossier_evidence_item_id' => $item->id, 'kind' => $kind, ...$constituent]);
}

/**
 * @return array{key: string, ingredient_id: int|null, name: string, grams: string}
 */
function overlayInitial(string $key, ?Ingredient $ingredient, string $grams): array
{
    return ['key' => $key, 'ingredient_id' => $ingredient?->id, 'name' => $ingredient?->display_name ?? $key, 'grams' => $grams];
}

it('sums the same allergen from every ingredient over the cured mass', function (): void {
    $workspace = Workspace::factory()->create();
    $limonene = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
    $lavender = Ingredient::factory()->create();
    $orange = Ingredient::factory()->create();
    overlayDeclaration($workspace, $lavender, EvidenceType::AllergenDeclaration, ConstituentKind::Allergen, ['allergen_id' => $limonene->id, 'max_value' => '40']);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $orange->id, 'allergen_id' => $limonene->id, 'concentration_percent' => 2]);

    $overlays = app(ConstituentOverlayBuilder::class)->build($workspace, [
        overlayInitial('item:fragrance:0', $lavender, '30'),
        overlayInitial('item:fragrance:1', $orange, '10'),
        overlayInitial('naoh', null, '127.3418'),
    ], '1500', '1300');
    $row = collect($overlays['rows'])->firstWhere('key', 'allergen:'.$limonene->id);

    expect(DecimalMath::round($row['grams'], 6))->toBe('12.200000')
        ->and(DecimalMath::round($row['final_percent'], 6))->toBe('0.938462')
        ->and(DecimalMath::round($row['initial_percent'], 6))->toBe('0.813333')
        ->and($row['needs_justification'])->toBeTrue()
        ->and(collect($row['contributions'])->pluck('source')->all())->toBe(['supplier', 'platform']);
});

it('expresses impurities in ppm and reports suppliers that declared nothing', function (): void {
    $workspace = Workspace::factory()->create();
    $lead = Substance::factory()->create(['name' => 'Lead']);
    $olive = Ingredient::factory()->create();
    overlayDeclaration($workspace, $olive, EvidenceType::TechnicalSheet, ConstituentKind::Impurity, ['allergen_id' => null, 'substance_id' => $lead->id, 'max_value' => '2', 'unit' => 'ppm']);

    $overlays = app(ConstituentOverlayBuilder::class)->build($workspace, [overlayInitial('item:saponified_oils:0', $olive, '1000')], '1500', '1300');
    $row = collect($overlays['rows'])->firstWhere('key', 'impurity:'.$lead->id);

    expect(DecimalMath::round($row['final_ppm'], 6))->toBe('1.538462')
        ->and($row['needs_justification'])->toBeFalse()
        ->and(collect($overlays['undeclared'])->where('ingredient_id', $olive->id)->pluck('kind')->sort()->values()->all())
        ->toBe(['allergen', 'restricted_substance']);
});

it('reads a blend without its own validated supplier through its components', function (): void {
    $workspace = Workspace::factory()->create();
    $linalool = Allergen::factory()->create(['inci_name' => 'LINALOOL']);
    $blend = Ingredient::factory()->create();
    $component = Ingredient::factory()->create();
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $component->id, 'percentage_in_parent' => 50]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $component->id, 'allergen_id' => $linalool->id, 'concentration_percent' => 10]);

    $overlays = app(ConstituentOverlayBuilder::class)->build($workspace, [overlayInitial('item:fragrance:0', $blend, '20')], '1500', '1300');
    $row = collect($overlays['rows'])->firstWhere('key', 'allergen:'.$linalool->id);

    expect(DecimalMath::round($row['grams'], 6))->toBe('1.000000')
        ->and($row['contributions'][0]['ingredient_id'])->toBe($component->id);
});

it('resolves restricted substances from conformity certificates first, then the flagged platform composition', function (): void {
    $workspace = Workspace::factory()->create();
    $coumarin = Substance::factory()->create(['name' => 'Coumarin']);
    $safrole = Substance::factory()->create(['name' => 'Safrole']);
    $oil = Ingredient::factory()->create();
    overlayDeclaration($workspace, $oil, EvidenceType::RegulatoryConformityCertificate, ConstituentKind::RestrictedSubstance, ['allergen_id' => null, 'substance_id' => $coumarin->id, 'max_value' => '0.8']);
    IngredientSubstanceEntry::factory()->create(['ingredient_id' => $oil->id, 'substance_id' => $coumarin->id, 'concentration_percent' => 0.1]);
    IngredientSubstanceEntry::factory()->create(['ingredient_id' => $oil->id, 'substance_id' => $safrole->id, 'concentration_percent' => 0.05]);

    $values = app(SupplierConstituentResolver::class)->restrictedSubstances($workspace, $oil)->keyBy('substance_id');

    expect(DecimalMath::round(DecimalMath::of($values[$coumarin->id]['max_value']), 2))->toBe('0.80')
        ->and($values[$coumarin->id]['source'])->toBe('supplier')
        ->and($values[$safrole->id]['source'])->toBe('platform')
        ->and($values[$safrole->id]['needs_justification'])->toBeTrue();
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/ConstituentOverlayBuilderTest.php`
Expected: FAIL, class `ConstituentOverlayBuilder` not found.

- [ ] **Step 3: Add `restrictedSubstances()` to the resolver**

In `app/Services/Dossiers/SupplierConstituentResolver.php`, add `use App\Models\IngredientSubstanceEntry;` and `use App\Support\DecimalMath;`, and this method after `impurities()`:

```php
    /**
     * Restricted substances: regulatory conformity certificates first; the platform composition (substance entries
     * with a stated concentration) only as a flagged fallback.
     *
     * @return Collection<int, array{substance_id: int, max_value: string, unit: string, source: string,
     *                               evidence_item_ids: list<int>, suppliers_without_declaration: list<int>, needs_justification: bool}>
     */
    public function restrictedSubstances(Workspace $workspace, Ingredient $ingredient): Collection
    {
        $silent = $this->suppliersWithoutDeclaration($workspace, $ingredient, ConstituentKind::RestrictedSubstance);
        $declared = $this->worstCase($workspace, $ingredient, ConstituentKind::RestrictedSubstance, 'substance_id', $silent);

        $fallback = IngredientSubstanceEntry::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('concentration_percent')
            ->whereNotIn('substance_id', $declared->keys()->all())
            ->get()
            ->map(fn (IngredientSubstanceEntry $entry): array => [
                'substance_id' => $entry->substance_id,
                'max_value' => DecimalMath::of($entry->concentration_percent),
                'unit' => ConstituentKind::RestrictedSubstance->unit(),
                'source' => 'platform',
                'evidence_item_ids' => [],
                'suppliers_without_declaration' => $silent,
                'needs_justification' => true,
            ]);

        return $declared->values()->concat($fallback)->values();
    }
```

- [ ] **Step 4: Implement the overlay builder**

```php
<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\ConstituentKind;
use App\Enums\SupplierQualificationStatus;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\SupplierQualification;
use App\Models\Workspace;
use App\Services\Dossiers\SupplierConstituentResolver;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Allergen, impurity and restricted-substance overlays (spec §4.1): Σ (worst-case value in the raw material × raw
 * material mass), over both compositions. Overlays never add mass.
 */
class ConstituentOverlayBuilder
{
    private const MAX_COMPONENT_DEPTH = 3;

    public function __construct(private readonly SupplierConstituentResolver $resolver) {}

    /**
     * @param  list<array{key: string, ingredient_id: int|null, grams: string}>  $initial
     * @return array{
     *     rows: list<array{key: string, kind: string, allergen_id: int|null, substance_id: int|null, grams: string, needs_justification: bool,
     *                      contributions: list<array<string, mixed>>, final_percent: string, initial_percent: string, final_ppm: string, initial_ppm: string}>,
     *     undeclared: list<array{initial_key: string, ingredient_id: int, kind: string, qualification_ids: list<int>}>
     * }
     */
    public function build(Workspace $workspace, array $initial, string $initialMass, string $finalMass): array
    {
        $totals = [];
        $undeclared = [];

        foreach ($initial as $row) {
            $ingredient = $row['ingredient_id'] === null ? null : Ingredient::withoutGlobalScopes()->find($row['ingredient_id']);

            if (! $ingredient instanceof Ingredient) {
                continue;
            }

            foreach ($this->materials($workspace, $ingredient, DecimalMath::of($row['grams']), 0) as [$material, $grams]) {
                foreach (ConstituentKind::cases() as $kind) {
                    $silent = $this->resolver->suppliersWithoutDeclaration($workspace, $material, $kind);

                    if ($silent !== []) {
                        $undeclared[] = ['initial_key' => $row['key'], 'ingredient_id' => $material->id, 'kind' => $kind->value, 'qualification_ids' => $silent];
                    }

                    foreach ($this->declared($workspace, $material, $kind) as $value) {
                        $totals = $this->addContribution($totals, $kind, $value, $row['key'], $material, $grams);
                    }
                }
            }
        }

        $rows = collect($totals)
            ->map(fn (array $row): array => [
                ...$row,
                'final_percent' => DecimalMath::percentOf($row['grams'], $finalMass),
                'initial_percent' => DecimalMath::percentOf($row['grams'], $initialMass),
                'final_ppm' => DecimalMath::div(DecimalMath::mul($row['grams'], '1000000'), $finalMass),
                'initial_ppm' => DecimalMath::div(DecimalMath::mul($row['grams'], '1000000'), $initialMass),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'undeclared' => $undeclared];
    }

    /**
     * @param  array<string, array<string, mixed>>  $totals
     * @param  array<string, mixed>  $value
     * @return array<string, array<string, mixed>>
     */
    private function addContribution(array $totals, ConstituentKind $kind, array $value, string $initialKey, Ingredient $material, string $grams): array
    {
        $isAllergen = $kind === ConstituentKind::Allergen;
        $id = (int) ($isAllergen ? $value['allergen_id'] : $value['substance_id']);
        $key = $kind->value.':'.$id;
        $declaredValue = DecimalMath::of($value['max_value']);
        $mass = DecimalMath::div(DecimalMath::mul($grams, $declaredValue), $kind === ConstituentKind::Impurity ? '1000000' : '100');

        $totals[$key] ??= [
            'key' => $key,
            'kind' => $kind->value,
            'allergen_id' => $isAllergen ? $id : null,
            'substance_id' => $isAllergen ? null : $id,
            'grams' => '0',
            'needs_justification' => false,
            'contributions' => [],
        ];
        $totals[$key]['grams'] = DecimalMath::add($totals[$key]['grams'], $mass);
        $totals[$key]['needs_justification'] = $totals[$key]['needs_justification'] || (bool) $value['needs_justification'];
        $totals[$key]['contributions'][] = [
            'initial_key' => $initialKey,
            'ingredient_id' => $material->id,
            'material_grams' => $grams,
            'value' => $declaredValue,
            'unit' => $value['unit'],
            'source' => $value['source'],
            'evidence_item_ids' => $value['evidence_item_ids'],
            'suppliers_without_declaration' => $value['suppliers_without_declaration'],
            'grams' => $mass,
        ];

        return $totals;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function declared(Workspace $workspace, Ingredient $material, ConstituentKind $kind): Collection
    {
        return match ($kind) {
            ConstituentKind::Allergen => $this->resolver->allergens($workspace, $material),
            ConstituentKind::Impurity => $this->resolver->impurities($workspace, $material),
            ConstituentKind::RestrictedSubstance => $this->resolver->restrictedSubstances($workspace, $material),
        };
    }

    /**
     * A blend with components and no validated supplier of its own is read through its components (decision 9).
     *
     * @return list<array{0: Ingredient, 1: string}>
     */
    private function materials(Workspace $workspace, Ingredient $ingredient, string $grams, int $depth): array
    {
        $components = IngredientComponent::query()
            ->where('ingredient_id', $ingredient->id)
            ->whereNotNull('component_ingredient_id')
            ->with(['componentIngredient' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (IngredientComponent $component): bool => $component->componentIngredient instanceof Ingredient);
        $hasOwnSupplier = SupplierQualification::query()
            ->where('workspace_id', $workspace->id)
            ->where('ingredient_id', $ingredient->id)
            ->where('status', SupplierQualificationStatus::Valide->value)
            ->exists();

        if ($components->isEmpty() || $hasOwnSupplier || $depth >= self::MAX_COMPONENT_DEPTH) {
            return [[$ingredient, $grams]];
        }

        return $components
            ->flatMap(fn (IngredientComponent $component): array => $this->materials(
                $workspace,
                $component->componentIngredient,
                DecimalMath::div(DecimalMath::mul($grams, DecimalMath::of($component->percentage_in_parent)), '100'),
                $depth + 1,
            ))
            ->values()
            ->all();
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/ConstituentOverlayBuilderTest.php tests/Feature/Dossiers/SupplierConstituentResolverTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/SupplierConstituentResolver.php app/Services/Dossiers/Calculation/ConstituentOverlayBuilder.php tests/Feature/Dossiers/Calculation/ConstituentOverlayBuilderTest.php
git commit -m "Sum DIP allergen, impurity and restricted-substance overlays across ingredients"
```

---
### Task 7: Dossier options and assessment parameters

**Files:**
- Create: `app/Enums/AssessmentRoute.php`, `app/Data/DossierAssessmentOptions.php`, `app/Services/Dossiers/Calculation/AssessmentParameterResolver.php`
- Test: `tests/Feature/Dossiers/Calculation/AssessmentParameterResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Data\DossierAssessmentOptions;
use App\Exceptions\DossierCalculationException;
use App\Models\AssessmentPolicy;
use App\Models\ExposurePreset;
use App\Models\Qra2Parameter;
use App\Services\Dossiers\Calculation\AssessmentParameterResolver;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the exposure preset, the policy and the QRA2 preset with their sources', function (): void {
    $policy = AssessmentPolicy::factory()->create();
    $preset = ExposurePreset::factory()->create();
    $qra2 = Qra2Parameter::factory()->create();
    ExposurePreset::factory()->create(['product_type_id' => $preset->product_type_id, 'version' => 2, 'is_current' => false, 'amount_per_use_g' => '5']);

    $parameters = app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, $qra2->ifra_product_category_id, $qra2->ifra_amendment_id, new DossierAssessmentOptions);
    $used = collect($parameters['used'])->keyBy('key');

    expect(DecimalMath::round($parameters['exposure']->dailyAmountGrams(), 2))->toBe('10.00')
        ->and(DecimalMath::round($parameters['policy_dap'], 2))->toBe('100.00')
        ->and(DecimalMath::round($parameters['mos_threshold'], 2))->toBe('100.00')
        ->and($parameters['is_rinse_off'])->toBeTrue()
        ->and($parameters['qra2']->is($qra2))->toBeTrue()
        ->and($used['amount_per_use_g']['source'])->toBe("exposure_preset:{$preset->id}:v1")
        ->and($used['body_weight_kg']['source'])->toBe("assessment_policy:{$policy->id}:v1");
});

it('applies a justified override and records the default it replaced', function (): void {
    AssessmentPolicy::factory()->create();
    $preset = ExposurePreset::factory()->create();

    $parameters = app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, null, null, new DossierAssessmentOptions(
        overrides: ['amount_per_use_g' => ['value' => '2', 'justification' => 'Large bar: weighed use of 2 g.']],
    ));
    $override = collect($parameters['used'])->firstWhere('key', 'amount_per_use_g');

    expect(DecimalMath::round($parameters['exposure']->dailyAmountGrams(), 2))->toBe('20.00')
        ->and($override['source'])->toBe('override')
        ->and(DecimalMath::round($override['default'], 2))->toBe('1.00')
        ->and($override['justification'])->toBe('Large bar: weighed use of 2 g.')
        ->and($parameters['qra2'])->toBeNull();
});

it('cannot run without a policy or an exposure preset', function (bool $withPolicy, string $reason): void {
    if ($withPolicy) {
        AssessmentPolicy::factory()->create();
    }

    $preset = ExposurePreset::factory()->create(['is_current' => false]);

    expect(fn () => app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, null, null, new DossierAssessmentOptions))
        ->toThrow(function (DossierCalculationException $exception) use ($reason): void {
            expect($exception->reason)->toBe($reason);
        });
})->with([
    'no policy' => [false, 'no_assessment_policy'],
    'no current preset' => [true, 'no_exposure_preset'],
]);

it('refuses unknown, unjustified or physically impossible inputs', function (array $arguments): void {
    new DossierAssessmentOptions(...$arguments);
})->with([
    'unknown key' => [['overrides' => ['mos_threshold' => ['value' => '50', 'justification' => 'x']]]],
    'no justification' => [['overrides' => ['retention_factor' => ['value' => '0.1', 'justification' => ' ']]]],
    'not a number' => [['overrides' => ['retention_factor' => ['value' => 'ten', 'justification' => 'x']]]],
    'negative retention' => [['overrides' => ['retention_factor' => ['value' => '-0.01', 'justification' => 'x']]]],
    'zero retention' => [['overrides' => ['retention_factor' => ['value' => '0', 'justification' => 'x']]]],
    'retention above 1' => [['overrides' => ['retention_factor' => ['value' => '1.5', 'justification' => 'x']]]],
    'zero body weight' => [['overrides' => ['body_weight_kg' => ['value' => '0', 'justification' => 'x']]]],
    'zero amount per use' => [['overrides' => ['amount_per_use_g' => ['value' => '0', 'justification' => 'x']]]],
    'zero uses per day' => [['overrides' => ['uses_per_day' => ['value' => '0', 'justification' => 'x']]]],
    'absorption above 100 %' => [['overrides' => ['default_dermal_absorption_percent' => ['value' => '100.1', 'justification' => 'x']]]],
    'negative absorption' => [['overrides' => ['default_dermal_absorption_percent' => ['value' => '-1', 'justification' => 'x']]]],
    'bioavailability above 100 %' => [['overrides' => ['default_oral_bioavailability_percent' => ['value' => '101', 'justification' => 'x']]]],
    'residual water above 30 %' => [['overrides' => ['residual_water_percent' => ['value' => '31', 'justification' => 'x']]]],
    'measured loss of 100 %' => [['measuredCureLossPercent' => '100']],
    'unknown route' => [['confirmations' => ['glycerin' => ['route' => 'guess', 'justification' => 'x']]]],
    'unknown annex answer' => [['confirmations' => ['glycerin' => ['route' => 'mos', 'justification' => 'x', 'annex_conditions' => 'maybe']]]],
])->throws(InvalidArgumentException::class);

it('accepts the limits themselves, zero absorption included', function (array $arguments): void {
    expect(new DossierAssessmentOptions(...$arguments))->toBeInstanceOf(DossierAssessmentOptions::class);
})->with([
    'zero absorption' => [['overrides' => ['default_dermal_absorption_percent' => ['value' => '0', 'justification' => 'Measured: not absorbed.']]]],
    'retention of 1' => [['overrides' => ['retention_factor' => ['value' => '1', 'justification' => 'Leave-on worst case.']]]],
    'residual water of 30 %' => [['overrides' => ['residual_water_percent' => ['value' => '30', 'justification' => 'Freshly cut bars.']]]],
    'no measured loss' => [['measuredCureLossPercent' => '0']],
]);

it('does not run with a stored preset value outside its physical bounds', function (): void {
    AssessmentPolicy::factory()->create();
    $preset = ExposurePreset::factory()->create(['retention_factor' => '0']);

    expect(fn () => app(AssessmentParameterResolver::class)->resolve($preset->product_type_id, null, null, new DossierAssessmentOptions))
        ->toThrow(function (DossierCalculationException $exception): void {
            expect($exception->reason)->toBe('invalid_parameter')
                ->and($exception->context)->toBe(['key' => 'retention_factor']);
        });
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/AssessmentParameterResolverTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`app/Enums/AssessmentRoute.php`:

```php
<?php

namespace App\Enums;

/**
 * How a DIP row is concluded (spec §4.2). Each value is also the key of the indicator that must pass.
 */
enum AssessmentRoute: string
{
    case MarginOfSafety = 'mos';
    case Cmax = 'cmax';
    case Adi = 'adi';
    case Ttc = 'ttc';
    case Qra2 = 'qra2';
}
```

`app/Data/DossierAssessmentOptions.php`:

```php
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
 * evaluation date. Phase 4 stores them on the dossier version.
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
```

`app/Services/Dossiers/Calculation/AssessmentParameterResolver.php`:

```php
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
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/AssessmentParameterResolverTest.php`
Expected: PASS (25 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/AssessmentRoute.php app/Data/DossierAssessmentOptions.php app/Services/Dossiers/Calculation/AssessmentParameterResolver.php tests/Feature/Dossiers/Calculation/AssessmentParameterResolverTest.php
git commit -m "Resolve DIP assessment parameters with justified dossier overrides"
```

---

### Task 8: EU annex checks and allergen label declaration

**Files:**
- Create: `app/Services/Dossiers/Calculation/LegalEntryChecker.php`
- Test: `tests/Feature/Dossiers/Calculation/LegalEntryCheckerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Allergen;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\Substance;
use App\Services\Dossiers\Calculation\LegalEntryChecker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 */
function legalEntryFor(Substance $substance, array $attributes, bool $reviewed = true): RegulatoryLegalEntry
{
    $regime = RegulatoryRegime::query()->firstWhere('code', 'eu') ?? RegulatoryRegime::factory()->create(['code' => 'eu']);
    $factory = RegulatoryLegalEntry::factory();
    $entry = ($reviewed ? $factory->reviewed() : $factory)->create(['regulatory_regime_id' => $regime->id, ...$attributes]);
    $entry->substances()->attach($substance->id);

    return $entry;
}

function legalCheckDate(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-23');
}

it('makes a banned or over-limit substance a binding breach and passes exactly at the limit', function (array $attributes, string $concentration, bool $breach): void {
    $substance = Substance::factory()->create();
    legalEntryFor($substance, $attributes);

    $result = app(LegalEntryChecker::class)->check($substance, $concentration, true, legalCheckDate());

    expect($result['binding_breach'])->toBe($breach)
        ->and($result['entries'])->toHaveCount(1);
})->with([
    'annex II present' => [['annex' => 'II'], '0.0001', true],
    'annex III above the maximum' => [['annex' => 'III', 'max_concentration_percent' => '0.5'], '0.500001', true],
    'annex III exactly at the maximum' => [['annex' => 'III', 'max_concentration_percent' => '0.5'], '0.5', false],
]);

it('follows the product scope and flags conditions it cannot check', function (): void {
    $substance = Substance::factory()->create();
    legalEntryFor($substance, ['annex' => 'III', 'entry_reference' => 'III/1', 'product_scope' => 'leave_on_only', 'max_concentration_percent' => '0.01']);
    legalEntryFor($substance, ['annex' => 'III', 'entry_reference' => 'III/2', 'product_scope' => 'see_conditions', 'label_warnings_fr' => 'Éviter le contact avec les yeux.']);

    $result = app(LegalEntryChecker::class)->check($substance, '1', true, legalCheckDate());

    expect($result['binding_breach'])->toBeFalse()
        ->and($result['needs_verification'])->toBeTrue()
        ->and(collect($result['entries'])->pluck('applies')->all())->toBe([false, true])
        ->and($result['label_warnings_fr'])->toBe(['Éviter le contact avec les yeux.']);
});

it('ignores draft and expired entries but reports the drafts', function (): void {
    $substance = Substance::factory()->create();
    $draft = legalEntryFor($substance, ['annex' => 'II'], reviewed: false);
    legalEntryFor($substance, ['annex' => 'II', 'effective_until' => '2025-12-31']);

    $result = app(LegalEntryChecker::class)->check($substance, '1', true, legalCheckDate());

    expect($result['binding_breach'])->toBeFalse()
        ->and($result['entries'])->toBe([])
        ->and($result['unreviewed_entry_ids'])->toBe([$draft->id]);
});

it('decides the label declaration of an allergen from its final concentration', function (string $concentration, bool $mustDeclare): void {
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu']);
    $allergen = Allergen::factory()->create();
    RegulatoryRegimeAllergen::factory()->create([
        'regulatory_regime_id' => $regime->id,
        'allergen_id' => $allergen->id,
        'rinse_off_threshold_percent' => 0.01,
        'leave_on_threshold_percent' => 0.001,
        'threshold_operator' => 'greater_than',
        'is_active' => true,
    ]);

    $declaration = app(LegalEntryChecker::class)->allergenDeclaration($allergen->id, $concentration, true, legalCheckDate());

    expect($declaration['must_declare'])->toBe($mustDeclare);
})->with([
    'above the rinse-off threshold' => ['0.0101', true],
    'exactly at a "greater than" threshold' => ['0.01', false],
]);
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/LegalEntryCheckerTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Dossiers\Calculation;

use App\Enums\LegalAnnex;
use App\Enums\LegalEntryProductScope;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegimeAllergen;
use App\Models\Substance;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;

/**
 * EU annex checks (spec §4.2, decision 13). Binding requirements are automatic: a banned substance present or an
 * exceeded maximum is a breach whatever the other indicators say. Conditions written as text cannot be checked
 * automatically and are flagged for verification.
 */
class LegalEntryChecker
{
    private const REGIME = 'eu';

    /**
     * @return array{entries: list<array<string, mixed>>, binding_breach: bool, needs_verification: bool,
     *               label_warnings_fr: list<string>, unreviewed_entry_ids: list<int>}
     */
    public static function none(): array
    {
        return ['entries' => [], 'binding_breach' => false, 'needs_verification' => false, 'label_warnings_fr' => [], 'unreviewed_entry_ids' => []];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, binding_breach: bool, needs_verification: bool,
     *               label_warnings_fr: list<string>, unreviewed_entry_ids: list<int>}
     */
    public function check(?Substance $substance, string $concentrationPercent, bool $isRinseOff, CarbonImmutable $on): array
    {
        $result = self::none();

        if ($substance === null) {
            return $result;
        }

        $entries = RegulatoryLegalEntry::query()
            ->whereHas('substances', fn ($query) => $query->whereKey($substance->id))
            ->whereHas('regulatoryRegime', fn ($query) => $query->where('code', self::REGIME))
            ->orderBy('annex')
            ->orderBy('entry_reference')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            if (! $entry->isReviewed()) {
                $result['unreviewed_entry_ids'][] = $entry->id;

                continue;
            }

            if (! $this->inForce($entry, $on)) {
                continue;
            }

            $applies = match ($entry->product_scope) {
                LegalEntryProductScope::AllProducts, LegalEntryProductScope::SeeConditions => true,
                LegalEntryProductScope::RinseOffOnly => $isRinseOff,
                LegalEntryProductScope::LeaveOnOnly => ! $isRinseOff,
            };
            $banned = $applies && $entry->annex === LegalAnnex::AnnexII && DecimalMath::isPositive($concentrationPercent);
            $exceeds = $applies && $entry->max_concentration_percent !== null
                && DecimalMath::cmp($concentrationPercent, DecimalMath::of($entry->max_concentration_percent)) > 0;
            $needsVerification = $applies && ($entry->product_scope === LegalEntryProductScope::SeeConditions
                || filled($entry->other_limitations)
                || filled($entry->product_type_body_parts));

            $result['entries'][] = [
                'entry_id' => $entry->id,
                'annex' => $entry->annex->value,
                'entry_reference' => $entry->entry_reference,
                'clause' => $entry->clause,
                'applies' => $applies,
                'max_concentration_percent' => $entry->max_concentration_percent === null ? null : DecimalMath::of($entry->max_concentration_percent),
                'banned' => $banned,
                'exceeds_maximum' => $exceeds,
                'needs_verification' => $needsVerification,
                'other_limitations' => $entry->other_limitations,
                'product_type_body_parts' => $entry->product_type_body_parts,
                'placing_on_market_until' => $entry->placing_on_market_until?->toDateString(),
                'making_available_until' => $entry->making_available_until?->toDateString(),
            ];
            $result['binding_breach'] = $result['binding_breach'] || $banned || $exceeds;
            $result['needs_verification'] = $result['needs_verification'] || $needsVerification;

            if ($applies && filled($entry->label_warnings_fr)) {
                $result['label_warnings_fr'][] = (string) $entry->label_warnings_fr;
            }
        }

        return $result;
    }

    /**
     * Whether the allergen must be declared on the label, from the EU threshold and its operator, against the
     * finished-product concentration (decision 14).
     *
     * @return array{threshold_percent: string, operator: string, must_declare: bool, declaration_label: string|null}|null
     */
    public function allergenDeclaration(int $allergenId, string $concentrationPercent, bool $isRinseOff, CarbonImmutable $on): ?array
    {
        $rule = RegulatoryRegimeAllergen::query()
            ->where('allergen_id', $allergenId)
            ->where('is_active', true)
            ->whereHas('regulatoryRegime', fn ($query) => $query->where('code', self::REGIME))
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $on))
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))
            ->orderBy('id')
            ->first();

        if (! $rule instanceof RegulatoryRegimeAllergen) {
            return null;
        }

        $threshold = DecimalMath::of($isRinseOff ? $rule->rinse_off_threshold_percent : $rule->leave_on_threshold_percent);
        $comparison = DecimalMath::cmp($concentrationPercent, $threshold);

        return [
            'threshold_percent' => $threshold,
            'operator' => (string) $rule->threshold_operator,
            'must_declare' => $rule->threshold_operator === 'greater_than' ? $comparison > 0 : $comparison >= 0,
            'declaration_label' => $rule->declaration_label,
        ];
    }

    private function inForce(RegulatoryLegalEntry $entry, CarbonImmutable $on): bool
    {
        return ($entry->effective_from === null || $entry->effective_from->lte($on))
            && ($entry->effective_until === null || $entry->effective_until->gte($on));
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/LegalEntryCheckerTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/LegalEntryChecker.php tests/Feature/Dossiers/Calculation/LegalEntryCheckerTest.php
git commit -m "Check DIP rows against reviewed EU annex entries and allergen label thresholds"
```

---

### Task 9: Row indicators

**Files:**
- Create: `app/Services/Dossiers/Calculation/RowIndicatorEvaluator.php`
- Modify: `tests/Support/DossierCalculationFixtures.php`
- Test: `tests/Feature/Dossiers/Calculation/RowIndicatorEvaluatorTest.php`

- [ ] **Step 1: Add the parameter fixture**

Add `use App\Models\Qra2Parameter;` to `tests/Support/DossierCalculationFixtures.php` and:

```php
    /**
     * Calculation parameters in the shape AssessmentParameterResolver returns, with the pilot exposure, DAp 100 %,
     * oral bioavailability 50 %, MoS threshold 100, rinse-off.
     *
     * @return array<string, mixed>
     */
    public static function pilotParameters(?Qra2Parameter $qra2 = null): array
    {
        return [
            'exposure' => self::pilotExposure(),
            'policy_dap' => '100',
            'policy_oral_bioavailability' => '50',
            'mos_threshold' => '100',
            'is_rinse_off' => true,
            'qra2' => $qra2,
            'used' => [],
        ];
    }
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Qra2Parameter;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\Calculation\LegalEntryChecker;
use App\Services\Dossiers\Calculation\RowIndicatorEvaluator;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $profile
 * @return array<string, mixed>
 */
function indicatorsFor(string $category, string $concentration, array $profile, ?Qra2Parameter $qra2 = null): array
{
    return app(RowIndicatorEvaluator::class)->evaluate(
        $category,
        $concentration,
        ToxicologyProfile::factory()->create($profile),
        Fixtures::pilotParameters($qra2),
        LegalEntryChecker::none(),
    );
}

it('passes every indicator exactly at its limit and fails just above', function (string $concentration, bool $passes): void {
    // C 0.6 % with DAp 100 % gives SED exactly 0.01; NOAEL 2 (oral) × 50 % gives NOAELsys 1, so MoS is exactly 100.
    $indicators = indicatorsFor('material', $concentration, [
        'noael_mg_kg_bw_day' => '2',
        'noael_route' => 'oral',
        'cmax_percent' => '0.6',
        'adi_mg_kg_bw_day' => '0.01',
        'ttc_mg_kg_bw_day' => '0.01',
    ]);

    expect($indicators['mos']['passes'])->toBe($passes)
        ->and($indicators['cmax']['passes'])->toBe($passes)
        ->and($indicators['adi']['passes'])->toBe($passes)
        ->and($indicators['ttc']['passes'])->toBe($passes);
})->with([
    'at the limits' => ['0.6', true],
    'just above' => ['0.6000001', false],
]);

it('uses policy defaults when the profile has no absorption data', function (): void {
    $indicators = indicatorsFor('material', '7.515827845474833949', [
        'noael_mg_kg_bw_day' => '1180',
        'noael_route' => 'oral',
        'oral_bioavailability_percent' => null,
        'dermal_absorption_percent' => '7.34',
    ]);

    expect($indicators['dermal_absorption_source'])->toBe('profile')
        ->and(DecimalMath::round($indicators['mos']['oral_bioavailability_percent'], 2))->toBe('50.00')
        ->and(DecimalMath::round($indicators['mos']['noael_systemic'], 2))->toBe('590.00')
        ->and(DecimalMath::round($indicators['mos']['value'], 2))->toBe('64169.75');
});

it('explains why a margin of safety is unavailable', function (array $profile, string $reason): void {
    expect(indicatorsFor('material', '1', $profile)['mos'])->toBe(['available' => false, 'reason' => $reason]);
})->with([
    'no NOAEL' => [['noael_mg_kg_bw_day' => null, 'noael_route' => null], 'no_noael'],
    'inhalation study' => [['noael_mg_kg_bw_day' => '10', 'noael_route' => 'inhalation'], 'route_not_convertible'],
    'dermal study' => [['noael_mg_kg_bw_day' => '10', 'noael_route' => 'dermal'], 'dermal_study_absorption_unknown'],
    'zero absorption' => [['noael_mg_kg_bw_day' => '10', 'noael_route' => 'oral', 'dermal_absorption_percent' => '0'], 'no_exposure'],
]);

it('evaluates QRA2 for allergens only, with the preset factor', function (): void {
    $preset = Qra2Parameter::factory()->create();
    $profile = ['noael_mg_kg_bw_day' => '150', 'noael_route' => 'oral', 'nesil_ug_cm2' => '10000', 'sensitisation_note' => 'Skin sensitiser.', 'cmr_note' => 'Not CMR.'];

    $allergen = indicatorsFor('allergen', '0.922804308977693538', $profile, $preset);
    $withoutPreset = indicatorsFor('allergen', '0.922804308977693538', $profile);

    expect(DecimalMath::round($allergen['qra2']['cmax_percent'], 6))->toBe('8.333333')
        ->and($allergen['qra2']['passes'])->toBeTrue()
        ->and(DecimalMath::round($allergen['sed_mg_kg_bw_day'], 9))->toBe('0.015380072')
        ->and(DecimalMath::round($allergen['mos']['value'], 2))->toBe('4876.44')
        ->and($allergen['hazard_endpoints'])->toBe(['sensitisation', 'cmr'])
        ->and($withoutPreset['qra2'])->toBe(['available' => false, 'reason' => 'no_qra2_preset'])
        ->and(indicatorsFor('material', '1', $profile, $preset)['qra2'])->toBeNull();
});

it('passes QRA2 exactly at its maximum and fails just above', function (string $concentration, bool $passes): void {
    // NESIL 3000 with SAF 300, CEL 0.2 and factor 0.5 gives a QRA2 maximum of exactly 2.5 %.
    $indicators = indicatorsFor('allergen', $concentration, ['nesil_ug_cm2' => '3000'], Qra2Parameter::factory()->create());

    expect(DecimalMath::round($indicators['qra2']['cmax_percent'], 6))->toBe('2.500000')
        ->and($indicators['qra2']['passes'])->toBe($passes);
})->with([
    'at the maximum' => ['2.5', true],
    'just above' => ['2.5000001', false],
]);
```

- [ ] **Step 3: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/RowIndicatorEvaluatorTest.php`
Expected: FAIL, class not found.

- [ ] **Step 4: Implement**

```php
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
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/RowIndicatorEvaluatorTest.php`
Expected: PASS (10 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/RowIndicatorEvaluator.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/RowIndicatorEvaluatorTest.php
git commit -m "Evaluate every DIP row indicator, QRA2 for allergens"
```

---

### Task 10: Suggested routes and row states

**Files:**
- Create: `app/Enums/DossierRowState.php`, `app/Services/Dossiers/Calculation/RowStateResolver.php`
- Test: `tests/Feature/Dossiers/Calculation/RowStateResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/RowStateResolverTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

`app/Enums/DossierRowState.php`:

```php
<?php

namespace App\Enums;

/**
 * State of one DIP Part A row (spec §4.2). `route_suggere` prints as « À justifier par l'évaluateur ».
 */
enum DossierRowState: string
{
    case NonConforme = 'non_conforme';
    case RouteSuggere = 'route_suggere';
    case Conforme = 'conforme';
    case AJustifier = 'a_justifier';
}
```

`app/Services/Dossiers/Calculation/RowStateResolver.php`:

```php
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
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/RowStateResolverTest.php`
Expected: PASS (16 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/DossierRowState.php app/Services/Dossiers/Calculation/RowStateResolver.php tests/Feature/Dossiers/Calculation/RowStateResolverTest.php
git commit -m "Resolve DIP row states from confirmed routes and indicators"
```

---
### Task 11: Identity and profile of each assessed material

**Files:**
- Create: `app/Services/Dossiers/Calculation/AssessedMaterialResolver.php`
- Modify: `tests/Support/DossierCalculationFixtures.php`
- Test: `tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php`

- [ ] **Step 1: Add the profile and coverage fixtures**

Add these imports to `tests/Support/DossierCalculationFixtures.php`: `use App\Enums\AssessmentReviewState;`, `use App\Models\MaterialAssessmentCoverage;`, `use App\Models\Substance;`, `use App\Models\ToxicologyProfile;`, and:

```php
    /**
     * An assessed material with a current toxicological profile.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $attributes
     */
    public static function substance(string $name, ?string $inci, array $profile = [], array $attributes = []): Substance
    {
        $substance = Substance::factory()->create(['name' => $name, 'inci_name' => $inci, ...$attributes]);
        ToxicologyProfile::factory()->create(['substance_id' => $substance->id, ...$profile]);

        return $substance;
    }

    /**
     * A sufficient review of a material, resting on the current profile of each reference.
     */
    public static function cover(Ingredient|Substance $material, Substance ...$references): MaterialAssessmentCoverage
    {
        $coverage = MaterialAssessmentCoverage::factory()->create([
            'coverable_type' => $material::class,
            'coverable_id' => $material->getKey(),
            'workspace_id' => $material instanceof Ingredient ? $material->workspace_id : null,
            'review_state' => AssessmentReviewState::Sufficient,
            'covers_whole_material' => true,
            'covers_constituents' => true,
        ]);

        foreach ($references as $reference) {
            $coverage->supportingSubstances()->attach($reference->id, [
                'toxicology_profile_id' => ToxicologyProfile::query()->current()->where('substance_id', $reference->id)->value('id'),
            ]);
        }

        return $coverage;
    }

    /**
     * Marks a substance's profiles as superseded, as a publisher would leave them, without the model's append-only guard.
     */
    public static function supersedeProfiles(Substance $substance): void
    {
        ToxicologyProfile::query()->where('substance_id', $substance->id)->update(['is_current' => false, 'superseded_at' => now()]);
    }
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\IngredientCategory;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientComponent;
use App\Models\Substance;
use App\Models\ToxicologyProfile;
use App\Services\Dossiers\Calculation\AssessedMaterialResolver;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @return array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, percent: string}
 */
function identityFinalRow(string $key, string $kind, ?Ingredient $ingredient, ?string $inci, string $percent): array
{
    return ['key' => $key, 'kind' => $kind, 'ingredient_id' => $ingredient?->id, 'name' => $ingredient?->display_name ?? (string) $inci, 'inci_name' => $inci, 'percent' => $percent];
}

it('identifies a soap salt by its INCI name', function (): void {
    $olivate = Fixtures::substance('Sodium olivate', 'Sodium Olivate');

    $contributions = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow('soap_salt:SODIUM OLIVATE', 'soap_salt', null, 'SODIUM OLIVATE', '75.3'));

    expect($contributions)->toHaveCount(1)
        ->and($contributions[0]['substance']->is($olivate))->toBeTrue()
        ->and($contributions[0]['ingredient'])->toBeNull()
        ->and($contributions[0]['unidentified_reason'])->toBeNull();
});

it('reports a final material missing from the catalogue or ambiguous', function (int $matches, string $reason): void {
    Substance::factory()->count($matches)->create(['inci_name' => 'SODIUM COCOATE']);

    $contributions = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow('soap_salt:SODIUM COCOATE', 'soap_salt', null, 'SODIUM COCOATE', '10'));

    expect($contributions[0]['substance'])->toBeNull()
        ->and($contributions[0]['unidentified_reason'])->toBe($reason);
})->with([
    'not in the catalogue' => [0, 'not_in_catalogue'],
    'two catalogue rows' => [2, 'ambiguous_inci'],
]);

it('identifies a carried ingredient by its INCI and keeps the ingredient for its coverage', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $oil = Fixtures::substance('Lavandula angustifolia oil', 'LAVANDULA ANGUSTIFOLIA OIL');

    $contribution = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow("carried:ingredient:{$lavender->id}", 'carried', $lavender, 'LAVANDULA ANGUSTIFOLIA OIL', '2.3'))[0];

    expect($contribution['substance']->is($oil))->toBeTrue()
        ->and($contribution['ingredient']->is($lavender))->toBeTrue();
});

it('reads a blend through its components', function (): void {
    $blend = Fixtures::ingredient('Blend', 'PARFUM', IngredientCategory::AromaticMaterials);
    $component = Fixtures::ingredient('Orange Oil', 'CITRUS AURANTIUM DULCIS PEEL OIL', IngredientCategory::AromaticMaterials);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $component->id, 'percentage_in_parent' => 40]);

    $contributions = app(AssessedMaterialResolver::class)->finalRowContributions(identityFinalRow("carried:ingredient:{$blend->id}", 'carried', $blend, 'PARFUM', '2'));

    expect($contributions)->toHaveCount(1)
        ->and($contributions[0]['source_key'])->toBe("carried:ingredient:{$blend->id}|component:{$component->id}")
        ->and(DecimalMath::round($contributions[0]['concentration_percent'], 6))->toBe('0.800000')
        ->and($contributions[0]['ingredient']->is($component))->toBeTrue();
});

it('uses the identity profile first and a single coverage reference as recorded read-across', function (): void {
    $olivate = Fixtures::substance('Sodium olivate', 'SODIUM OLIVATE');
    $reference = Fixtures::substance('Olive fatty acid soaps', null);
    Fixtures::cover($olivate, $reference);
    $resolver = app(AssessedMaterialResolver::class);

    $own = $resolver->profileFor($olivate, [$olivate]);
    Fixtures::supersedeProfiles($olivate);
    $readAcross = $resolver->profileFor($olivate, [$olivate]);

    expect($own['basis'])->toBe('own')
        ->and($own['profile']->substance_id)->toBe($olivate->id)
        ->and($readAcross['basis'])->toBe('reference')
        ->and($readAcross['reference_substance_id'])->toBe($reference->id)
        ->and($readAcross['profile']->substance_id)->toBe($reference->id);
});

it('refuses to choose between several references and ignores tracked constituents', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $first = Fixtures::substance('Lavender oil, profile A', null);
    $second = Fixtures::substance('Lavender oil, profile B', null);
    $limonene = Fixtures::substance('Limonene', 'LIMONENE', attributes: ['allergen_id' => Allergen::factory()->create()->id]);
    Fixtures::cover($lavender, $first, $limonene);
    $resolver = app(AssessedMaterialResolver::class);

    expect($resolver->profileFor(null, [$lavender])['reference_substance_id'])->toBe($first->id);

    $otherLavender = Fixtures::ingredient('Lavender Oil, second supplier grade', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    Fixtures::cover($otherLavender, $second);
    $both = $resolver->profileFor(null, [$lavender, $otherLavender]);

    expect($both['profile'])->toBeNull()
        ->and($both['reason'])->toBe('ambiguous_reference');
});

it('flags a review that relied on a superseded profile and finds an allergen through its catalogue link', function (): void {
    $allergen = Allergen::factory()->create();
    $limonene = Fixtures::substance('Limonene', 'LIMONENE', attributes: ['allergen_id' => $allergen->id]);
    Fixtures::cover($limonene, $limonene);
    Fixtures::supersedeProfiles($limonene);
    ToxicologyProfile::factory()->create(['substance_id' => $limonene->id, 'version' => 2]);
    $resolver = app(AssessedMaterialResolver::class);

    expect($resolver->coverageSummary($limonene)['relies_on_superseded_profiles'])->toBeTrue()
        ->and($resolver->substanceForOverlay(['allergen_id' => $allergen->id, 'substance_id' => null])->is($limonene))->toBeTrue()
        ->and($resolver->profileFor($limonene, [$limonene])['profile']->version)->toBe(2);
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php`
Expected: FAIL, class not found.

- [ ] **Step 4: Implement**

```php
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
            ->whereNotNull('component_ingredient_id')
            ->with(['componentIngredient' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (IngredientComponent $component): bool => $component->componentIngredient instanceof Ingredient);

        if ($components->isNotEmpty() && $depth < self::MAX_COMPONENT_DEPTH) {
            return $components
                ->flatMap(fn (IngredientComponent $component): array => $this->ingredientContributions(
                    "{$sourceKey}|component:{$component->component_ingredient_id}",
                    $finalRow,
                    $component->componentIngredient,
                    DecimalMath::div(DecimalMath::mul($percent, DecimalMath::of($component->percentage_in_parent)), '100'),
                    $depth + 1,
                ))
                ->values()
                ->all();
        }

        [$substance, $reason] = $this->substanceByInci($ingredient->inci_name);

        return [$this->contribution($sourceKey, $finalRow, (string) $ingredient->display_name, $percent, $ingredient, $substance, $reason)];
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
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php`
Expected: PASS (8 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/AssessedMaterialResolver.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php
git commit -m "Separate the legal identity of each DIP material from the profile it is assessed with"
```

---

### Task 12: The assessment engine

**Files:**
- Create: `app/Services/Dossiers/Calculation/DossierAssessmentEngine.php`
- Modify: `tests/Support/DossierCalculationFixtures.php`
- Test: `tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php`

- [ ] **Step 1: Add the pilot reference data**

Add these imports to `tests/Support/DossierCalculationFixtures.php`: `use App\Enums\ConstituentKind;`, `use App\Enums\EvidenceType;`, `use App\Models\Allergen;`, `use App\Models\AssessmentPolicy;`, `use App\Models\DossierEvidenceConstituent;`, `use App\Models\DossierEvidenceItem;`, `use App\Models\ExposurePreset;`, `use App\Models\SupplierQualification;`, and:

```php
    /**
     * The olive soap with 3 % lavender oil and everything the engine needs to assess it: policy (DAp 100 %, oral
     * bioavailability 50 %, MoS 100, bw 60 kg), the toilet-soap preset for its product type, profiles and sufficient
     * reviews for every initial and final material, and a validated lavender supplier declaring 40 % limonene.
     * Extra 'fragrance' and 'additives' rows come through $phaseItems.
     *
     * @param  array<string, list<array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}>>  $phaseItems
     * @return array<string, mixed>
     */
    public static function assessedLavenderSoap(array $phaseItems = []): array
    {
        $lavender = self::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
        $soap = self::oliveSoap([
            'fragrance' => [self::row($lavender, 3, 30), ...($phaseItems['fragrance'] ?? [])],
            'additives' => $phaseItems['additives'] ?? [],
        ]);
        AssessmentPolicy::factory()->create();
        ExposurePreset::factory()->create(['product_type_id' => $soap['recipe']->product_type_id]);

        $olivate = self::substance('Sodium olivate', 'SODIUM OLIVATE', ['noael_mg_kg_bw_day' => '1000']);
        $glycerin = self::substance('Glycerin', 'GLYCERIN', ['noael_mg_kg_bw_day' => '1180', 'dermal_absorption_percent' => '7.34']);
        $aqua = self::substance('Water', 'AQUA', ['noael_mg_kg_bw_day' => null, 'noael_route' => null, 'cmax_percent' => '100']);
        $oliveOil = self::substance('Olea europaea fruit oil', 'OLEA EUROPAEA FRUIT OIL', ['noael_mg_kg_bw_day' => '2000']);
        $lavenderOil = self::substance('Lavandula angustifolia oil', 'LAVANDULA ANGUSTIFOLIA OIL', ['noael_mg_kg_bw_day' => '200', 'sensitisation_note' => 'Contains fragrance allergens; see QRA2.']);
        $limoneneAllergen = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
        $limonene = self::substance('Limonene', 'LIMONENE', ['noael_mg_kg_bw_day' => '150', 'nesil_ug_cm2' => '10000', 'sensitisation_note' => 'Skin sensitiser (oxidised).'], ['allergen_id' => $limoneneAllergen->id]);

        $coverages = [];

        foreach (compact('olivate', 'glycerin', 'aqua') as $name => $finalMaterial) {
            $coverages[$name] = self::cover($finalMaterial, $finalMaterial);
        }

        $coverages['olive'] = self::cover($soap['olive'], $oliveOil);
        $coverages['lavender'] = self::cover($lavender, $lavenderOil, $limonene);

        $qualification = SupplierQualification::factory()->create(['workspace_id' => $soap['workspace']->id, 'ingredient_id' => $lavender->id]);
        $declaration = DossierEvidenceItem::factory()->create([
            'supplier_qualification_id' => $qualification->id,
            'type' => EvidenceType::AllergenDeclaration,
            'constituents_complete' => true,
        ]);
        DossierEvidenceConstituent::factory()->create([
            'dossier_evidence_item_id' => $declaration->id,
            'kind' => ConstituentKind::Allergen,
            'allergen_id' => $limoneneAllergen->id,
            'max_value' => '40',
        ]);

        return [
            ...$soap,
            'lavender' => $lavender,
            'substances' => compact('olivate', 'glycerin', 'aqua', 'oliveOil', 'lavenderOil', 'limonene'),
            'limoneneAllergen' => $limoneneAllergen,
            'coverages' => $coverages,
        ];
    }
```

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Data\DossierAssessmentOptions;
use App\Enums\IngredientCategory;
use App\Enums\WorkspaceMemberRole;
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\IngredientAllergenEntry;
use App\Models\Qra2Parameter;
use App\Models\RecipeVersion;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Substance;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
use App\Models\WorkspaceMember;
use App\Services\Dossiers\Calculation\DossierAssessmentEngine;
use App\Support\DecimalMath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DossierCalculationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $soap
 * @return array<string, mixed>
 */
function assessSoap(array $soap, ?DossierAssessmentOptions $options = null): array
{
    return app(DossierAssessmentEngine::class)->assess($soap['owner'], $soap['workspace'], $soap['version'], $options ?? new DossierAssessmentOptions);
}

/**
 * @param  array<string, mixed>  $assessment
 * @return array<string, mixed>
 */
function assessedRow(array $assessment, string $key): array
{
    return collect($assessment['rows'])->firstWhere('key', $key) ?? throw new RuntimeException("No row [{$key}].");
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reviewedEuEntry(Substance $substance, array $attributes): RegulatoryLegalEntry
{
    $regime = RegulatoryRegime::query()->firstWhere('code', 'eu') ?? RegulatoryRegime::factory()->create(['code' => 'eu']);
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create(['regulatory_regime_id' => $regime->id, 'annex' => 'III', ...$attributes]);
    $entry->substances()->attach($substance->id);

    return $entry;
}

it('assesses the lavender soap end to end against the hand-calculated values', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $substances = $soap['substances'];

    $assessment = assessSoap($soap);
    $limonene = assessedRow($assessment, 'substance:'.$substances['limonene']->id);
    $glycerin = assessedRow($assessment, 'substance:'.$substances['glycerin']->id);

    expect($assessment['status'])->toBe('calculated')
        ->and($assessment['blockers'])->toBe([])
        ->and($assessment['can_submit'])->toBeTrue()
        ->and($assessment['can_approve'])->toBeFalse()
        ->and($assessment['composition']['tracing']['passes'])->toBeTrue()
        ->and($limonene['category'])->toBe('allergen')
        ->and(DecimalMath::round($limonene['concentration_percent'], 6))->toBe('0.922804')
        ->and(DecimalMath::round($limonene['indicators']['sed_mg_kg_bw_day'], 9))->toBe('0.015380072')
        ->and(DecimalMath::round($limonene['indicators']['mos']['value'], 2))->toBe('4876.44')
        ->and($limonene['indicators']['qra2'])->toBe(['available' => false, 'reason' => 'no_qra2_preset'])
        ->and($limonene['state'])->toBe('route_suggere')
        ->and($limonene['suggested_route'])->toBe('mos')
        ->and($limonene['needs_justification'])->toBeFalse()
        ->and(DecimalMath::round($glycerin['indicators']['mos']['value'], 2))->toBe('64169.75')
        ->and($glycerin['profile']['basis'])->toBe('own')
        ->and(assessedRow($assessment, 'substance:'.$substances['aqua']->id)['suggested_route'])->toBe('cmax')
        ->and(assessedRow($assessment, 'substance:'.$substances['lavenderOil']->id)['indicators']['hazard_endpoints'])->toBe(['sensitisation'])
        ->and(collect($assessment['warnings'])->pluck('code')->all())->toContain('final_composition_estimated', 'no_qra2_preset', 'suppliers_without_declaration');
});

it('concludes rows only through confirmed routes, and an allergen only with QRA2', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $glycerinKey = 'substance:'.$soap['substances']['glycerin']->id;
    $limoneneKey = 'substance:'.$soap['substances']['limonene']->id;
    $options = new DossierAssessmentOptions(confirmations: [
        $glycerinKey => ['route' => 'mos', 'justification' => 'Oral NOAEL, SCCS default bioavailability.'],
        $limoneneKey => ['route' => 'mos', 'justification' => 'Systemic NOAEL; sensitisation through QRA2.', 'endpoints_addressed' => true],
    ]);

    $withoutQra2 = assessSoap($soap, $options);

    $category = IfraProductCategory::factory()->create();
    $amendment = IfraAmendment::factory()->create();
    Qra2Parameter::factory()->create(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    RecipeVersion::withoutGlobalScopes()->whereKey($soap['version']->id)->update(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    $soap['version'] = $soap['version']->fresh();
    $withQra2 = assessSoap($soap, $options);

    expect(assessedRow($withoutQra2, $glycerinKey)['state'])->toBe('conforme')
        ->and(assessedRow($withoutQra2, $limoneneKey)['state'])->toBe('a_justifier')
        ->and(assessedRow($withoutQra2, $limoneneKey)['state_reason'])->toBe('qra2_required')
        ->and(DecimalMath::round(assessedRow($withQra2, $limoneneKey)['indicators']['qra2']['cmax_percent'], 6))->toBe('8.333333')
        ->and(assessedRow($withQra2, $limoneneKey)['state'])->toBe('conforme');
});

it('records a justified override in the parameters used', function (): void {
    $soap = Fixtures::assessedLavenderSoap();

    $assessment = assessSoap($soap, new DossierAssessmentOptions(overrides: [
        'amount_per_use_g' => ['value' => '2', 'justification' => 'Large bar: weighed use of 2 g.'],
    ]));
    $limonene = assessedRow($assessment, 'substance:'.$soap['substances']['limonene']->id);

    expect(DecimalMath::round($limonene['indicators']['sed_mg_kg_bw_day'], 9))->toBe('0.030760144')
        ->and(collect($assessment['parameters'])->firstWhere('key', 'amount_per_use_g')['source'])->toBe('override')
        ->and(collect($assessment['parameters'])->firstWhere('key', 'residual_water_percent')['source'])->toBe('formula_estimate');
});

it('checks legal limits on the actual substance even when it is assessed through a read-across profile', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $olivate = $soap['substances']['olivate'];
    $readAcross = Fixtures::substance('Olive fatty acid soaps', null, ['noael_mg_kg_bw_day' => '5000']);
    Fixtures::supersedeProfiles($olivate);
    $soap['coverages']['olivate']->delete();
    Fixtures::cover($olivate, $readAcross);
    reviewedEuEntry($olivate, ['max_concentration_percent' => '50']);
    $key = 'substance:'.$olivate->id;

    $row = assessedRow(assessSoap($soap, new DossierAssessmentOptions(confirmations: [
        $key => ['route' => 'mos', 'justification' => 'Read-across from olive fatty acid soaps.'],
    ])), $key);

    expect($row['profile']['basis'])->toBe('reference')
        ->and($row['profile']['substance_id'])->toBe($readAcross->id)
        ->and($row['indicators']['mos']['passes'])->toBeTrue()
        ->and($row['state'])->toBe('non_conforme')
        ->and($row['state_reason'])->toBe('binding_requirement');
});

it('sums reaction glycerin and added glycerin before checking a limit', function (): void {
    $glycerinIngredient = Fixtures::ingredient('Vegetable Glycerin', 'GLYCERIN', IngredientCategory::HumectantsPolyols);
    $soap = Fixtures::assessedLavenderSoap(['additives' => [Fixtures::row($glycerinIngredient, 2, 20)]]);
    reviewedEuEntry($soap['substances']['glycerin'], ['max_concentration_percent' => '8']);

    $row = assessedRow(assessSoap($soap), 'substance:'.$soap['substances']['glycerin']->id);
    $parts = collect($row['contributions'])->map(fn (array $part): string => DecimalMath::round($part['concentration_percent'], 6))->sort()->values()->all();

    expect(DecimalMath::round($row['concentration_percent'], 6))->toBe('8.900034')
        ->and($parts)->toBe(['1.511880', '7.388153'])
        ->and($row['state'])->toBe('non_conforme');
});

it('sums a neat allergen with the same allergen declared in another ingredient, without counting the neat one twice', function (): void {
    $neatLimonene = Fixtures::ingredient('d-Limonene', 'LIMONENE', IngredientCategory::AromaticMaterials);
    $soap = Fixtures::assessedLavenderSoap(['fragrance' => [Fixtures::row($neatLimonene, 0.6, 6)]]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $neatLimonene->id, 'allergen_id' => $soap['limoneneAllergen']->id, 'concentration_percent' => 100]);
    reviewedEuEntry($soap['substances']['limonene'], ['max_concentration_percent' => '1']);

    $row = assessedRow(assessSoap($soap), 'substance:'.$soap['substances']['limonene']->id);

    expect(DecimalMath::round($row['concentration_percent'], 6))->toBe('1.377067')
        ->and($row['contributions'])->toHaveCount(2)
        ->and($row['category'])->toBe('allergen')
        ->and($row['state'])->toBe('non_conforme');
});

it('blocks submission for an unreviewed or unidentified final material', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $soap['coverages']['glycerin']->delete();
    $soap['substances']['olivate']->update(['inci_name' => 'SODIUM OLIVATE (RENAMED)']);

    $assessment = assessSoap($soap);

    expect($assessment['can_submit'])->toBeFalse()
        ->and(collect($assessment['blockers'])->map(fn (array $blocker): string => $blocker['code'].':'.($blocker['key'] ?? ''))->all())
        ->toContain('material_not_reviewed:glycerin', 'final_material_unidentified:soap_salt:SODIUM OLIVATE');
});

it('blocks submission for an initial ingredient whose review is insufficient', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $soap['coverages']['lavender']->forceFill(['review_state' => 'insufficient', 'insufficient_reason' => 'Grade not equivalent to the profiled oil.'])->save();

    $codes = collect(assessSoap($soap)['blockers'])->map(fn (array $blocker): string => $blocker['code'].':'.($blocker['scope'] ?? ''));

    expect($codes->all())->toContain('material_insufficient:initial');
});

it('keeps a non-conforming row submittable but not approvable', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    reviewedEuEntry($soap['substances']['limonene'], ['max_concentration_percent' => '0.5']);

    $assessment = assessSoap($soap);

    expect(assessedRow($assessment, 'substance:'.$soap['substances']['limonene']->id)['state'])->toBe('non_conforme')
        ->and($assessment['can_submit'])->toBeTrue()
        ->and($assessment['can_approve'])->toBeFalse();
});

it('reports a calculation that cannot run instead of failing', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $soap['version'] = $soap['workingCopy'];

    $assessment = assessSoap($soap);

    expect($assessment['status'])->toBe('cannot_run')
        ->and($assessment['reason'])->toBe('working_copy')
        ->and($assessment['can_submit'])->toBeFalse()
        ->and($assessment['blockers'][0]['code'])->toBe('calculation_cannot_run');
});

it('refuses editors, revoked workspaces and formulas from another workspace', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $editor = User::factory()->create();
    WorkspaceMember::factory()->for($soap['workspace'])->for($editor)->create(['role' => WorkspaceMemberRole::Editor]);
    $other = Workspace::factory()->for($soap['owner'], 'owner')->create();
    WorkspaceDossierGrant::factory()->for($other)->create();
    $engine = app(DossierAssessmentEngine::class);

    expect(fn () => $engine->assess($editor, $soap['workspace'], $soap['version']))->toThrow(AuthorizationException::class)
        ->and(fn () => $engine->assess($soap['owner'], $other, $soap['version']))->toThrow(AuthorizationException::class);

    WorkspaceDossierGrant::query()->where('workspace_id', $soap['workspace']->id)->update(['revoked_at' => now()]);

    expect(fn () => assessSoap($soap))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 3: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php`
Expected: FAIL, class `DossierAssessmentEngine` not found.

- [ ] **Step 4: Implement**

```php
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
        $on = $options->evaluatedOn ?? CarbonImmutable::today();

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
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php`
Expected: PASS (11 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/DossierAssessmentEngine.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php
git commit -m "Assess a saved soap formula end to end for the DIP, one row per substance"
```

---

### Task 13: Verification and spec amendments

**Files:**
- Modify: `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md`

- [ ] **Step 1: Run every DIP test, then the full suite**

```bash
export LANG=en_US.UTF-8
php artisan test --compact tests/Feature/Dossiers
php artisan test --compact --parallel
```

Expected: all pass. A failure outside `tests/Feature/Dossiers` means a shared service changed behaviour; this plan changes no shared service except `SupplierConstituentResolver` (one added method).

- [ ] **Step 2: Check the reference layers are independent**

Confirm, by reading the tests, that every expected number in Tasks 2–12 comes from the "Reference values" tables of this plan (hand calculation, reproduced independently by Codex) and none was copied from a test run. Layer 3 of spec §12 (the assessor-approved pilot) comes after phase 4.

- [ ] **Step 3: Amend the spec where the pilot narrows it**

In `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md`:

1. Replace the line starting with `**Status:**` with:

```markdown
**Status:** design agreed with Philippe on 2026-10-21 after a Codex second-opinion review. Phases 1, 2a, 2b and 3 (calculation engine, read-only) are implemented on `codex/dip-pilot`; phase 2c (folder import) and phase 4 (dossiers, signing, archive) remain.
```

2. At the end of §4.1, add:

```markdown
- **Pilot limits (phase 3, after Codex's plan review):** quantities come from the saved weights; only triglyceride oils, butters and animal fats react (fatty acids and waxes need another reaction model); lye-water substitutes and KOH below 100 % purity are refused until their water or residue composition is evidenced; only the lye water evaporates. Each refusal is a named "calculation cannot run" reason.
```

3. In §4.2, replace the line starting with `` - `NOAELsys = `` with:

```markdown
- `NOAELsys = NOAEL × oral bioavailability` for an oral study. A dermal NOAEL is not converted automatically in the pilot: the absorption of the study itself is not recorded, and reusing the product DAp would cancel out of the MoS (open question 7). An inhalation NOAEL is not converted.
```

4. In §4.2, after the line starting with `- **Annex checks:**`, add:

```markdown
- **One row per substance:** contributions of the same substance from every source — a neat ingredient, a constituent declared in another ingredient, reaction glycerin and added glycerin — are summed before any limit or indicator is evaluated. A row's legal identity is the substance itself; a read-across profile from its coverage never replaces that identity. An annex condition that cannot be checked automatically is recorded as met or not met; not met makes the row `non_conforme`.
```

5. In §5, replace `- any initial **or final** material whose review state is` with:

```markdown
  - any initial recipe ingredient **or final** material whose review state is
```

   and add after that list item:

```markdown
    Initial alkali and lye water are transformed materials: they are assessed through what they become (soap salts, residual water) and have no finished-product row of their own.
```

6. In §11, add:

```markdown
7. **Dermal NOAEL conversion:** whether and how a dermal-study NOAEL is converted to a systemic value (it needs the study's own absorption) — to agree with the assessor; the pilot leaves such rows to another route.
```

- [ ] **Step 4: Pint, graph, commit**

```bash
vendor/bin/pint --dirty --format agent
graphify update .
git add docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md
git commit -m "Record DIP phase 3 and its pilot limits in the spec"
```

---

## Self-review notes (for the reviewer of this plan)

- **Spec §4.1 coverage:** initial composition from saved weights and final composition with mass balance (Task 5), ingredient tracing with a bounded tolerance and the column-J regression (Task 4), measured cure loss from water only (Task 5), overlays on both compositions (Task 6). Supplier set, CAS and function per initial row are left to phase 4's Part A table, which reads the validated qualifications directly.
- **Spec §4.2 coverage:** SED, oral NOAELsys, MoS (Task 2); all indicators shown, QRA2 at and above its limit (Task 9); one row per substance with summed contributions and legal checks on the actual identity (Tasks 11, 12); binding requirements automatic, including a condition recorded as not met (Tasks 8, 10); the four states (Task 10); impurities in ppm and µg (Tasks 2, 6, 9); hazard endpoints never settled by a large MoS (Tasks 9, 10).
- **Spec §12 coverage:** layer 1 arithmetic fixtures (Tasks 2, 3, 5, 12); layer 2 boundary and failure cases (Tasks 4, 5, 7, 9, 10); row-state, coverage, identity, summing and gate tests (Tasks 10, 11, 12); authorization including Editor denial (Task 12). Snapshot, out-of-date and signing tests belong to phase 4.
- **Not in this phase:** storage of confirmations and overrides, Part B text, the checklist, print view — phase 4. Leave-on and liquid products, other reaction models, lye substitutes, KOH 90 % — after the pilot.

## Review changes (2026-10-23, Codex plan review)

| Finding | Change |
| --- | --- |
| P1 · a supporting profile replaced the material's identity, so annex checks ran on the wrong substance | Identity and profile separated (decision 6, Task 11); annex checks always on the identity; test with a read-across profile and an exceeded limit on the actual substance (Task 12) |
| P1 · separate material and constituent rows could each pass a maximum their total exceeds | One row per substance summing every contribution, neat materials not counted twice (decision 7, Task 12); tests for reaction + added glycerin and neat + declared limonene |
| P1 · quantities rebuilt from rounded percentages changed or dropped ingredients | Saved weights first, as the preview does (decision 2, Task 5); weight-mode test with 1.2345 g at 0.0123 % and 0.001 g at 0 % |
| P1 · negative retention or zero body weight accepted | Physical bounds on every override and stored value (decision 11, Task 7); 16 refusal cases and 4 accepted limits |
| P1 · non-water lye substitutes evaporated as if water | Lye substitutes refused in the pilot; only water evaporates; measured loss taken from water only (decisions 1, 3, Task 5) |
| P1 · triglyceride stoichiometry applied to fatty acids and waxes | Reacting materials restricted to vegetable oils, butters and animal fats (decision 1, Task 5) |
| P2 · KOH 90 % residue left permanently unidentified | KOH below 100 % refused in the pilot (decision 1, Task 5) |
| P2 · column-J regression passed with a 4 g tolerance | Tolerance capped at 0.01 g; larger values refused; test at the cap and for 4 g (decision 5, Task 4) |
| P2 · nullable dataset value on a non-nullable parameter | Parameter made nullable (Task 4) |
| Note · dermal NOAEL reused the product DAp, cancelling out of the MoS | Dermal NOAEL not converted in the pilot; spec open question 7 (decision 10, Tasks 2, 9, 13) |
| Note · a failed annex condition was not distinct from an unverified one | `annex_conditions: met / not_met`; not met → `non_conforme` (decision 12, Task 10) |
| Note · spec §5 says any initial material, decision 15 waives alkali and water | Spec §5 amended to name transformed materials (Task 13) |
| Note · extra tests | QRA2 at and above the limit (Task 9); negative superfat, excess alkali and KOH 90 % (Task 5); dual lye with KOH 100 % (Task 5); insufficient initial coverage and Editor denial (Task 12) |
