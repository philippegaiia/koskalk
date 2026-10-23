# DIP Phase 3 — Calculation Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Compute, for a saved bar-soap formula, everything Part A of the DIP needs: both compositions with a mass balance and an ingredient-tracing ledger, allergen/impurity/restricted-substance overlays, exposure (SED), systemic NOAEL, MoS and the other indicators, QRA2 with the preset's adjustment factor, EU annex checks, suggested routes and row states — at full precision, with every input recorded, and with the blockers and warnings phase 4 will show and snapshot.

**Architecture:** Pure calculators (`ExposureCalculator`, `Qra2Calculator`, `IngredientTracingVerifier`, `RowStateResolver`) do the arithmetic and the state rules on decimal strings and are tested against hand-calculated values. Builders read the data: `DossierCompositionBuilder` turns a saved formula's workbench snapshot into the initial composition, the final (cured) composition and the ledger, reusing `SoapCalculationService::calculateOilReactionProducts`; `ConstituentOverlayBuilder` sums the supplier worst cases from `SupplierConstituentResolver`; `AssessedMaterialResolver`, `AssessmentParameterResolver` and `LegalEntryChecker` load profiles, coverage, parameters and annex entries. `DossierAssessmentEngine` orchestrates them and returns one array. **Read-only:** no tables, no migrations, no screens. Route confirmations and dossier overrides are engine *inputs*; phase 4 stores them on dossier versions.

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

1. **Pilot scope of the engine: bar soap only, superfat ≥ 0.** A cosmetic family (`calculation_basis = total_formula`), a liquid-soap family, a negative superfat or any excess alkali makes the calculation **not run** with a named reason (`unsupported_family`, `liquid_soap_unsupported`, `negative_superfat`, `excess_alkali`). They are spec blockers ("a calculation that cannot run"); leave-on and liquid products come after the pilot (spec §10, phase 5). The engine also refuses the working copy (`working_copy`): a dossier pins a saved formula.
2. **Final composition model** (matches the label's cured output, verified on a probe formula: 1000 g olive oil, 3 % lavender → cured 1300.384 g, sodium olivate 979.6072 g, glycerin 97.7346 g):
   - each oil → its soap salt(s), glycerin and the unsaponified share, from `calculateOilReactionProducts` with the formula's own superfat, lye type, dual-lye share and KOH purity;
   - NaOH and active KOH → the soap salts; the **non-active share of KOH 90 %** stays in the bar as its own final row (« Part non active de l'hydroxyde de potassium », no assessed material, so it shows as unidentified until an admin adds one);
   - the lye liquid (water plus any `lye_water` substitutes such as a hydrosol) keeps the residual share and loses the rest by evaporation; the residual is split across the liquid rows by their fresh share, as `LyeLiquidAllocationService` does for labels;
   - cured mass = non-liquid mass ÷ (1 − residual %), residual % from the formula (`curedWaterPercentage`) or a justified override; with a **measured cure loss** (% of the initial batch) the lost mass replaces the estimate, and nothing else is rescaled (spec §4.1);
   - every other phase is carried at unchanged mass.
3. **Ledger convention.** Contributions are recorded in grams before normalisation: oil → glycerin (the glycerol backbone), oil → each soap salt (salt − its reacting alkali), alkali → each soap salt, oil → unsaponified share, liquid → residual, liquid → evaporated (a loss), carried ingredient → itself, KOH non-active share → its own row. The initial NaOH/KOH rows hold the amounts actually weighed (the formula's values); the per-oil reacting alkali is recomputed at 12 decimals, so a difference beyond the tolerance shows as an unallocated gap.
4. **Tolerances are explicit config:** `dossiers.mass_balance_tolerance_g` = `0.001` g (inputs carry 4-decimal gram values) and `dossiers.percent_tolerance` = `0.000001`. A tolerance never absorbs an unexplained difference: the regression fixture shaped like DIP.GA.007's column J fails even with a 1 g tolerance.
5. **Which material is assessed for each final row:**
   - soap salt, glycerin, residual water → the `substance_catalog` row whose `inci_name` matches (case-insensitive); none or several → `unidentified` (blocks submission);
   - unsaponified share, carried ingredient, residual hydrosol → the ingredient itself;
   - an ingredient is evaluated through the **whole-material references** of its coverage (supporting substances that are not tracked constituents — no `allergen_id`, not in the ingredient's substance entries), one evaluated row per reference at the ingredient's concentration; constituents are evaluated in their overlay rows; a blend with no whole-material reference is evaluated through its components (concentration × `percentage_in_parent`);
   - a substance material with no supporting reference is evaluated through its own current profile.
6. **Profiles: the current version is used while the dossier is live** (spec D10 "live while draft"). A coverage that relied on a profile version since superseded is a warning (`coverage_relies_on_superseded_profiles`); phase 4's out-of-date detection covers the frozen case.
7. **Overlays** use `SupplierConstituentResolver` per ingredient (supplier worst case first, flagged platform fallback). Contributions of the same allergen from every ingredient are summed (spec §4.2). Allergen mass in a saponified oil is carried as an overlay on that oil's initial mass (conservative). A blend with components and no validated supplier of its own is expanded into its components. `SupplierConstituentResolver` gains `restrictedSubstances()` (RCE values first, the platform `ingredient_substance_entries` with a concentration as flagged fallback).
8. **Indicators are all computed and shown; none concludes a row on its own.** MoS passes at ≥ threshold, C passes at ≤ Cmax, SED passes at ≤ ADI or TTC (boundary cases pass). NOAELsys = NOAEL × oral bioavailability (oral NOAEL) or × DAp (dermal NOAEL), as the spec states; an inhalation NOAEL is not converted (`route_not_convertible`). Impurities are compared in % internally and also reported in ppm and µg/kg bw/day. The profile has no separate SEDmax field: ADI and TTC are the SED limits.
9. **Row states** follow spec §4.2 exactly: binding breach → `non_conforme`; no confirmation → `route_suggere` when an indicator is available, else `a_justifier`; confirmed route → `conforme` only when its indicator passes, the profile's hazard endpoints are addressed, any annex condition that cannot be checked automatically is verified, and — for an allergen — QRA2 is available and passes; otherwise `a_justifier` with the reason. A confirmation without a justification counts as no confirmation.
10. **Annex checks** use reviewed `regulatory_legal_entries` of the `eu` regime in force on the evaluation date. Annex II present → banned. A maximum exceeded → breach (exactly at the maximum passes). `rinse_off_only` / `leave_on_only` follow the exposure preset's `is_rinse_off`. `see_conditions`, `other_limitations` or `product_type_body_parts` → `needs_verification`. Transition dates (`placing_on_market_until`, `making_available_until`) are shown but not evaluated in the pilot. Draft entries linked to a present substance are reported as a warning, never used.
11. **Label declaration of allergens** uses the active `eu` `regulatory_regime_allergens` row: rinse-off or leave-on threshold by preset, with its operator, against the **final** concentration.
12. **Submission blockers computed now** (phase 4 enforces them): calculation cannot run (includes no assessment policy, no exposure preset); tracing gap; an initial recipe ingredient or a final material whose coverage is not `sufficient`; an unidentified final material. Initial NaOH, KOH and lye water are not checked as initial materials: they are assessed through the final materials they become.
13. **Peroxide value unit (open since phase 2b):** the engine does not use peroxide values; they belong to the 2.A.2 raw-material specification table in phase 4. The unit question (meq O₂/kg on technical sheets vs mmol/L stored) is settled there.
14. **Output is plain arrays with documented shapes**, enum values as strings, decimals as full-precision strings (round only for display with `DecimalMath::round()`), so phase 4 can snapshot it as JSON without conversion.

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
| Materials | create `app/Services/Dossiers/Calculation/AssessedMaterialResolver.php`; test `tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php` |
| Engine | create `app/Services/Dossiers/Calculation/DossierAssessmentEngine.php`; test `tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php` |

## Reference values (computed by hand, not by the implementation)

Exposure inputs from DIP.GA.007: A = 1 g × 10/day, R = 0.01, bw = 60 kg.

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

Variants of the olive-only soap: lye water with 25 % lavender hydrosol → hydrosol 95 g fresh, 34.833595 g residual, 60.166405 g evaporated; water 285 g fresh, 104.500785 g residual. Measured cure loss 15 % → 226.101270 g lost, residual water 153.898730 g, cured mass 1281.240530 g (water 12.011697 %). Residual-water override 9 % → cured mass 1238.837143 g, residual 111.495343 g.

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

it('derives the systemic NOAEL from the study route', function (): void {
    $calculator = app(ExposureCalculator::class);

    expect(DecimalMath::round($calculator->systemicNoael('150', NoaelRoute::Oral, '50', '100'), 6))->toBe('75.000000')
        ->and(DecimalMath::round($calculator->systemicNoael('100', NoaelRoute::Dermal, '50', '14.4'), 6))->toBe('14.400000')
        ->and($calculator->systemicNoael('100', NoaelRoute::Inhalation, '50', '100'))->toBeNull();
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
     * NOAELsys = NOAEL × oral bioavailability (oral study) or × DAp (dermal study). An inhalation NOAEL is not converted.
     */
    public function systemicNoael(string $noael, NoaelRoute $route, string $oralBioavailabilityPercent, string $dermalAbsorptionPercent): ?string
    {
        return match ($route) {
            NoaelRoute::Oral => DecimalMath::mul($noael, DecimalMath::div($oralBioavailabilityPercent, '100')),
            NoaelRoute::Dermal => DecimalMath::mul($noael, DecimalMath::div($dermalAbsorptionPercent, '100')),
            NoaelRoute::Inhalation => null,
        };
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

it('fails a composition shaped like the DIP.GA.007 workbook column J whatever the tolerance', function (string $tolerance): void {
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
})->with(['configured tolerance' => [null], 'a tolerance of 1 g' => ['1']]);

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
Expected: PASS (5 tests).

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
     * workspace that has the DIP module. Extra rows (e.g. 'fragrance' or 'lye_water') come through $phaseItems.
     *
     * @param  array<string, list<array{ingredient_id: int, percentage: int|float, weight: int|float, note: null}>>  $phaseItems
     * @param  array<string, mixed>  $payloadOverrides
     * @return array{owner: User, workspace: Workspace, recipe: Recipe, version: RecipeVersion, workingCopy: RecipeVersion, olive: Ingredient}
     */
    public static function oliveSoap(array $phaseItems = [], array $payloadOverrides = []): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->for($owner, 'owner')->create();
        WorkspaceDossierGrant::factory()->for($workspace)->create();
        $family = ProductFamily::query()->where('slug', 'soap')->first()
            ?? ProductFamily::factory()->create(['slug' => 'soap', 'name' => 'Soap', 'calculation_basis' => 'oil_weight']);
        $olive = self::ingredient('Olive Oil', 'OLEA EUROPAEA FRUIT OIL', IngredientCategory::Lipids, [
            'soap_inci_naoh_name' => 'SODIUM OLIVATE',
            'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
            'is_soap_saponification_trusted' => true,
        ]);
        IngredientSapProfile::factory()->create(['ingredient_id' => $olive->id, 'koh_sap_value' => 0.188]);

        $payload = [
            'name' => 'DIP engine soap',
            'product_type_id' => testProductTypeIdForFamily('soap'),
            'oil_unit' => 'g',
            'oil_weight' => 1000,
            'manufacturing_mode' => 'saponify_in_formula',
            'exposure_mode' => 'rinse_off',
            'regulatory_regime' => 'eu',
            'editing_mode' => 'percentage',
            'lye_type' => 'naoh',
            'koh_purity_percentage' => 90,
            'dual_lye_koh_percentage' => 40,
            'water_mode' => 'percent_of_oils',
            'water_value' => 38,
            'superfat' => 5,
            'ifra_product_category_id' => null,
            'phase_items' => [
                'saponified_oils' => [['ingredient_id' => $olive->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
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

it('splits the residual liquid across water and a hydrosol by their fresh share', function (): void {
    $hydrosol = Fixtures::ingredient('Lavender Hydrosol', 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', IngredientCategory::BotanicalsExtracts);
    $soap = Fixtures::oliveSoap(['lye_water' => [Fixtures::row($hydrosol, 25, 95)]]);

    $composition = app(DossierCompositionBuilder::class)->build($soap['version']);
    $losses = collect($composition['losses'])->keyBy('from');

    expect(DecimalMath::round(compositionFinal($composition, 'residual_liquid:ingredient:'.$hydrosol->id)['grams'], 6))->toBe('34.833595')
        ->and(DecimalMath::round(compositionFinal($composition, 'residual_water')['grams'], 6))->toBe('104.500785')
        ->and(DecimalMath::round($losses['item:lye_water:0']['grams'], 6))->toBe('60.166405')
        ->and(DecimalMath::round($losses['water']['grams'], 6))->toBe('180.499215')
        ->and($composition['tracing']['passes'])->toBeTrue();
});

it('uses a measured cure loss instead of the estimate without rescaling other materials', function (): void {
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

it('refuses what the pilot engine does not support', function (string $reason): void {
    $soap = Fixtures::oliveSoap();
    $builder = app(DossierCompositionBuilder::class);

    // Pest would call closures placed in a dataset, so each case is selected by its expected reason.
    $build = match ($reason) {
        'working_copy' => fn () => $builder->build($soap['workingCopy']),
        'unsupported_family' => function () use ($builder, $soap) {
            ProductFamily::query()->whereKey($soap['recipe']->product_family_id)->update(['calculation_basis' => 'total_formula']);

            return $builder->build($soap['version']);
        },
        'measured_loss_out_of_range' => fn () => $builder->build($soap['version'], measuredCureLossPercent: '30'),
    };

    expect($build)->toThrow(function (DossierCalculationException $exception) use ($reason): void {
        expect($exception->reason)->toBe($reason);
    });
})->with(['working_copy', 'unsupported_family', 'measured_loss_out_of_range']);
```

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

use App\Exceptions\DossierCalculationException;
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
 * normalisation. The final composition is a theoretical estimate unless a measured cure loss is given.
 */
class DossierCompositionBuilder
{
    private const REACTION_PHASE = 'saponified_oils';

    private const LYE_LIQUID_PHASE = 'lye_water';

    private const REACTION_MASS_PRECISION = 12;

    public function __construct(
        private readonly RecipeWorkbenchVersionDataService $versionData,
        private readonly SoapCalculationService $soapCalculation,
        private readonly MassConverter $massConverter,
        private readonly IngredientTracingVerifier $tracingVerifier,
    ) {}

    /**
     * @return array{
     *     basis: array{unit: string, oil_weight_g: string, initial_mass_g: string, lye_liquid_g: string, cured_mass_g: string,
     *                  residual_liquid_g: string, residual_source: string, residual_water_percent: string|null, measured_cure_loss_percent: string|null},
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
        $this->assertSupported($recipe, $version);
        $snapshot = $this->versionData->versionSnapshot($recipe, $version->id);
        $calculation = $snapshot['calculation'] ?? null;

        if (! is_array($calculation) || ! is_array($calculation['lye'] ?? null)) {
            throw new DossierCalculationException('calculation_unavailable');
        }

        if (is_array($calculation['liquid_process'] ?? null)) {
            throw new DossierCalculationException('liquid_soap_unsupported');
        }

        $lye = $calculation['lye'];
        $this->assertNoExcessAlkali($lye);
        $unit = (string) data_get($snapshot, 'draft.oilUnit', 'g');
        $oilWeight = $this->grams(data_get($snapshot, 'draft.oilWeight', 0), $unit);
        $settings = $this->reactionSettings($lye);
        $initial = [];
        $final = [];
        $contributions = [];
        $reactions = [];

        foreach (Arr::wrap(data_get($snapshot, 'draft.phaseItems', [])) as $phase => $items) {
            if ($phase === self::LYE_LIQUID_PHASE) {
                continue;
            }

            foreach (array_values(Arr::wrap($items)) as $index => $item) {
                $grams = DecimalMath::div(DecimalMath::mul($oilWeight, DecimalMath::of($item['percentage'] ?? 0)), '100');

                if (! DecimalMath::isPositive($grams)) {
                    continue;
                }

                $key = "item:{$phase}:{$index}";
                $initial[] = $this->initialRow($key, 'ingredient', (int) $item['ingredient_id'], (string) $item['name'], $item['inci_name'] ?? null, (string) $phase, $grams);

                if ($phase === self::REACTION_PHASE) {
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

        [$initial, $final, $contributions] = $this->addAlkali($lye, $unit, $initial, $final, $contributions);
        $liquid = $this->lyeLiquidRows($snapshot, $this->grams(data_get($lye, 'water.weight', 0), $unit));
        $initial = [...$initial, ...array_column($liquid['rows'], 'initial')];
        $initialMass = DecimalMath::add(...array_column($initial, 'grams'));
        [$curedMass, $residual, $residualSource, $residualPercent] = $this->curedMass($snapshot, $initialMass, $liquid['total'], $measuredCureLossPercent, $residualWaterPercent);
        $losses = [];

        foreach ($liquid['rows'] as $row) {
            $fresh = $row['initial']['grams'];
            $kept = DecimalMath::mul($residual, DecimalMath::div($fresh, $liquid['total']));
            $losses[] = ['from' => $row['initial']['key'], 'kind' => 'evaporated', 'grams' => DecimalMath::sub($fresh, $kept)];

            if (DecimalMath::isPositive($kept)) {
                $final = $this->mergeFinal($final, [[...$row['final'], 'sources' => [$row['initial']['name']], 'grams' => $kept]]);
                $contributions[] = ['from' => $row['initial']['key'], 'to' => $row['final']['key'], 'grams' => $kept];
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
                'lye_liquid_g' => $liquid['total'],
                'cured_mass_g' => $curedMass,
                'residual_liquid_g' => $residual,
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

    private function assertSupported(Recipe $recipe, RecipeVersion $version): void
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
     * @param  array<string, mixed>  $lye
     */
    private function assertNoExcessAlkali(array $lye): void
    {
        if (DecimalMath::cmp(DecimalMath::of($lye['superfat_percentage'] ?? 0), '0') < 0) {
            throw new DossierCalculationException('negative_superfat');
        }

        if (DecimalMath::isPositive(DecimalMath::of(data_get($lye, 'excess.naoh_weight', 0)))
            || DecimalMath::isPositive(DecimalMath::of(data_get($lye, 'excess.koh_weight', 0)))) {
            throw new DossierCalculationException('excess_alkali');
        }
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
     * One oil's reaction (decision 3): oil → glycerin (backbone) and soap salt (salt − its alkali), alkali → soap salt,
     * the rest of the oil stays unsaponified.
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
     * The weighed alkali (the formula's values) as initial rows; KOH's non-active share stays in the bar.
     *
     * @param  array<string, mixed>  $lye
     * @param  list<array<string, mixed>>  $initial
     * @param  array<string, array<string, mixed>>  $final
     * @param  list<array{from: string, to: string, grams: string}>  $contributions
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: list<array{from: string, to: string, grams: string}>}
     */
    private function addAlkali(array $lye, string $unit, array $initial, array $final, array $contributions): array
    {
        $naoh = $this->grams(data_get($lye, 'selected.naoh_weight', 0), $unit);
        $kohActive = $this->grams(data_get($lye, 'selected.koh_weight', 0), $unit);
        $kohWeighed = $this->grams(data_get($lye, 'selected.koh_to_weigh', 0), $unit);

        if (DecimalMath::isPositive($naoh)) {
            $initial[] = $this->initialRow('naoh', 'alkali', null, 'Hydroxyde de sodium', 'SODIUM HYDROXIDE', 'lye', $naoh);
        }

        if (DecimalMath::isPositive($kohWeighed)) {
            $initial[] = $this->initialRow('koh', 'alkali', null, 'Hydroxyde de potassium', 'POTASSIUM HYDROXIDE', 'lye', $kohWeighed);
            $nonActive = DecimalMath::sub($kohWeighed, $kohActive);

            if (DecimalMath::isPositive($nonActive)) {
                $final = $this->mergeFinal($final, [
                    $this->finalRow('koh_non_active', 'koh_non_active', null, 'Part non active de l’hydroxyde de potassium', null, ['Hydroxyde de potassium'], $nonActive),
                ]);
                $contributions[] = ['from' => 'koh', 'to' => 'koh_non_active', 'grams' => $nonActive];
            }
        }

        return [$initial, $final, $contributions];
    }

    /**
     * Water plus any lye-water substitutes, each with the final row its residual share goes to.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{total: string, rows: list<array{initial: array<string, mixed>, final: array<string, mixed>}>}
     */
    private function lyeLiquidRows(array $snapshot, string $liquidMass): array
    {
        $waterInci = (string) config('dossiers.final_material_inci.residual_water');
        $rows = [];
        $substituted = '0';

        foreach (array_values(Arr::wrap(data_get($snapshot, 'draft.phaseItems.'.self::LYE_LIQUID_PHASE, []))) as $index => $item) {
            $grams = DecimalMath::div(DecimalMath::mul($liquidMass, DecimalMath::of($item['percentage'] ?? 0)), '100');

            if (! DecimalMath::isPositive($grams)) {
                continue;
            }

            $substituted = DecimalMath::add($substituted, $grams);
            $finalKey = 'residual_liquid:ingredient:'.$item['ingredient_id'];
            $rows[] = [
                'initial' => $this->initialRow('item:'.self::LYE_LIQUID_PHASE.":{$index}", 'ingredient', (int) $item['ingredient_id'], (string) $item['name'], $item['inci_name'] ?? null, self::LYE_LIQUID_PHASE, $grams),
                'final' => $this->finalRow($finalKey, 'residual_liquid', (int) $item['ingredient_id'], (string) $item['name'], $item['inci_name'] ?? null, [], '0'),
            ];
        }

        $water = DecimalMath::sub($liquidMass, $substituted);

        if (DecimalMath::cmp($water, '0') < 0) {
            throw new DossierCalculationException('lye_liquid_over_allocated');
        }

        if (DecimalMath::isPositive($water)) {
            array_unshift($rows, [
                'initial' => $this->initialRow('water', 'water', null, 'Eau', $waterInci, 'lye', $water),
                'final' => $this->finalRow('residual_water', 'residual_water', null, 'Eau résiduelle', $waterInci, [], '0'),
            ]);
        }

        return ['total' => $liquidMass, 'rows' => $rows];
    }

    /**
     * Cured mass and residual liquid: a measured cure loss replaces the estimated water loss; otherwise every non-liquid
     * mass is kept and the residual percentage (formula or justified override) sets the cured mass.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string, 1: string, 2: string, 3: string|null}
     */
    private function curedMass(array $snapshot, string $initialMass, string $liquidMass, ?string $measuredLossPercent, ?string $residualOverride): array
    {
        if (! DecimalMath::isPositive($liquidMass)) {
            return [$initialMass, '0', 'no_liquid', null];
        }

        if ($measuredLossPercent !== null) {
            $lost = DecimalMath::div(DecimalMath::mul($initialMass, DecimalMath::of($measuredLossPercent)), '100');

            if (DecimalMath::cmp($lost, '0') < 0 || DecimalMath::cmp($lost, $liquidMass) > 0) {
                throw new DossierCalculationException('measured_loss_out_of_range');
            }

            return [DecimalMath::sub($initialMass, $lost), DecimalMath::sub($liquidMass, $lost), 'measured', null];
        }

        $percent = $residualOverride !== null
            ? DecimalMath::of($residualOverride)
            : DecimalMath::of(SoapSap::curedWaterPercentage(data_get($snapshot, 'draft.curedWaterPercentage')));

        if (DecimalMath::cmp($percent, '0') < 0 || DecimalMath::cmp($percent, '100') >= 0) {
            throw new DossierCalculationException('residual_water_out_of_range');
        }

        $retained = DecimalMath::sub($initialMass, $liquidMass);
        $cured = DecimalMath::div($retained, DecimalMath::sub('1', DecimalMath::div($percent, '100')));
        $residual = DecimalMath::sub($cured, $retained);

        if (DecimalMath::cmp($residual, $liquidMass) > 0) {
            throw new DossierCalculationException('residual_exceeds_liquid');
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

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php`
Expected: PASS (8 tests). If the label cross-check differs in the 4th decimal, compare the label row's own rounding (`SoapCuredOutputBuilder::roundMass`) before changing anything: the engine's grams are the full-precision reference.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Exceptions/DossierCalculationException.php app/Services/Dossiers/Calculation/DossierCompositionBuilder.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/DossierCompositionBuilderTest.php
git commit -m "Build both DIP soap compositions with an ingredient-tracing ledger"
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
     * A blend with components and no validated supplier of its own is read through its components (decision 7).
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

it('refuses unknown or unjustified overrides and unknown routes', function (array $arguments): void {
    new DossierAssessmentOptions(...$arguments);
})->with([
    'unknown key' => [['overrides' => ['mos_threshold' => ['value' => '50', 'justification' => 'x']]]],
    'no justification' => [['overrides' => ['retention_factor' => ['value' => '0.1', 'justification' => ' ']]]],
    'not a number' => [['overrides' => ['retention_factor' => ['value' => 'ten', 'justification' => 'x']]]],
    'unknown route' => [['confirmations' => ['glycerin' => ['route' => 'guess', 'justification' => 'x']]]],
])->throws(InvalidArgumentException::class);
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
 * justification; the owner's confirmed routes per row key; an optional measured cure loss (% of the initial batch);
 * the evaluation date. Phase 4 stores them on the dossier version.
 */
final readonly class DossierAssessmentOptions
{
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
     * @param  array<string, array{route: string, justification: string, endpoints_addressed?: bool, conditions_verified?: bool}>  $confirmations
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

            DecimalMath::of($override['value'] ?? null);
        }

        foreach ($confirmations as $rowKey => $confirmation) {
            if (AssessmentRoute::tryFrom((string) ($confirmation['route'] ?? '')) === null) {
                throw new InvalidArgumentException("The route confirmed for [{$rowKey}] is not an assessment route.");
            }
        }

        if ($measuredCureLossPercent !== null) {
            DecimalMath::of($measuredCureLossPercent);
        }
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
Expected: PASS (8 tests).

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
 * EU annex checks (spec §4.2, decision 10). Binding requirements are automatic: a banned substance present or an
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
     * finished-product concentration (decision 11).
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
```

- [ ] **Step 3: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/RowIndicatorEvaluatorTest.php`
Expected: FAIL, class not found.

- [ ] **Step 4: Implement**

```php
<?php

namespace App\Services\Dossiers\Calculation;

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
        $systemicNoael = $this->exposure->systemicNoael(DecimalMath::of($profile->noael_mg_kg_bw_day), $profile->noael_route, $bioavailability, $dap);

        if ($systemicNoael === null) {
            return ['available' => false, 'reason' => 'route_not_convertible'];
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
Expected: PASS (7 tests).

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
 * Row states (spec §4.2, decision 9). A row is concluded only through a confirmed, justified route whose indicator
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
     * @param  array{route: string, justification: string, endpoints_addressed?: bool, conditions_verified?: bool}|null  $confirmation
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

        if ((bool) data_get($indicators, 'legal.needs_verification', false) && ($confirmation['conditions_verified'] ?? false) !== true) {
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
Expected: PASS (14 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/DossierRowState.php app/Services/Dossiers/Calculation/RowStateResolver.php tests/Feature/Dossiers/Calculation/RowStateResolverTest.php
git commit -m "Resolve DIP row states from confirmed routes and indicators"
```

---
### Task 11: Assessed materials for each row

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
function materialFinalRow(string $key, string $kind, ?Ingredient $ingredient, ?string $inci, string $percent): array
{
    return ['key' => $key, 'kind' => $kind, 'ingredient_id' => $ingredient?->id, 'name' => $ingredient?->display_name ?? (string) $inci, 'inci_name' => $inci, 'percent' => $percent];
}

it('finds a soap salt by its INCI name and evaluates it through its own profile', function (): void {
    $olivate = Fixtures::substance('Sodium olivate', 'Sodium Olivate');
    Fixtures::cover($olivate, $olivate);

    $targets = app(AssessedMaterialResolver::class)->targetsForFinalRow(materialFinalRow('soap_salt:SODIUM OLIVATE', 'soap_salt', null, 'SODIUM OLIVATE', '75.3'));

    expect($targets)->toHaveCount(1)
        ->and($targets[0]['key'])->toBe('soap_salt:SODIUM OLIVATE')
        ->and($targets[0]['material'])->toMatchArray(['type' => 'substance', 'id' => $olivate->id, 'unidentified_reason' => null])
        ->and($targets[0]['coverage']['state'])->toBe('sufficient')
        ->and($targets[0]['profile']->substance_id)->toBe($olivate->id);
});

it('reports a final material that is missing from the catalogue or ambiguous', function (int $matches, string $reason): void {
    Substance::factory()->count($matches)->create(['inci_name' => 'SODIUM COCOATE']);

    $targets = app(AssessedMaterialResolver::class)->targetsForFinalRow(materialFinalRow('soap_salt:SODIUM COCOATE', 'soap_salt', null, 'SODIUM COCOATE', '10'));

    expect($targets[0]['material'])->toMatchArray(['type' => null, 'unidentified_reason' => $reason])
        ->and($targets[0]['profile'])->toBeNull();
})->with([
    'not in the catalogue' => [0, 'not_in_catalogue'],
    'two catalogue rows' => [2, 'ambiguous_inci'],
]);

it('evaluates an ingredient through its whole-material references, not its tracked constituents', function (): void {
    $lavender = Fixtures::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
    $oil = Fixtures::substance('Lavandula angustifolia oil', 'LAVANDULA ANGUSTIFOLIA OIL');
    $limonene = Fixtures::substance('Limonene', 'LIMONENE', attributes: ['allergen_id' => Allergen::factory()->create()->id]);
    Fixtures::cover($lavender, $oil, $limonene);

    $targets = app(AssessedMaterialResolver::class)->targetsForFinalRow(materialFinalRow("carried:ingredient:{$lavender->id}", 'carried', $lavender, 'LAVANDULA ANGUSTIFOLIA OIL', '2.3'));

    expect($targets)->toHaveCount(1)
        ->and($targets[0]['key'])->toBe("carried:ingredient:{$lavender->id}|substance:{$oil->id}")
        ->and($targets[0]['material'])->toMatchArray(['type' => 'ingredient', 'id' => $lavender->id])
        ->and($targets[0]['substance']->is($oil))->toBeTrue();
});

it('evaluates a blend without whole-material references through its components', function (): void {
    $blend = Fixtures::ingredient('Blend', 'PARFUM', IngredientCategory::AromaticMaterials);
    $component = Fixtures::ingredient('Component', 'CITRUS AURANTIUM DULCIS PEEL OIL', IngredientCategory::AromaticMaterials);
    IngredientComponent::factory()->create(['ingredient_id' => $blend->id, 'component_ingredient_id' => $component->id, 'percentage_in_parent' => 40]);

    $targets = app(AssessedMaterialResolver::class)->targetsForFinalRow(materialFinalRow("carried:ingredient:{$blend->id}", 'carried', $blend, 'PARFUM', '2'));

    expect($targets)->toHaveCount(1)
        ->and($targets[0]['key'])->toBe("carried:ingredient:{$blend->id}|component:{$component->id}")
        ->and(DecimalMath::round($targets[0]['concentration_percent'], 6))->toBe('0.800000')
        ->and($targets[0]['material']['id'])->toBe($component->id)
        ->and($targets[0]['coverage'])->toBeNull();
});

it('flags a review that relied on a superseded profile and finds an allergen through its catalogue link', function (): void {
    $allergen = Allergen::factory()->create();
    $limonene = Fixtures::substance('Limonene', 'LIMONENE', attributes: ['allergen_id' => $allergen->id]);
    Fixtures::cover($limonene, $limonene);
    ToxicologyProfile::query()->where('substance_id', $limonene->id)->update(['is_current' => false]);
    ToxicologyProfile::factory()->create(['substance_id' => $limonene->id, 'version' => 2]);
    $resolver = app(AssessedMaterialResolver::class);

    expect($resolver->coverageSummary($limonene)['relies_on_superseded_profiles'])->toBeTrue()
        ->and($resolver->substanceForOverlay(['allergen_id' => $allergen->id, 'substance_id' => null])->is($limonene))->toBeTrue()
        ->and($resolver->currentProfile($limonene)->version)->toBe(2);
});
```

The superseded-profile test updates `is_current` through the query builder on purpose: the model blocks updates of append-only versions, and the test only needs the stored state a publisher would leave.

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
 * Which assessed material, coverage and toxicological profile each DIP row is evaluated through (decision 5).
 */
class AssessedMaterialResolver
{
    private const MAX_COMPONENT_DEPTH = 3;

    public function __construct(private readonly MaterialAssessmentCoverageService $coverageService) {}

    /**
     * @param  array{key: string, kind: string, ingredient_id: int|null, name: string, inci_name: string|null, percent: string}  $finalRow
     * @return list<array{key: string, name: string, concentration_percent: string,
     *                    material: array{type: string|null, id: int|null, name: string, unidentified_reason: string|null},
     *                    coverage: array<string, mixed>|null, substance: Substance|null, profile: ToxicologyProfile|null}>
     */
    public function targetsForFinalRow(array $finalRow): array
    {
        return match ($finalRow['kind']) {
            'soap_salt', 'glycerin', 'residual_water' => $this->substanceTargets($finalRow['key'], $finalRow['name'], $finalRow['inci_name'], $finalRow['percent']),
            'unsaponified', 'carried', 'residual_liquid' => $this->ingredientTargets(
                $finalRow['key'],
                $finalRow['name'],
                $finalRow['ingredient_id'] === null ? null : Ingredient::withoutGlobalScopes()->find($finalRow['ingredient_id']),
                $finalRow['percent'],
                0,
            ),
            default => [$this->unidentified($finalRow['key'], $finalRow['name'], $finalRow['percent'], 'not_in_catalogue')],
        };
    }

    /**
     * The assessed material of an overlay row: the allergen's catalogue link, or the impurity or restricted substance.
     *
     * @param  array{allergen_id: int|null, substance_id: int|null}  $overlayRow
     */
    public function substanceForOverlay(array $overlayRow): ?Substance
    {
        return $overlayRow['allergen_id'] !== null
            ? Substance::query()->where('allergen_id', $overlayRow['allergen_id'])->orderBy('id')->first()
            : Substance::query()->find($overlayRow['substance_id']);
    }

    public function currentProfile(?Substance $substance): ?ToxicologyProfile
    {
        return $substance === null
            ? null
            : ToxicologyProfile::query()->current()->where('substance_id', $substance->id)->orderByDesc('version')->first();
    }

    /**
     * @return array{state: string, covers_whole_material: bool, covers_constituents: bool, relies_on_superseded_profiles: bool, coverage_id: int}|null
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

    /**
     * @return list<array<string, mixed>>
     */
    private function substanceTargets(string $key, string $name, ?string $inci, string $percent): array
    {
        $matches = blank($inci) ? collect() : Substance::query()
            ->whereRaw('upper(inci_name) = ?', [mb_strtoupper(trim((string) $inci))])
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return [$this->unidentified($key, $name, $percent, $matches->isEmpty() ? 'not_in_catalogue' : 'ambiguous_inci')];
        }

        $substance = $matches->first();
        $references = $this->wholeMaterialReferences($substance)->reject(fn (Substance $reference): bool => $reference->is($substance));
        $identity = ['type' => 'substance', 'id' => $substance->id, 'name' => $substance->name, 'unidentified_reason' => null];
        $coverage = $this->coverageSummary($substance);

        if ($references->isEmpty()) {
            return [$this->target($key, $name, $percent, $identity, $coverage, $substance)];
        }

        return $references
            ->map(fn (Substance $reference): array => $this->target("{$key}|substance:{$reference->id}", "{$name} — {$reference->name}", $percent, $identity, $coverage, $reference))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ingredientTargets(string $key, string $name, ?Ingredient $ingredient, string $percent, int $depth): array
    {
        if (! $ingredient instanceof Ingredient) {
            return [$this->unidentified($key, $name, $percent, 'not_in_catalogue')];
        }

        $identity = ['type' => 'ingredient', 'id' => $ingredient->id, 'name' => (string) $ingredient->display_name, 'unidentified_reason' => null];
        $coverage = $this->coverageSummary($ingredient);
        $references = $this->wholeMaterialReferences($ingredient);

        if ($references->isNotEmpty()) {
            return $references
                ->map(fn (Substance $reference): array => $this->target("{$key}|substance:{$reference->id}", "{$ingredient->display_name} — {$reference->name}", $percent, $identity, $coverage, $reference))
                ->values()
                ->all();
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
                ->flatMap(fn (IngredientComponent $component): array => $this->ingredientTargets(
                    "{$key}|component:{$component->component_ingredient_id}",
                    (string) $component->componentIngredient->display_name,
                    $component->componentIngredient,
                    DecimalMath::div(DecimalMath::mul($percent, DecimalMath::of($component->percentage_in_parent)), '100'),
                    $depth + 1,
                ))
                ->values()
                ->all();
        }

        return [$this->target($key, (string) $ingredient->display_name, $percent, $identity, $coverage, null)];
    }

    /**
     * Supporting substances of the material's coverage that are not tracked constituents: no allergen link and, for an
     * ingredient, not among its substance entries. Constituents are evaluated in their overlay rows.
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
     * @param  array{type: string|null, id: int|null, name: string, unidentified_reason: string|null}  $identity
     * @param  array<string, mixed>|null  $coverage
     * @return array<string, mixed>
     */
    private function target(string $key, string $name, string $percent, array $identity, ?array $coverage, ?Substance $substance): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'concentration_percent' => $percent,
            'material' => $identity,
            'coverage' => $coverage,
            'substance' => $substance,
            'profile' => $this->currentProfile($substance),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unidentified(string $key, string $name, string $percent, string $reason): array
    {
        return $this->target($key, $name, $percent, ['type' => null, 'id' => null, 'name' => $name, 'unidentified_reason' => $reason], null, null);
    }
}
```

- [ ] **Step 5: Run the test**

Run: `php artisan test --compact tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/AssessedMaterialResolver.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/AssessedMaterialResolverTest.php
git commit -m "Resolve the assessed material, coverage and profile of each DIP row"
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
     *
     * @return array<string, mixed>
     */
    public static function assessedLavenderSoap(): array
    {
        $lavender = self::ingredient('Lavender Oil', 'LAVANDULA ANGUSTIFOLIA OIL', IngredientCategory::AromaticMaterials);
        $soap = self::oliveSoap(['fragrance' => [self::row($lavender, 3, 30)]]);
        AssessmentPolicy::factory()->create();
        ExposurePreset::factory()->create(['product_type_id' => $soap['recipe']->product_type_id]);

        $olivate = self::substance('Sodium olivate', 'SODIUM OLIVATE', ['noael_mg_kg_bw_day' => '1000']);
        $glycerin = self::substance('Glycerin', 'GLYCERIN', ['noael_mg_kg_bw_day' => '1180', 'dermal_absorption_percent' => '7.34']);
        $aqua = self::substance('Water', 'AQUA', ['noael_mg_kg_bw_day' => null, 'noael_route' => null, 'cmax_percent' => '100']);
        $oliveOil = self::substance('Olea europaea fruit oil', 'OLEA EUROPAEA FRUIT OIL', ['noael_mg_kg_bw_day' => '2000']);
        $lavenderOil = self::substance('Lavandula angustifolia oil', 'LAVANDULA ANGUSTIFOLIA OIL', ['noael_mg_kg_bw_day' => '200', 'sensitisation_note' => 'Contains fragrance allergens; see QRA2.']);
        $limoneneAllergen = Allergen::factory()->create(['inci_name' => 'LIMONENE']);
        $limonene = self::substance('Limonene', 'LIMONENE', ['noael_mg_kg_bw_day' => '150', 'nesil_ug_cm2' => '10000', 'sensitisation_note' => 'Skin sensitiser (oxidised).'], ['allergen_id' => $limoneneAllergen->id]);

        foreach ([$olivate, $glycerin, $aqua] as $finalMaterial) {
            self::cover($finalMaterial, $finalMaterial);
        }

        $coverages = [
            'olive' => self::cover($soap['olive'], $oliveOil),
            'lavender' => self::cover($lavender, $lavenderOil, $limonene),
            'glycerin' => MaterialAssessmentCoverage::query()->where('coverable_type', Substance::class)->where('coverable_id', $glycerin->id)->firstOrFail(),
        ];

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
use App\Models\IfraAmendment;
use App\Models\IfraProductCategory;
use App\Models\Qra2Parameter;
use App\Models\RecipeVersion;
use App\Models\RegulatoryLegalEntry;
use App\Models\RegulatoryRegime;
use App\Models\Workspace;
use App\Models\WorkspaceDossierGrant;
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

it('assesses the lavender soap end to end against the hand-calculated values', function (): void {
    $soap = Fixtures::assessedLavenderSoap();

    $assessment = assessSoap($soap);
    $limonene = assessedRow($assessment, 'allergen:'.$soap['limoneneAllergen']->id);
    $glycerin = assessedRow($assessment, 'glycerin');

    expect($assessment['status'])->toBe('calculated')
        ->and($assessment['blockers'])->toBe([])
        ->and($assessment['can_submit'])->toBeTrue()
        ->and($assessment['can_approve'])->toBeFalse()
        ->and($assessment['composition']['tracing']['passes'])->toBeTrue()
        ->and(DecimalMath::round($limonene['concentration_percent'], 6))->toBe('0.922804')
        ->and(DecimalMath::round($limonene['indicators']['sed_mg_kg_bw_day'], 9))->toBe('0.015380072')
        ->and(DecimalMath::round($limonene['indicators']['mos']['value'], 2))->toBe('4876.44')
        ->and($limonene['indicators']['qra2'])->toBe(['available' => false, 'reason' => 'no_qra2_preset'])
        ->and($limonene['state'])->toBe('route_suggere')
        ->and($limonene['suggested_route'])->toBe('mos')
        ->and($limonene['needs_justification'])->toBeFalse()
        ->and(DecimalMath::round($glycerin['indicators']['mos']['value'], 2))->toBe('64169.75')
        ->and(assessedRow($assessment, 'residual_water')['suggested_route'])->toBe('cmax')
        ->and(assessedRow($assessment, "carried:ingredient:{$soap['lavender']->id}|substance:{$soap['substances']['lavenderOil']->id}")['indicators']['hazard_endpoints'])->toBe(['sensitisation'])
        ->and(collect($assessment['warnings'])->pluck('code')->all())->toContain('final_composition_estimated', 'no_qra2_preset', 'suppliers_without_declaration');
});

it('concludes rows only through confirmed routes, and an allergen only with QRA2', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $limoneneKey = 'allergen:'.$soap['limoneneAllergen']->id;
    $options = new DossierAssessmentOptions(confirmations: [
        'glycerin' => ['route' => 'mos', 'justification' => 'Oral NOAEL, SCCS default bioavailability.'],
        $limoneneKey => ['route' => 'mos', 'justification' => 'Systemic NOAEL; sensitisation through QRA2.', 'endpoints_addressed' => true],
    ]);

    $withoutQra2 = assessSoap($soap, $options);

    $category = IfraProductCategory::factory()->create();
    $amendment = IfraAmendment::factory()->create();
    Qra2Parameter::factory()->create(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    RecipeVersion::withoutGlobalScopes()->whereKey($soap['version']->id)->update(['ifra_product_category_id' => $category->id, 'ifra_amendment_id' => $amendment->id]);
    $soap['version'] = $soap['version']->fresh();
    $withQra2 = assessSoap($soap, $options);

    expect(assessedRow($withoutQra2, 'glycerin')['state'])->toBe('conforme')
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
    $limonene = assessedRow($assessment, 'allergen:'.$soap['limoneneAllergen']->id);

    expect(DecimalMath::round($limonene['indicators']['sed_mg_kg_bw_day'], 9))->toBe('0.030760144')
        ->and(collect($assessment['parameters'])->firstWhere('key', 'amount_per_use_g')['source'])->toBe('override')
        ->and(collect($assessment['parameters'])->firstWhere('key', 'residual_water_percent')['source'])->toBe('formula_estimate');
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

it('keeps a non-conforming row submittable but not approvable', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $regime = RegulatoryRegime::factory()->create(['code' => 'eu']);
    $entry = RegulatoryLegalEntry::factory()->reviewed()->create([
        'regulatory_regime_id' => $regime->id,
        'annex' => 'III',
        'max_concentration_percent' => '0.5',
    ]);
    $entry->substances()->attach($soap['substances']['limonene']->id);

    $assessment = assessSoap($soap);

    expect(assessedRow($assessment, 'allergen:'.$soap['limoneneAllergen']->id)['state'])->toBe('non_conforme')
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

it('refuses a workspace without the module or a formula from another workspace', function (): void {
    $soap = Fixtures::assessedLavenderSoap();
    $other = Workspace::factory()->for($soap['owner'], 'owner')->create();
    WorkspaceDossierGrant::factory()->for($other)->create();

    expect(fn () => app(DossierAssessmentEngine::class)->assess($soap['owner'], $other, $soap['version']))->toThrow(AuthorizationException::class);

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
use App\Models\ToxicologyProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The live DIP assessment of a saved formula (spec §4): both compositions, overlays, every indicator, suggested routes,
 * row states, and the blockers and warnings of spec §5. Read-only: nothing is stored; phase 4 snapshots the result.
 */
class DossierAssessmentEngine
{
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
        $rows = [
            ...$this->materialRows($composition['final'], $parameters, $options, $on),
            ...$this->overlayRows($overlays['rows'], $parameters, $options, $on),
        ];
        $blockers = $this->blockers($composition, $rows);

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
            'warnings' => $this->warnings($composition, $overlays, $parameters, $rows),
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
     * @param  array<string, mixed>  $parameters
     * @return list<array<string, mixed>>
     */
    private function materialRows(array $final, array $parameters, DossierAssessmentOptions $options, CarbonImmutable $on): array
    {
        return collect($final)
            ->flatMap(fn (array $finalRow): array => collect($this->materials->targetsForFinalRow($finalRow))
                ->map(fn (array $target): array => $this->evaluatedRow(
                    'material',
                    $target,
                    $parameters,
                    $options,
                    $on,
                    ['final_key' => $finalRow['key'], 'needs_justification' => false],
                ))
                ->all())
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $overlays
     * @param  array<string, mixed>  $parameters
     * @return list<array<string, mixed>>
     */
    private function overlayRows(array $overlays, array $parameters, DossierAssessmentOptions $options, CarbonImmutable $on): array
    {
        return collect($overlays)
            ->map(function (array $overlay) use ($parameters, $options, $on): array {
                $substance = $this->materials->substanceForOverlay($overlay);
                $name = $substance?->name ?? (string) Allergen::query()->whereKey($overlay['allergen_id'])->value('inci_name');
                $target = [
                    'key' => $overlay['key'],
                    'name' => $name,
                    'concentration_percent' => $overlay['final_percent'],
                    'material' => [
                        'type' => $substance === null ? null : 'substance',
                        'id' => $substance?->id,
                        'name' => $name,
                        'unidentified_reason' => $substance === null ? 'not_in_catalogue' : null,
                    ],
                    'coverage' => null,
                    'substance' => $substance,
                    'profile' => $this->materials->currentProfile($substance),
                ];
                $row = $this->evaluatedRow($overlay['kind'], $target, $parameters, $options, $on, [
                    'overlay_key' => $overlay['key'],
                    'needs_justification' => $overlay['needs_justification'],
                ]);

                if ($overlay['kind'] === 'allergen') {
                    $row['label_declaration'] = $this->legal->allergenDeclaration((int) $overlay['allergen_id'], $overlay['final_percent'], $parameters['is_rinse_off'], $on);
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{key: string, name: string, concentration_percent: string, material: array<string, mixed>, coverage: array<string, mixed>|null, substance: Substance|null, profile: ToxicologyProfile|null}  $target
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function evaluatedRow(string $category, array $target, array $parameters, DossierAssessmentOptions $options, CarbonImmutable $on, array $extra): array
    {
        $legal = $this->legal->check($target['substance'], $target['concentration_percent'], $parameters['is_rinse_off'], $on);
        $indicators = $this->indicators->evaluate($category, $target['concentration_percent'], $target['profile'], $parameters, $legal);
        $confirmation = $options->confirmations[$target['key']] ?? null;
        $state = $this->states->resolve($category, $target['profile'] !== null, $indicators, $confirmation);

        return [
            'key' => $target['key'],
            'category' => $category,
            'name' => $target['name'],
            'concentration_percent' => $target['concentration_percent'],
            'material' => $target['material'],
            'coverage' => $target['coverage'],
            'substance_id' => $target['substance']?->id,
            'profile' => $target['profile'] === null ? null : ['id' => $target['profile']->id, 'version' => $target['profile']->version],
            'indicators' => $indicators,
            'suggested_route' => $state['suggested_route']?->value,
            'confirmed_route' => $state['confirmed_route']?->value,
            'confirmation' => $confirmation,
            'state' => $state['state']->value,
            'state_reason' => $state['reason'],
            ...$extra,
        ];
    }

    /**
     * Submission blockers of spec §5 that this phase can compute (decision 12).
     *
     * @param  array<string, mixed>  $composition
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function blockers(array $composition, array $rows): array
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

        $materialRows = collect($rows)
            ->where('category', 'material')
            ->unique(fn (array $row): string => $row['material']['type'] === null ? 'unidentified:'.$row['final_key'] : $row['material']['type'].':'.$row['material']['id']);

        foreach ($materialRows as $row) {
            if ($row['material']['type'] === null) {
                $blockers[] = ['code' => 'final_material_unidentified', 'scope' => 'final', 'key' => $row['final_key'], 'name' => $row['name'], 'reason' => $row['material']['unidentified_reason']];

                continue;
            }

            $state = $row['coverage']['state'] ?? AssessmentReviewState::NotReviewed->value;

            if ($state !== AssessmentReviewState::Sufficient->value) {
                $blockers[] = ['code' => 'material_'.$state, 'scope' => 'final', 'key' => $row['final_key'], 'name' => $row['material']['name']];
            }
        }

        return $blockers;
    }

    /**
     * Visible warnings carried into the snapshot; they never block submission.
     *
     * @param  array<string, mixed>  $composition
     * @param  array<string, mixed>  $overlays
     * @param  array<string, mixed>  $parameters
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function warnings(array $composition, array $overlays, array $parameters, array $rows): array
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

        foreach (collect($rows)->filter(fn (array $row): bool => (bool) data_get($row, 'coverage.relies_on_superseded_profiles'))->unique(fn (array $row): string => $row['material']['type'].':'.$row['material']['id']) as $row) {
            $warnings[] = ['code' => 'coverage_relies_on_superseded_profiles', 'material' => $row['material']];
        }

        if ($parameters['qra2'] === null && collect($rows)->contains('category', 'allergen')) {
            $warnings[] = ['code' => 'no_qra2_preset'];
        }

        foreach (collect($rows)->where('category', 'allergen')->whereNull('substance_id') as $row) {
            $warnings[] = ['code' => 'allergen_without_assessed_material', 'key' => $row['key']];
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
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Dossiers/Calculation/DossierAssessmentEngine.php tests/Support/DossierCalculationFixtures.php tests/Feature/Dossiers/Calculation/DossierAssessmentEngineTest.php
git commit -m "Assess a saved soap formula end to end for the DIP"
```

---

### Task 13: Verification and documentation

**Files:**
- Modify: `docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md` (status line only)

- [ ] **Step 1: Run every DIP test, then the full suite**

```bash
export LANG=en_US.UTF-8
php artisan test --compact tests/Feature/Dossiers
php artisan test --compact --parallel
```

Expected: all pass. A failure outside `tests/Feature/Dossiers` means a shared service changed behaviour; this plan changes no shared service except `SupplierConstituentResolver` (one added method).

- [ ] **Step 2: Check the reference layers are independent**

Confirm, by reading the tests, that every expected number in Tasks 2–12 comes from the "Reference values" table of this plan (hand calculation) and none was copied from a test run. Layer 3 of spec §12 (the assessor-approved pilot) comes after phase 4.

- [ ] **Step 3: Update the spec status line**

In the spec, replace the line starting with `**Status:**` with:

```markdown
**Status:** design agreed with Philippe on 2026-10-21 after a Codex second-opinion review. Phases 1, 2a, 2b and 3 (calculation engine, read-only) are implemented on `codex/dip-pilot`; phase 2c (folder import) and phase 4 (dossiers, signing, archive) remain.
```

- [ ] **Step 4: Pint, graph, commit**

```bash
vendor/bin/pint --dirty --format agent
graphify update .
git add docs/superpowers/specs/2026-10-21-product-information-file-dip-design.md
git commit -m "Record DIP phase 3 as implemented in the spec"
```

---

## Self-review notes (for the reviewer of this plan)

- **Spec §4.1 coverage:** initial composition (Task 5), final composition with mass balance (Task 5), ingredient tracing before normalisation with the column-J regression (Task 4), measured cure loss (Task 5), overlays on both compositions (Task 6). Supplier set per initial row is not repeated in the composition: the overlays carry the evidence items, and phase 4's Part A table reads the validated qualifications directly.
- **Spec §4.2 coverage:** SED, NOAELsys, MoS (Task 2); all indicators shown (Task 9); binding requirements automatic (Tasks 8, 10); suggested and confirmed routes with the four states (Task 10); QRA2 with the factor and summed allergen contributions (Tasks 3, 6, 9); impurities in ppm and µg (Tasks 2, 6, 9); annex checks with warnings collected (Task 8); hazard endpoints never settled by a large MoS (Tasks 9, 10).
- **Spec §12 coverage:** layer 1 arithmetic fixtures (Tasks 2, 3, 5, 12); layer 2 boundary and failure cases (Tasks 4, 5, 7, 9, 10); row-state, coverage and gate tests (Tasks 10, 11, 12). Snapshot, out-of-date and signing tests belong to phase 4.
- **Not in this phase:** storage of confirmations and overrides, Part B text, the checklist, print view — phase 4. Leave-on and liquid products — phase 5.
