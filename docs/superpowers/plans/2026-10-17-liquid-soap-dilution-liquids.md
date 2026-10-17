# Liquid Soap Dilution Liquids Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a liquid soap formula replace part of the water of its first and final dilution with other liquids, and carry them through the preview, save, labels, costing, batch sheet, Production Bench, sharing and the bench screen.

**Architecture:** Two new system phases, `first_dilution` and `final_dilution`, hold liquid rows whose percentage is a % of that step's water, exactly like the alkali solution's `lye_water` rows. `LiquidSoapProcessCalculator` is unchanged (a liquid counts by its whole weight); `LiquidSoapFormulaProcessor::dilutionLiquids()` splits each step's added mass into liquids and plain water with `LyeLiquidAllocationService`. Every consumer that already special-cases `lye_water` gets the same treatment for the two new phases.

**Tech Stack:** Laravel 13, Pest, Livewire + Alpine (`resources/js/recipe-workbench`), bcmath decimal strings.

**Spec:** `docs/superpowers/specs/2026-10-17-liquid-soap-dilution-liquids-design.md`

**Before starting:** read `.ai/rules/index.md` and the rule files for `app/Services/**`, `app/Services/Production/**`, `resources/js/recipe-workbench/**`, `resources/views/**/recipe-workbench/**`, `lang/**` and `tests/**`. Run tests with `export LANG=en_US.UTF-8` first. After PHP edits run `vendor/bin/pint --dirty --format agent`.

**Shared fixture used by several tasks** — a published liquid soap (1000 g oil, KOH SAP 0.19, −3% superfat, citric acid 0.65062% of oils as a 20% solution, 35% → 25% dilutions). Its preview (from `LiquidSoapBatchSheetTest`) gives: first dilution adds **1679.327 g**, neutralization adds 32.531 g, final dilution adds **1327.47 g** with no additions, final batch **4760 g**. With a 30% hydrosol in the final dilution the hydrosol is 0.30 × 1327.4700 = **398.2410 g** and the plain water 929.2290 g.

---

## File map

| File | Responsibility |
| --- | --- |
| `app/Services/RecipeWorkbenchPhaseBlueprints.php` | Adds the two phases and the `DILUTION_PHASES` constant |
| `app/Services/LyeLiquidIngredientValidator.php` | Error field becomes a parameter so messages name the step |
| `app/Services/LiquidSoapFormulaProcessor.php` | `dilutionLiquids()`; `addedDilutionWaterWeight()` excludes liquids |
| `app/Services/RecipeWorkbenchPreviewService.php` | Preview returns `dilution_liquids`, adjusts composition, unused warning |
| `app/Services/RecipeWorkbenchPayloadNormalizer.php` | Keeps the rows out of the % of oils maths; stores them at save |
| `app/Services/RecipeWorkbenchService.php` | Access validation of the rows at save |
| `app/Services/InciGenerationService.php` | Liquid rows at their process weight |
| `app/Services/RecipeVersionCostPreviewBuilder.php` | Rows priced on their stored weight |
| `app/Services/FormulaDocumentBuilder.php` | Step instructions list water and liquids |
| `app/Services/Production/ProductionRequirementBuilder.php` | Rows scale from saved weight |
| `app/Services/Production/ProductionFormulaSnapshotBuilder.php` | Water lines exclude the liquids |
| `app/Services/FormulaSharePreview.php`, `FormulaShareRecipeImporter.php` | Validate and preserve the rows |
| `resources/js/recipe-workbench/catalog.js`, `payload.js`, `component.js`, `sections/costing-section.js` | Bench state, serialisation, costing |
| `resources/views/livewire/dashboard/partials/recipe-workbench/liquid-soap-process.blade.php` | Liquids lines per step |
| `lang/en/workbench.php`, `lang/en/formula_documents.php`, `database/seeders/data/interface-translations.json` | Strings |

---

### Task 1: Dilution phases on liquid soap families

**Files:**
- Modify: `app/Services/RecipeWorkbenchPhaseBlueprints.php`
- Test: `tests/Feature/LiquidSoapDilutionLiquidsTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\ProductFamily;
use App\Services\RecipeWorkbenchPhaseBlueprints;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('gives liquid soap first and final dilution phases in process order and bar soap none', function () {
    $blueprints = app(RecipeWorkbenchPhaseBlueprints::class);

    expect(collect($blueprints->all(ProductFamily::factory()->liquidSoapPaste()->make()))->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'first_dilution', 'neutralization', 'additives', 'fragrance', 'final_dilution'])
        ->and(collect($blueprints->all(ProductFamily::factory()->make(['slug' => 'soap'])))->pluck('key')->all())
        ->toBe(['saponified_oils', 'lye_water', 'additives', 'fragrance'])
        ->and($blueprints->find('final_dilution')['phase_type'])->toBe('post_reaction');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapDilutionLiquidsTest.php`
Expected: FAIL, the liquid list has no dilution phases.

- [ ] **Step 3: Add the phases**

In `RecipeWorkbenchPhaseBlueprints`, add a public constant and replace the `array_splice` block in `soapPhases()`:

```php
    /** Liquid soap dilution steps whose rows are a % of that step's water. */
    public const DILUTION_PHASES = ['first_dilution', 'final_dilution'];
```

```php
        if (! $withNeutralization) {
            return $phases;
        }

        $dilution = fn (string $key, string $name, string $description): array => [
            'key' => $key,
            'name' => $name,
            'phase_group' => 'post_reaction',
            'phase_type' => 'post_reaction',
            'description' => $description,
            'is_system' => true,
        ];

        array_splice($phases, 2, 0, [
            $dilution('first_dilution', 'First dilution', 'Liquids that replace part of the first dilution water of a liquid soap paste.'),
            [
                'key' => 'neutralization',
                'name' => 'Neutralization',
                'phase_group' => 'post_reaction',
                'phase_type' => 'post_reaction',
                'description' => 'The acid that neutralizes free KOH left in a liquid soap paste.',
                'is_system' => true,
            ],
        ]);
        $phases[] = $dilution('final_dilution', 'Final dilution', 'Liquids that replace part of the final dilution water of a liquid soap.');

        return $phases;
```

Also update the docblock of `soapPhases()` to: "Liquid soap paste families add the first dilution and neutralization after the alkali solution and the final dilution last; bar soap never has them."

- [ ] **Step 4: Run it to see it pass**

Run: `php artisan test --compact tests/Feature/LiquidSoapDilutionLiquidsTest.php tests/Feature/SoapBenchBaselineTest.php tests/Feature/LiquidSoapFormulaPersistenceTest.php`
Expected: PASS. If a persistence test asserts the old liquid phase list, update its expected list to the new order.

- [ ] **Step 5: Commit**

```bash
git add app/Services/RecipeWorkbenchPhaseBlueprints.php tests/Feature/LiquidSoapDilutionLiquidsTest.php tests/Feature/LiquidSoapFormulaPersistenceTest.php
git commit -m "Give liquid soap first and final dilution phases"
```

---

### Task 2: Split each dilution into liquids and plain water

**Files:**
- Modify: `app/Services/LyeLiquidIngredientValidator.php`
- Modify: `app/Services/LiquidSoapFormulaProcessor.php`
- Test: `tests/Unit/LiquidSoapDilutionLiquidsSplitTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\LiquidSoapFormulaProcessor;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function dilutionProcess(): array
{
    return [
        'status' => 'ok',
        'stages' => [
            'first_dilution' => ['add_weight' => '1679.3270'],
            'final_dilution' => ['add_weight' => '1327.4700'],
        ],
        'neutralizer' => ['weight' => '6.5062', 'solution_weight' => '32.5310'],
    ];
}

it('splits each dilution into its liquids and plain water', function () {
    $split = app(LiquidSoapFormulaProcessor::class)->dilutionLiquids(dilutionProcess(), [
        'final_dilution' => [['id' => 'h1', 'ingredient_id' => 7, 'percentage' => 30]],
    ]);

    expect($split['first_dilution'])->toBe(['water_weight' => 1679.327, 'liquids' => []])
        ->and($split['final_dilution']['water_weight'])->toBe(929.229)
        ->and($split['final_dilution']['liquids'])->toBe([['id' => 'h1', 'ingredient_id' => 7, 'percentage' => 30.0, 'weight' => 398.241]]);
});

it('counts only the plain water as added dilution water', function () {
    $processor = app(LiquidSoapFormulaProcessor::class);
    $process = [...dilutionProcess(), 'dilution_liquids' => $processor->dilutionLiquids(dilutionProcess(), [
        'final_dilution' => [['ingredient_id' => 7, 'percentage' => 30]],
    ])];

    // 1679.327 + 929.229 + (32.531 − 6.5062)
    expect((float) $processor->addedDilutionWaterWeight($process))->toEqualWithDelta(2634.5808, 0.0001);
});

it('names the step when its liquids exceed 100 percent', function () {
    app(LiquidSoapFormulaProcessor::class)->dilutionLiquids(dilutionProcess(), [
        'first_dilution' => [['ingredient_id' => 7, 'percentage' => 60], ['ingredient_id' => 8, 'percentage' => 50]],
    ]);
})->throws(ValidationException::class);
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Unit/LiquidSoapDilutionLiquidsSplitTest.php`
Expected: FAIL, `dilutionLiquids` is undefined.

- [ ] **Step 3: Parameterise the validator's error field**

In `LyeLiquidIngredientValidator`, give the three public methods an optional field argument and use it in every `ValidationException::withMessages([...])` key that currently says `'phase_items.lye_water'`:

```php
    public function assertMaximumRows(array $rows, string $field = 'phase_items.lye_water'): void
    public function validate(array $rows, ?User $user = null, string $field = 'phase_items.lye_water'): array
    public function normalizeRows(array $rows, string $field = 'phase_items.lye_water'): array
```

`normalizeRows()` must pass `$field` to `assertMaximumRows()`, and `validate()` must pass it to `normalizeRows()`.

- [ ] **Step 4: Add `dilutionLiquids()` and change `addedDilutionWaterWeight()`**

In `LiquidSoapFormulaProcessor`, inject the two services and add the method:

```php
    public function __construct(
        private readonly LiquidSoapProcessCalculator $liquidSoapProcessCalculator,
        private readonly LyeLiquidAllocationService $lyeLiquidAllocationService,
        private readonly LyeLiquidIngredientValidator $lyeLiquidIngredientValidator,
    ) {}

    /**
     * Each dilution step's added mass split into its liquids (rows as % of
     * that step's water) and the plain water left. A step that adds nothing
     * gives its liquids 0 g.
     *
     * @param  array<string, mixed>  $process  Process result with status ok.
     * @param  array<string, mixed>  $phaseItems  Rows keyed by phase.
     * @return array<string, array{water_weight: float, liquids: list<array<string, mixed>>}>
     */
    public function dilutionLiquids(array $process, array $phaseItems): array
    {
        return collect(RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES)
            ->mapWithKeys(function (string $phaseKey) use ($process, $phaseItems): array {
                $field = "phase_items.{$phaseKey}";
                $rows = $this->lyeLiquidIngredientValidator->normalizeRows(
                    collect($phaseItems[$phaseKey] ?? [])->values()->all(),
                    $field,
                );

                try {
                    $allocation = $this->lyeLiquidAllocationService->allocateFresh(
                        (string) data_get($process, "stages.{$phaseKey}.add_weight", '0'),
                        $rows,
                    );
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([$field => $exception->getMessage()]);
                }

                return [$phaseKey => [
                    'water_weight' => $allocation['water_weight'],
                    'liquids' => collect($allocation['substitutions'])
                        ->map(fn (array $row): array => [
                            ...collect($row)->only(['id', 'ingredient_id'])->all(),
                            'percentage' => (float) $row['percentage'],
                            'weight' => (float) $row['weight'],
                        ])
                        ->values()
                        ->all(),
                ]];
            })
            ->all();
    }
```

Replace the body of `addedDilutionWaterWeight()` so it counts plain water only:

```php
    public function addedDilutionWaterWeight(array $liquidProcess): string
    {
        $neutralizer = $liquidProcess['neutralizer'] ?? null;
        $solutionWater = is_array($neutralizer)
            ? bcsub((string) $neutralizer['solution_weight'], (string) $neutralizer['weight'], 18)
            : '0';
        $dilutionWater = collect(RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES)
            ->reduce(function (string $total, string $phaseKey) use ($liquidProcess): string {
                $added = (string) data_get($liquidProcess, "stages.{$phaseKey}.add_weight", '0');
                $liquids = collect(data_get($liquidProcess, "dilution_liquids.{$phaseKey}.liquids", []))
                    ->reduce(fn (string $sum, array $liquid): string => bcadd($sum, (string) $liquid['weight'], 18), '0');

                return bcadd($total, bcsub($added === '' ? '0' : $added, $liquids, 18), 18);
            }, '0');

        return bcadd($dilutionWater, bccomp($solutionWater, '0', 18) > 0 ? $solutionWater : '0', 18);
    }
```

Update its docblock: "Plain water added after the paste: both dilutions minus their liquids, and the neutralizer solution water." Add `use Illuminate\Validation\ValidationException;` and `use InvalidArgumentException;` (already imported).

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Unit/LiquidSoapDilutionLiquidsSplitTest.php tests/Feature/LiquidSoapCostingTest.php tests/Feature/LiquidSoapIngredientListTest.php`
Expected: PASS (existing callers have no `dilution_liquids` key, so they get the same water as before).

- [ ] **Step 6: Commit**

```bash
git add app/Services/LyeLiquidIngredientValidator.php app/Services/LiquidSoapFormulaProcessor.php tests/Unit/LiquidSoapDilutionLiquidsSplitTest.php
git commit -m "Split liquid soap dilutions into their liquids and plain water"
```

---

### Task 3: Preview returns the dilution liquids

**Files:**
- Modify: `app/Services/RecipeWorkbenchPreviewService.php` (`previewLiquidSoapProcess`, `previewSoapCalculation`)
- Modify: `lang/en/workbench.php` (warning text)
- Test: `tests/Feature/LiquidSoapProcessPreviewTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LiquidSoapProcessPreviewTest.php`, reusing that file's existing payload helper. Read the top of the file first; the helper builds the reference formula and calls `RecipeWorkbenchService::previewSoapCalculation()`. If its name differs from `liquidSoapPreviewPayload`/`liquidSoapPreview`, use the file's names.

```php
it('previews each dilution liquid as part of its step water', function () {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    $payload = liquidSoapPreviewPayload();
    $payload['phase_items']['final_dilution'] = [['id' => 'h1', 'ingredient_id' => $hydrosol->id, 'percentage' => 30]];

    $process = liquidSoapPreview($payload)['liquid_process'];

    expect($process['dilution_liquids']['final_dilution']['liquids'][0])->toMatchArray(['ingredient_id' => $hydrosol->id, 'weight' => 398.241])
        ->and($process['dilution_liquids']['final_dilution']['water_weight'])->toBe(929.229)
        ->and((float) $process['composition']['dilution_liquids_weight'])->toEqualWithDelta(398.241, 0.0001)
        ->and((float) $process['final_weight'])->toEqualWithDelta(4760.0, 0.0001);
});

it('warns when a step with liquids adds nothing', function () {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);
    $payload = liquidSoapPreviewPayload();
    $payload['liquid_process']['first_dilution_percentage'] = 80;
    $payload['phase_items']['first_dilution'] = [['ingredient_id' => $hydrosol->id, 'percentage' => 50]];

    expect(liquidSoapPreview($payload)['liquid_process']['warnings'])->toContain('dilution_liquid_unused_first_dilution');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapProcessPreviewTest.php`
Expected: FAIL, no `dilution_liquids` key.

- [ ] **Step 3: Add the split to the preview**

In `previewLiquidSoapProcess()`, after `$process = ...->process(...)` and before the `return`:

```php
        $dilutionLiquids = $process['status'] === 'ok'
            ? $this->liquidSoapFormulaProcessor->dilutionLiquids($process, $payload['phase_items'] ?? [])
            : null;

        if ($dilutionLiquids !== null) {
            $liquidsWeight = collect($dilutionLiquids)->flatMap(fn (array $step): array => $step['liquids'])
                ->reduce(fn (string $total, array $liquid): string => bcadd($total, (string) $liquid['weight'], 18), '0');
            $finalWeight = (string) $process['final_weight'];
            $process['composition']['water_weight'] = $this->fourDecimals(bcsub((string) $process['composition']['water_weight'], $liquidsWeight, 18));
            $process['composition']['dilution_liquids_weight'] = $this->fourDecimals($liquidsWeight);
            $process['composition_percentages']['water_weight'] = $this->fourDecimals(bcdiv(bcmul($process['composition']['water_weight'], '100', 18), $finalWeight, 18));
            $process['composition_percentages']['dilution_liquids_weight'] = $this->fourDecimals(bcdiv(bcmul($liquidsWeight, '100', 18), $finalWeight, 18));

            foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
                if ($dilutionLiquids[$phaseKey]['liquids'] !== [] && bccomp((string) data_get($process, "stages.{$phaseKey}.add_weight", '0'), '0', 4) <= 0) {
                    $process['warnings'][] = "dilution_liquid_unused_{$phaseKey}";
                }
            }
        }
```

and add `'dilution_liquids' => $dilutionLiquids,` to the returned array. In `previewSoapCalculation()`, next to the `lye_water` `assertMaximumRows`, assert the dilution rows too:

```php
        foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
            $rows = data_get($payload, "phase_items.{$phaseKey}", []);
            $this->lyeLiquidIngredientValidator->assertMaximumRows(is_array($rows) ? $rows : [], "phase_items.{$phaseKey}");
        }
```

`previewLiquidSoapCalculation()` already catches `InvalidArgumentException` into `status: invalid`; also catch `ValidationException` there the same way, using `collect($exception->errors())->flatten()->first()` as the message, so a step above 100% shows on the bench instead of failing the request.

- [ ] **Step 4: Add the warning strings**

In `lang/en/workbench.php`, under `'liquid' => ['warnings' => [...]]`, add:

```php
            'dilution_liquid_unused_first_dilution' => 'The first dilution adds no water, so its liquids are not used.',
            'dilution_liquid_unused_final_dilution' => 'The final dilution adds no water, so its liquids are not used.',
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapProcessPreviewTest.php tests/Unit/LiquidSoapProcessCalculatorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/RecipeWorkbenchPreviewService.php lang/en/workbench.php tests/Feature/LiquidSoapProcessPreviewTest.php
git commit -m "Preview liquid soap dilution liquids in their step water"
```

---

### Task 4: Save the dilution liquid rows

**Files:**
- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeWorkbenchService.php` (`validateLyeLiquidIngredients`)
- Test: `tests/Feature/LiquidSoapDilutionLiquidsTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LiquidSoapDilutionLiquidsTest.php`. The helper mirrors `liquidSoapBatchSheetDocument()` in `LiquidSoapBatchSheetTest`:

```php
use App\Enums\IngredientCategory;
use App\Enums\LiquidSoapNeutralizer;
use App\Models\Ingredient;
use App\Models\IngredientSapProfile;
use App\Models\ProductType;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Services\RecipeWorkbenchService;
use Illuminate\Validation\ValidationException;

/** @return array{user: User, family: ProductFamily, payload: array<string, mixed>, hydrosol: Ingredient} */
function dilutionLiquidsFormula(): array
{
    $user = User::factory()->create();
    $family = ProductFamily::factory()->liquidSoapPaste()->create();
    $productType = ProductType::factory()->create(['product_family_id' => $family->id]);
    $oil = Ingredient::factory()->create(['category' => IngredientCategory::Lipids, 'is_soap_saponification_trusted' => true, 'is_active' => true]);
    IngredientSapProfile::factory()->create(['ingredient_id' => $oil->id, 'koh_sap_value' => 0.19]);
    $citricAcid = Ingredient::factory()->create(['category' => IngredientCategory::PhAdjustersBuffers, 'owner_type' => null, 'owner_id' => null, 'is_active' => true, 'liquid_soap_neutralizer' => LiquidSoapNeutralizer::CitricAcidAnhydrous]);
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', 'is_active' => true]);

    return ['user' => $user, 'family' => $family, 'hydrosol' => $hydrosol, 'payload' => [
        'name' => 'Liquid castile', 'product_type_id' => $productType->id, 'oil_unit' => 'g', 'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula', 'exposure_mode' => 'rinse_off', 'regulatory_regime' => 'none', 'editing_mode' => 'percentage',
        'lye_type' => 'koh', 'koh_purity_percentage' => 90, 'dual_lye_koh_percentage' => 40, 'water_mode' => 'lye_concentration', 'water_value' => 28, 'superfat' => -3,
        'liquid_process' => ['first_dilution_percentage' => 35, 'final_dilution_percentage' => 25, 'neutralizer_solution_percentage' => 20],
        'phase_items' => [
            'saponified_oils' => [['ingredient_id' => $oil->id, 'percentage' => 100, 'weight' => 1000, 'note' => null]],
            'neutralization' => [['ingredient_id' => $citricAcid->id, 'percentage' => 0.65062, 'weight' => 6.5062, 'note' => null]],
            'additives' => [], 'fragrance' => [],
            'final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]],
        ],
    ]];
}

it('saves a dilution liquid as a % of its step water with its process weight', function () {
    ['user' => $user, 'family' => $family, 'payload' => $payload, 'hydrosol' => $hydrosol] = dilutionLiquidsFormula();

    $version = app(RecipeWorkbenchService::class)->save($user, $family, $payload);
    $phase = RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $version->id)->where('slug', 'final_dilution')->sole();
    $item = RecipeItem::withoutGlobalScopes()->where('recipe_phase_id', $phase->id)->sole();

    expect($item->ingredient_id)->toBe($hydrosol->id)
        ->and($item->percentage)->toBe('30.0000')
        ->and($item->weight)->toBe('398.2410')
        ->and((float) RecipeVersion::withoutGlobalScopes()->find($version->id)->calculation_context['totals']['oil_percentage'])->toBe(100.0);
});

it('refuses dilution liquids that replace more than all of a step water', function () {
    ['user' => $user, 'family' => $family, 'payload' => $payload, 'hydrosol' => $hydrosol] = dilutionLiquidsFormula();
    $other = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'is_active' => true]);
    $payload['phase_items']['final_dilution'][] = ['ingredient_id' => $other->id, 'percentage' => 80, 'weight' => 0, 'note' => null];

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(ValidationException::class);
});

it('refuses an alkali as a dilution liquid', function () {
    ['user' => $user, 'family' => $family, 'payload' => $payload] = dilutionLiquidsFormula();
    $koh = Ingredient::factory()->create(['category' => IngredientCategory::SoapmakingAlkalis, 'is_active' => true]);
    $payload['phase_items']['first_dilution'] = [['ingredient_id' => $koh->id, 'percentage' => 10, 'weight' => 0, 'note' => null]];

    expect(fn () => app(RecipeWorkbenchService::class)->save($user, $family, $payload))
        ->toThrow(ValidationException::class, __('workbench.validation.lye_liquid_alkali'));
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapDilutionLiquidsTest.php`
Expected: FAIL. The hydrosol is either rejected by the oils total or stored as % of oils.

- [ ] **Step 3: Keep the rows out of the % of oils maths**

In `RecipeWorkbenchPayloadNormalizer::phasePayload()`:

```php
            $rows = in_array($phase['key'], ['lye_water', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true)
                ? []
                : ($phaseItems[$phase['key']] ?? []);
```

- [ ] **Step 4: Allocate them after the process is known**

In `normalize()`, inside the `if ($productFamily?->usesLiquidSoapPasteBench())` block, after `withAdditionsFromFinalBatch(...)`:

```php
            $dilutionItems = $this->normalizeDilutionLiquidItems($payload, $normalizedRecipe, $liquidProcess);
```

Declare `$dilutionItems = [];` before that `if`. Extend the phase map that replaces `lye_water` items:

```php
            ->map(function (array $phase) use ($lyeLiquidItems, $dilutionItems): array {
                $items = match (true) {
                    $phase['key'] === 'lye_water' => $lyeLiquidItems,
                    array_key_exists($phase['key'], $dilutionItems) => $dilutionItems[$phase['key']],
                    default => null,
                };

                if ($items === null) {
                    return $phase;
                }

                return [
                    ...$phase,
                    'items' => $items,
                    'totals' => [
                        'percentage_of_oils' => 0.0,
                        'weight' => (float) $this->roundMass(
                            collect($items)->reduce(
                                fn (string $total, array $item): string => bcadd($total, NumberLocale::normalizeDecimalString($item['weight'] ?? null) ?? '0', 18),
                                '0',
                            ),
                        ),
                    ],
                ];
            })
```

Add the method next to `normalizeLyeLiquidItems()`:

```php
    /**
     * Liquid soap dilution liquids: rows stored as a % of their step's water
     * with the weight the saved process gives them.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $normalizedRecipe
     * @param  array<string, string>  $settings
     * @return array<string, list<array{ingredient_id: int|null, percentage: float, weight: float, note: mixed}>>
     */
    private function normalizeDilutionLiquidItems(array $payload, array $normalizedRecipe, array $settings): array
    {
        $phases = collect($normalizedRecipe['phases'])->keyBy('key');
        $neutralizerRow = collect($phases->get('neutralization')['items'] ?? [])->first(fn (array $item): bool => $item['ingredient_id'] !== null);
        $additionsWeight = collect(['additives', 'fragrance'])
            ->flatMap(fn (string $key): array => $phases->get($key)['items'] ?? [])
            ->reduce(fn (string $total, array $item): string => bcadd($total, NumberLocale::normalizeDecimalString((string) ($item['weight'] ?? 0)) ?? '0', 18), '0');
        $process = $this->liquidSoapFormulaProcessor->process(
            $this->liquidSoapProcessCalculator->pasteComposition($this->soapCalculation($payload, $normalizedRecipe)),
            $settings,
            $this->liquidSoapFormulaProcessor->neutralizer($neutralizerRow, $settings),
            $additionsWeight,
        );

        return collect($this->liquidSoapFormulaProcessor->dilutionLiquids($process, $payload['phase_items'] ?? []))
            ->map(function (array $step, string $phaseKey) use ($payload): array {
                $notes = collect($payload['phase_items'][$phaseKey] ?? [])->values();

                return collect($step['liquids'])
                    ->values()
                    ->map(fn (array $liquid, int $index): array => [
                        'ingredient_id' => $this->ingredientId($liquid['ingredient_id'] ?? null),
                        'percentage' => $liquid['percentage'],
                        'weight' => $liquid['weight'],
                        'note' => $notes->get($index)['note'] ?? null,
                    ])
                    ->all();
            })
            ->all();
    }
```

In the returned `phases` map, keep rows of the two phases even with a null ingredient filtered out like other phases (the existing filter already keeps only rows with an `ingredient_id`, which is correct here).

- [ ] **Step 5: Validate access and alkalis at save**

In `RecipeWorkbenchService::validateLyeLiquidIngredients()` (called from `save()` and `publish()`), after the existing `lye_water` validation, add:

```php
        foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
            $phase = collect($normalizedPayload['phases'] ?? [])->firstWhere('key', $phaseKey);

            if (is_array($phase)) {
                $this->lyeLiquidIngredientValidator->validate($phase['items'], $user, "phase_items.{$phaseKey}");
            }
        }
```

Read the existing method first and match how it reads `lye_water` rows (it may read them from `$normalizedPayload['phases']` the same way).

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapDilutionLiquidsTest.php tests/Feature/LiquidSoapFormulaPersistenceTest.php tests/Feature/SoapBenchBaselineTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/RecipeWorkbenchPayloadNormalizer.php app/Services/RecipeWorkbenchService.php tests/Feature/LiquidSoapDilutionLiquidsTest.php
git commit -m "Save liquid soap dilution liquids as a share of their step water"
```

---

### Task 5: Labels list the liquids, AQUA keeps the plain water

**Files:**
- Modify: `app/Services/InciGenerationService.php`
- Test: `tests/Feature/LiquidSoapIngredientListTest.php`

- [ ] **Step 1: Write the failing test**

Append to `LiquidSoapIngredientListTest.php` (reuse its helper that builds the reference formula and its labeling; read the top of the file for the names). Add the same hydrosol row as in Task 4 (`final_dilution`, 30%):

```php
it('lists a dilution liquid at its weight in the finished batch and keeps it out of AQUA', function () {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'inci_name' => 'LAVANDULA ANGUSTIFOLIA FLOWER WATER', 'is_active' => true]);
    $labeling = liquidSoapLabeling(['phase_items' => ['final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]]]]);

    foreach ($labeling['list_variants'] as $variant) {
        $rows = collect($variant['ingredient_rows'])->keyBy('label');

        expect((float) $rows['LAVANDULA ANGUSTIFOLIA FLOWER WATER']['weight'])->toEqualWithDelta(398.241, 0.001)
            ->and(collect($variant['ingredient_rows'])->sum('weight'))->toEqualWithDelta($labeling['basis']['formula_weight'] - ($variant['key'] === 'incorporated_ingredients' ? 0 : 21.744), 0.01);
    }
});
```

(21.744 g is the non-KOH share of the 217.44 g of 90% KOH flakes at −3% superfat, absent from the saponified list; if that file's existing assertions give a different impurity figure for its formula, use theirs.)

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapIngredientListTest.php`
Expected: FAIL, the hydrosol row weighs 0 and AQUA still holds its weight.

- [ ] **Step 3: Resolve the liquid weights from the process**

Next to `withResolvedLyeLiquidWeights()`, add and call it wherever that method is called (same arguments):

```php
    /**
     * Liquid soap dilution liquids weigh what the process gave them.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $soapCalculation
     * @return array<string, mixed>
     */
    private function withResolvedDilutionLiquidWeights(array $payload, ?array $soapCalculation): array
    {
        $split = $this->liquidProcess($soapCalculation)['dilution_liquids'] ?? null;

        if (! is_array($split)) {
            return $payload;
        }

        foreach (RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES as $phaseKey) {
            $weights = collect($split[$phaseKey]['liquids'] ?? [])->pluck('weight')->values();
            $position = 0;
            $payload['phase_items'][$phaseKey] = collect($payload['phase_items'][$phaseKey] ?? [])
                ->map(fn (mixed $row): mixed => is_array($row) && is_numeric($row['ingredient_id'] ?? null)
                    ? [...$row, 'weight' => (float) ($weights->get($position++) ?? 0)]
                    : $row)
                ->all();
        }

        return $payload;
    }
```

`bottledWaterWeight()` already reads `composition.water_weight`, which Task 3 reduced by the liquids. `addedWaterWeight()` uses `addedDilutionWaterWeight()`, fixed in Task 2. If the saponified/incorporated row builders skip phases they don't know, make sure rows of `first_dilution` and `final_dilution` are treated like `additives` rows (plain ingredient contexts at their weight): search the file for `'additives'` phase lists and add the two keys there.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapIngredientListTest.php tests/Feature/LiquidSoapBatchSheetTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/InciGenerationService.php tests/Feature/LiquidSoapIngredientListTest.php
git commit -m "List liquid soap dilution liquids on the label at their batch weight"
```

---

### Task 6: Costing prices the liquids and less water

**Files:**
- Modify: `app/Services/RecipeVersionCostPreviewBuilder.php`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/js/recipe-workbench/component.js` (`liquidDilutionWaterWeight`)
- Test: `tests/Feature/LiquidSoapCostingTest.php`, `tests/Feature/LiquidSoapBenchClientTest.php`

- [ ] **Step 1: Write the failing server test**

In `LiquidSoapCostingTest.php`, add a test that saves the existing reference formula with the Task 4 hydrosol row (30% of the final dilution), then reads the saved costing rows the same way the existing test does, and asserts:

```php
    expect((float) $rows->firstWhere('ingredient_id', $hydrosol->id)['quantity'])->toEqualWithDelta(398.241, 0.001)
        ->and((float) $rows->firstWhere('phase_key', 'implicit_water')['quantity'])->toEqualWithDelta($waterWithoutLiquid - 398.241, 0.001);
```

where `$waterWithoutLiquid` is the water quantity the existing test asserts for the same formula without the hydrosol (copy that number).

- [ ] **Step 2: Write the failing client test**

Append to `LiquidSoapBenchClientTest.php`:

```php
it('prices liquid soap dilution liquids from the preview and keeps them out of the water row', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.oilWeight = 1000;
liquid.costingBaseOilWeight = 1000;
liquid.costingWaterIngredient = { ingredient_id: 1, name: 'Water', default_price_per_kg: 0.1 };
liquid.phaseItems.final_dilution = [{ id: 'h1', ingredient_id: 7, name: 'Lavender hydrosol', percentage: 30 }];
liquid.backendCalculation = { liquid_process: {
    status: 'ok',
    stages: { first_dilution: { add_weight: '100' }, neutralization: { add_weight: '0' }, final_dilution: { add_weight: '200' } },
    neutralizer: null,
    dilution_liquids: { first_dilution: { water_weight: 100, liquids: [] }, final_dilution: { water_weight: 140, liquids: [{ id: 'h1', ingredient_id: 7, percentage: 30, weight: 60 }] } },
} };

assert.equal(liquid.costingWeightForRow('final_dilution', liquid.phaseItems.final_dilution[0]), 60);
assert.equal(liquid.liquidDilutionWaterWeight(), 240);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
```

- [ ] **Step 3: Run them to see them fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapCostingTest.php tests/Feature/LiquidSoapBenchClientTest.php`
Expected: FAIL.

- [ ] **Step 4: Server costing uses the stored weight**

In `RecipeVersionCostPreviewBuilder`, replace the two `$phaseKey === 'lye_water'` checks with a helper:

```php
    /** Rows whose percentage is not a % of oils: they cost their stored weight. */
    private function costsStoredWeight(string $phaseKey): bool
    {
        return in_array($phaseKey, ['lye_water', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true);
    }
```

```php
        $usesFormulaWeight = ($this->costsStoredWeight($phaseKey) || $isDerived) && bccomp($formulaBasisGrams, '0', 18) > 0;
        $displayPercentage = ($this->costsStoredWeight($phaseKey) || $isDerived) && bccomp($costingBasisGrams, '0', 18) > 0
```

The implicit water already goes through `addedDilutionWaterWeight()` (Task 2), which reads `dilution_liquids` from the recomputed calculation.

- [ ] **Step 5: Workbench costing uses the preview weight**

In `costing-section.js`, add the two phases to `SYSTEM_PHASE_TRANSLATION_KEYS`:

```js
    first_dilution: 'costing.phases.first_dilution',
    final_dilution: 'costing.phases.final_dilution',
```

and at the top of `costingWeightForRow(phaseKey, row)`:

```js
            if (phaseKey === 'first_dilution' || phaseKey === 'final_dilution') {
                const liquid = (this.liquidProcessPreview?.dilution_liquids?.[phaseKey]?.liquids ?? [])
                    .find((candidate) => candidate.id === row.id || Number(candidate.ingredient_id) === Number(row.ingredient_id));
                const formulaOilWeight = convertCostingMass(this.oilWeight, this.oilUnit, this.costingBaseOilUnit);

                return liquid && formulaOilWeight > 0
                    ? convertCostingMass(this.number(liquid.weight), this.oilUnit, this.costingBaseOilUnit) * (this.costingBaseOilWeight / formulaOilWeight)
                    : 0;
            }
```

Set `calculated: ['lye_water', 'first_dilution', 'final_dilution'].includes(phaseKey)` in `costingRowForPhaseItem`. In `component.js`, make `liquidDilutionWaterWeight()` use the plain water of each step:

```js
            const stepWater = (key) => {
                const split = process.dilution_liquids?.[key];

                return split ? this.number(split.water_weight) : this.number(process.stages[key].add_weight);
            };

            return stepWater('first_dilution') + stepWater('final_dilution') + Math.max(0, solutionWater);
```

Add the strings to `lang/en/workbench.php` under `'costing' => ['phases' => [...]]`: `'first_dilution' => 'First dilution'`, `'final_dilution' => 'Final dilution'`, and map them in `RecipeVersionCostPreviewBuilder::phaseName()` to `__('workbench.costing.phases.first_dilution')` / `final_dilution`.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapCostingTest.php tests/Feature/LiquidSoapBenchClientTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/RecipeVersionCostPreviewBuilder.php resources/js/recipe-workbench/sections/costing-section.js resources/js/recipe-workbench/component.js lang/en/workbench.php tests/Feature/LiquidSoapCostingTest.php tests/Feature/LiquidSoapBenchClientTest.php
git commit -m "Cost liquid soap dilution liquids and only the plain water"
```

---

### Task 7: Batch sheet steps say what to add

**Files:**
- Modify: `app/Services/FormulaDocumentBuilder.php` (`liquidProcessDocument`)
- Modify: `lang/en/formula_documents.php`
- Test: `tests/Feature/LiquidSoapBatchSheetTest.php`

- [ ] **Step 1: Write the failing test**

```php
it('lists the water and dilution liquids each step adds', function () {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    $document = liquidSoapBatchSheetDocument(['phase_items' => ['final_dilution' => [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]]]]);
    $final = collect($document['liquid_process']['steps'])->firstWhere('key', 'final_dilution');

    expect($final['instruction'])->toContain('Lavender hydrosol')->toContain('water');
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapBatchSheetTest.php`
Expected: FAIL.

- [ ] **Step 3: Build the step instructions**

In `liquidProcessDocument()`, add a closure and use it for both dilution steps:

```php
        $draftLiquids = fn (string $phaseKey) => collect(Arr::wrap(data_get($snapshot, "draft.phaseItems.{$phaseKey}", [])))
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['ingredient_id'] ?? null))
            ->values();
        $unit = (string) data_get($snapshot, 'draft.oilUnit', 'g');
        $mass = fn (float|string $value): string => NumberLocale::formatAdaptiveDecimal((float) $value, 2, 4, $locale).' '.$unit;
        $whatToAdd = function (string $phaseKey) use ($process, $draftLiquids, $mass): ?string {
            $split = $process['dilution_liquids'][$phaseKey] ?? null;

            if (! is_array($split) || $split['liquids'] === []) {
                return null;
            }

            $names = $draftLiquids($phaseKey);
            $parts = collect($split['liquids'])
                ->values()
                ->map(fn (array $liquid, int $index): string => $mass($liquid['weight']).' '.($names->get($index)['name'] ?? ''))
                ->prepend($mass($split['water_weight']).' '.__('formula_documents.liquid.water'))
                ->all();

            return implode(' + ', $parts);
        };
```

and for the two steps:

```php
                    'instruction' => ($liquids = $whatToAdd('first_dilution')) === null
                        ? __('formula_documents.liquid.instructions.first_dilution', ['target' => $target('first_dilution')])
                        : __('formula_documents.liquid.instructions.first_dilution_with_liquids', ['liquids' => $liquids, 'target' => $target('first_dilution')]),
```

```php
                    'instruction' => ($liquids = $whatToAdd('final_dilution')) === null
                        ? __('formula_documents.liquid.instructions.final_dilution', ['target' => $target('final_dilution')])
                        : __('formula_documents.liquid.instructions.final_dilution_with_liquids', ['liquids' => $liquids, 'target' => $target('final_dilution')]),
```

In `lang/en/formula_documents.php` under `liquid.instructions` add:

```php
            'first_dilution_with_liquids' => 'Add :liquids to bring the paste to :target% anhydrous soap.',
            'final_dilution_with_liquids' => 'Add the additions, then :liquids up to :target% anhydrous soap.',
```

and under `liquid`: `'water' => 'water',`.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapBatchSheetTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/FormulaDocumentBuilder.php lang/en/formula_documents.php tests/Feature/LiquidSoapBatchSheetTest.php
git commit -m "Print what each liquid soap dilution adds on the batch sheet"
```

---

### Task 8: Production plans the liquids and less water

**Files:**
- Modify: `app/Services/Production/ProductionRequirementBuilder.php`
- Modify: `app/Services/Production/ProductionFormulaSnapshotBuilder.php` (`liquidWaterLines`)
- Test: `tests/Feature/LiquidSoapProductionTest.php`

- [ ] **Step 1: Write the failing test**

`liquidSoapProduction()` builds its payload inline. Add an optional `array $finalDilutionLiquids = []` parameter that becomes `'final_dilution' => $finalDilutionLiquids` in `phase_items`, then:

```php
it('plans dilution liquids as materials and leaves only plain water in the dilution line', function (): void {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    $production = liquidSoapProduction(finalDilutionLiquids: [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]])['production'];
    $liquid = $production->requirements->firstWhere('ingredient_id', $hydrosol->id);
    $finalWater = $production->formulaLines->first(fn ($line): bool => $line->component === ProductionFormulaComponent::Water && $line->phase_key_snapshot === 'final_dilution');

    expect($liquid->phase_key_snapshot)->toBe('final_dilution')
        ->and((float) $liquid->required_mass_grams)->toEqualWithDelta((float) $finalWater->planned_mass_grams * 30 / 70, 0.01)
        ->and($production->formulaLines->sum(fn ($line): float => (float) $line->planned_mass_grams))->toEqualWithDelta(9520.0, 0.01);
});
```

(The hydrosol is created before `liquidSoapProduction()` runs, so the ingredient exists when the formula is published.)

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapProductionTest.php`
Expected: FAIL. The liquid requirement is scaled as % of oils (300 g per 1000 g) and the water line still holds the whole step.

- [ ] **Step 3: Requirements scale from the saved weight**

In `ProductionRequirementBuilder::build()`:

```php
                $scalesFromSavedWeight = in_array($phase->slug, ['lye_water', 'neutralization', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true);
                $requiredMass = $this->roundStorage(match (true) {
                    $phase->slug === 'neutralization' => $this->neutralizerDose($version, $item, $basisQuantityGrams)['mass_grams'],
                    $scalesFromSavedWeight => $this->scaledSavedMass($version, (string) $item->weight, $basisQuantityGrams),
                    default => bcdiv(bcmul($basisQuantityGrams, $percentage, self::GuardScale), '100', self::GuardScale),
                });
```

Add `use App\Services\RecipeWorkbenchPhaseBlueprints;`.

- [ ] **Step 4: Water lines exclude the liquids**

In `ProductionFormulaSnapshotBuilder::build()`, pass the requirements to `liquidWaterLines(...)` and subtract each step's liquid requirement masses (in grams, converted to the draft unit) from the step's water before building its line:

```php
    private function liquidWaterLines(array $process, string $unit, string $basisQuantityGrams, int $sortOrder, Collection $requirements): Collection
    {
        $liquidGrams = fn (string $phaseKey): string => $requirements
            ->filter(fn (array $requirement): bool => ($requirement['kind'] ?? null) === 'ingredient' && ($requirement['phase_key_snapshot'] ?? null) === $phaseKey)
            ->reduce(fn (string $total, array $requirement): string => bcadd($total, (string) $requirement['required_mass_grams'], self::GuardScale), '0');
        $stepWater = fn (string $phaseKey): string => bcsub(
            $this->massConverter->toGrams((string) data_get($process, "stages.{$phaseKey}.add_weight", '0'), $unit),
            $liquidGrams($phaseKey),
            self::GuardScale,
        );
        $neutralizer = is_array($process['neutralizer'] ?? null) ? $process['neutralizer'] : null;
        $neutralizerWater = $neutralizer === null
            ? '0'
            : bcsub((string) $neutralizer['solution_weight'], (string) $neutralizer['weight'], self::GuardScale);
```

and in the candidates use grams directly (the masses below are already grams, so skip the `toGrams` conversion for these two):

```php
            ['phase' => 'first_dilution', 'name' => 'water', 'grams' => $stepWater('first_dilution')],
            ['phase' => 'neutralization', 'name' => 'neutralizer_solution_water', 'grams' => $this->massConverter->toGrams($neutralizerWater, $unit)],
            ['phase' => 'final_dilution', 'name' => 'water', 'grams' => $stepWater('final_dilution')],
```

with `if (bccomp($candidate['grams'], '0', self::GuardScale) <= 0) { continue; }` and `$mass = $this->roundStorage($candidate['grams']);` in the loop (replacing the old `weight` check and `toGrams` call). Update the call site: `$this->liquidWaterLines($liquidProcess, $unit, $basisQuantityGrams, ((int) $lines->max('sort_order')) + 1, $requirements)`.

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapProductionTest.php tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionRunPrintTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Production tests/Feature/LiquidSoapProductionTest.php
git commit -m "Plan liquid soap dilution liquids as materials in production"
```

---

### Task 9: Sharing round-trips the liquids

**Files:**
- Modify: `app/Services/FormulaSharePreview.php` (`resolve`)
- Modify: `app/Services/FormulaShareRecipeImporter.php` (`preserveQuantities`)
- Test: `tests/Feature/LiquidSoapFormulaShareTest.php`

- [ ] **Step 1: Write the failing test**

Give `sharedLiquidSoap()` an optional `array $finalDilution = []` merged into `phase_items`, create a platform hydrosol in the test, and:

```php
it('round trips liquid soap dilution liquids', function (): void {
    $hydrosol = Ingredient::factory()->create(['category' => IngredientCategory::WaterSolventsCarriers, 'display_name' => 'Lavender hydrosol', 'is_active' => true]);
    ['owner' => $owner, 'recipient' => $recipient, 'recipe' => $recipe] = sharedLiquidSoap(finalDilution: [['ingredient_id' => $hydrosol->id, 'percentage' => 30, 'weight' => 0, 'note' => null]]);

    $copy = savedLiquidSoapVersion(sendAndAcceptLiquidSoap($owner, $recipe, $recipient));
    $phase = RecipePhase::withoutGlobalScopes()->where('recipe_version_id', $copy->id)->where('slug', 'final_dilution')->sole();

    expect(RecipeItem::withoutGlobalScopes()->where('recipe_phase_id', $phase->id)->get(['ingredient_id', 'percentage'])->toArray())
        ->toBe([['ingredient_id' => $hydrosol->id, 'percentage' => '30.0000']]);
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapFormulaShareTest.php`
Expected: FAIL in `preserveQuantities` (the % of oils weight check rejects the row).

- [ ] **Step 3: Skip the % of oils check and validate the rows**

In `FormulaShareRecipeImporter::preserveQuantities()`:

```php
            if (in_array($phase['key'], ['lye_water', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true) && ($context['calculation_basis'] ?? null) !== 'total_formula') {
                continue;
            }
```

In `FormulaSharePreview::resolve()`, extend the `lye_water` branch:

```php
                if (in_array($phase['slug'], ['lye_water', ...RecipeWorkbenchPhaseBlueprints::DILUTION_PHASES], true)) {
                    $field = "phase_items.{$phase['slug']}";
                    $this->dilutionLiquids->assertMaximumRows($phase['items'], $field);
```

and pass `$field` to the `validate($localRows, $actor, $field)` call in the same block.

- [ ] **Step 4: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapFormulaShareTest.php tests/Feature/FormulaShareRoundTripTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/FormulaSharePreview.php app/Services/FormulaShareRecipeImporter.php tests/Feature/LiquidSoapFormulaShareTest.php
git commit -m "Share liquid soap dilution liquids"
```

---

### Task 10: Bench screen — liquids per dilution step

**Files:**
- Modify: `resources/js/recipe-workbench/catalog.js` (`resolveTargetPhase`)
- Modify: `resources/js/recipe-workbench/payload.js` (`serializeRow`)
- Modify: `resources/js/recipe-workbench/component.js` (liquid section helpers, `LIQUID_COMPOSITION_ORDER`/`COLORS`)
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/liquid-soap-process.blade.php`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/LiquidSoapBenchClientTest.php`, `tests/Feature/LiquidSoapFormulaTabTest.php`

- [ ] **Step 1: Write the failing client test**

```php
it('adds dilution liquids to a step and sends them as a share of its water', function () {
    $process = runLiquidSoapBenchScript(<<<'JS'
const liquid = bench(liquidFamily);
liquid.ingredients = [{ id: 7, name: 'Lavender hydrosol', category: 'water_solvents_carriers', available_phases: ['additives'] }];
liquid.addDilutionLiquid('final_dilution', 7);
liquid.phaseItems.final_dilution[0].percentage = 30;

assert.equal(liquid.dilutionLiquidRows('final_dilution').length, 1);
assert.equal(liquid.dilutionLiquidShareTotal('final_dilution'), 30);
assert.deepEqual(serializeDraft(liquid).phase_items.final_dilution.map(({ ingredient_id, percentage, weight }) => ({ ingredient_id, percentage, weight })), [{ ingredient_id: 7, percentage: 30, weight: 0 }]);

const bar = bench(barFamily);
assert.equal(Object.hasOwn(serializeDraft(bar).phase_items, 'final_dilution'), false);
JS);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
});
```

Note: `phaseItems` keys come from the phase blueprints in the payload; if `bench(liquidFamily)` builds no blueprints in this harness, set `liquid.phaseItems.final_dilution = []` before calling `addDilutionLiquid` and keep the assertion about bar soap.

- [ ] **Step 2: Run it to see it fail**

Run: `php artisan test --compact tests/Feature/LiquidSoapBenchClientTest.php`
Expected: FAIL, `addDilutionLiquid` is undefined.

- [ ] **Step 3: JS state and serialisation**

`catalog.js` `resolveTargetPhase()`:

```js
    if (['lye_water', 'first_dilution', 'final_dilution'].includes(requestedPhase)) {
        return requestedPhase;
    }
```

`payload.js` `serializeRow()`, so the server sizes these rows:

```js
        weight: phaseKey === 'lye_water'
            ? serializedDilutionLiquidWeight(state, row)
            : (phaseKey === 'first_dilution' || phaseKey === 'final_dilution' ? 0 : rowWeight(state, row)),
```

`component.js`, inside the liquid soap section object (next to `chooseLiquidNeutralizer`):

```js
        dilutionLiquidRows(phaseKey) {
            return this.phaseItems?.[phaseKey] ?? [];
        },

        dilutionLiquidShareTotal(phaseKey) {
            return this.dilutionLiquidRows(phaseKey).reduce((total, row) => total + Math.max(0, this.number(row.percentage)), 0);
        },

        /** Adds a non-alkali liquid to a dilution step, up to four per step. */
        addDilutionLiquid(phaseKey, ingredientId) {
            const ingredient = (this.ingredients ?? []).find((candidate) => Number(candidate.id) === Number(ingredientId));

            if (!ingredient || ingredient.category === 'soapmaking_alkalis' || this.dilutionLiquidRows(phaseKey).length >= 4) {
                return;
            }

            if (!Array.isArray(this.phaseItems[phaseKey])) {
                this.phaseItems[phaseKey] = [];
            }

            this.addIngredient(ingredient, phaseKey, false);
        },

        /** A liquid's grams from the server preview, 0 until it arrives. */
        dilutionLiquidWeight(phaseKey, row) {
            const liquid = (this.liquidProcessPreview?.dilution_liquids?.[phaseKey]?.liquids ?? [])
                .find((candidate) => candidate.id === row.id || Number(candidate.ingredient_id) === Number(row.ingredient_id));

            return liquid ? this.number(liquid.weight) : 0;
        },

        dilutionWaterWeight(phaseKey) {
            return this.number(this.liquidProcessPreview?.dilution_liquids?.[phaseKey]?.water_weight);
        },
```

Add `'dilution_liquids_weight'` after `'water_weight'` in `LIQUID_COMPOSITION_ORDER` and `dilution_liquids_weight: 'var(--color-sky, var(--color-line-strong))'` to `LIQUID_COMPOSITION_COLORS` (check `resources/css/app.css` for an existing soft blue token and use it).

- [ ] **Step 4: Blade liquids lines**

In `liquid-soap-process.blade.php`, compute search options at the top like `formula-settings.blade.php` does:

```blade
@php
    $dilutionLiquidOptions = collect($workbench['ingredients'] ?? [])
        ->reject(fn (array $ingredient): bool => ($ingredient['category'] ?? null) === 'soapmaking_alkalis')
        ->map(fn (array $ingredient): array => [
            'id' => $ingredient['id'],
            'label' => $ingredient['name'],
            'description' => $ingredient['inci_name'] ?? null,
            'searchText' => implode(' ', array_filter([$ingredient['name'], $ingredient['inci_name'] ?? null])),
        ])
        ->values()
        ->all();
@endphp
```

After the four settings inputs, add one block per step:

```blade
 @foreach (['first_dilution', 'final_dilution'] as $dilutionStep)
 <section class="sk-inset space-y-3 p-4" aria-labelledby="liquid-{{ $dilutionStep }}-liquids-heading">
 <div class="flex flex-wrap items-baseline justify-between gap-3">
 <p id="liquid-{{ $dilutionStep }}-liquids-heading" class="sk-eyebrow">{{ __('workbench.liquid.dilution_liquids.'.$dilutionStep) }}</p>
 <p class="numeric text-xs text-[var(--color-ink-soft)]" x-text="t('liquid.dilution_liquids.water_left', { percentage: format(Math.max(0, 100 - dilutionLiquidShareTotal('{{ $dilutionStep }}')), 2), weight: format(dilutionWaterWeight('{{ $dilutionStep }}'), calculatedMassDecimals(dilutionWaterWeight('{{ $dilutionStep }}'))), unit: oilUnit })"></p>
 </div>
 <template x-for="row in dilutionLiquidRows('{{ $dilutionStep }}')" :key="row.id">
 <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem_8rem_2.5rem] sm:items-center">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.name"></p>
 <label class="flex items-center gap-2">
 <input x-model="row.percentage" @blur="normalizeDecimalBlur($event); row.percentage = format(clampPercentage($event.target.value), 2)" type="text" inputmode="decimal" :aria-label="t('liquid.dilution_liquids.share_for', { ingredient: row.name })" class="sk-input numeric" />
 <span class="text-sm text-[var(--color-ink-soft)]">%</span>
 </label>
 <span class="numeric text-right text-sm text-[var(--color-ink-strong)]" x-text="`${format(dilutionLiquidWeight('{{ $dilutionStep }}', row), calculatedMassDecimals(dilutionLiquidWeight('{{ $dilutionStep }}', row)))} ${oilUnit}`"></span>
 <button type="button" @click="removeIngredient('{{ $dilutionStep }}', row.id)" class="grid size-10 place-items-center rounded-md text-[var(--color-ink-soft)] hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)]" :aria-label="t('liquid.dilution_liquids.remove', { ingredient: row.name })"><x-action-icon name="close" /></button>
 </div>
 </template>
 <div x-show="dilutionLiquidRows('{{ $dilutionStep }}').length < 4">
 <x-search-combobox
 id="liquid-{{ $dilutionStep }}-liquid-search"
 :inline-options="true"
 class="sk-combobox-reference"
 :label="__('workbench.liquid.dilution_liquids.add')"
 :options="$dilutionLiquidOptions"
 :placeholder="__('workbench.liquid.dilution_liquids.search')"
 x-on:search-combobox-selected="addDilutionLiquid('{{ $dilutionStep }}', $event.detail.id)"
 />
 </div>
 <template x-if="dilutionLiquidShareTotal('{{ $dilutionStep }}') > 100.00001">
 <p role="alert" class="text-xs text-[var(--color-danger-strong)]">{{ __('workbench.liquid.dilution_liquids.exceeds') }}</p>
 </template>
 </section>
 @endforeach
```

Add the strings to `lang/en/workbench.php` under `liquid`:

```php
        'dilution_liquids' => [
            'first_dilution' => 'First dilution liquids',
            'final_dilution' => 'Final dilution liquids',
            'add' => 'Add a liquid',
            'search' => 'Search hydrosols, milks, juices…',
            'share_for' => 'Share of the step water for :ingredient',
            'remove' => 'Remove :ingredient',
            'water_left' => 'Water: :percentage% · :weight :unit',
            'exceeds' => 'The liquids replace more than all of this step water.',
        ],
```

and under `liquid.components`: `'dilution_liquids_weight' => 'Dilution liquids',`.

- [ ] **Step 5: Markup check**

In `LiquidSoapFormulaTabTest::liquidSoapFormulaTabMarkup()`, add `'dilution_liquids' => $document->getElementById('liquid-final_dilution-liquids-heading') !== null,` and expect `true` on the liquid bench and `false` on the bar soap bench.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/LiquidSoapBenchClientTest.php tests/Feature/LiquidSoapFormulaTabTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add resources/js/recipe-workbench resources/views/livewire/dashboard/partials/recipe-workbench/liquid-soap-process.blade.php lang/en/workbench.php tests/Feature/LiquidSoapBenchClientTest.php tests/Feature/LiquidSoapFormulaTabTest.php
git commit -m "Let the liquid soap bench add liquids to each dilution"
```

---

### Task 11: Translations, docs and the full suite

**Files:**
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `docs/superpowers/plans/2026-10-15-liquid-soap-paste-calculator.md` (remove "dilution liquids other than water" from Out of scope; add a line pointing to this plan)

- [ ] **Step 1: Catalogue rows**

Every new `lang/en` key from Tasks 3, 6, 7 and 10 (`workbench.liquid.warnings.dilution_liquid_unused_*`, `workbench.costing.phases.first_dilution|final_dilution`, `workbench.liquid.dilution_liquids.*`, `workbench.liquid.components.dilution_liquids_weight`, `formula_documents.liquid.instructions.*_with_liquids`, `formula_documents.liquid.water`) gets a six-locale row (de, es, fr, it, nl, pt_BR) in `interface-translations.json`, kept sorted by group then key. Use a script that appends rows and sorts with `(group, key)` and writes with two-space indentation and unescaped Unicode, as earlier commits on this branch did.

- [ ] **Step 2: Run the catalogue tests**

Run: `php artisan test --compact $(grep -rln interface-translations.json tests | tr '\n' ' ')`
Expected: PASS.

- [ ] **Step 3: Full suite and graph**

Run: `vendor/bin/pint --dirty --format agent`, then `php artisan test --compact --parallel`
Expected: all pass (66 skipped PostgreSQL sharing tests). Then `graphify update .`.

- [ ] **Step 4: Commit**

```bash
git add database/seeders/data/interface-translations.json docs/superpowers/plans/2026-10-15-liquid-soap-paste-calculator.md
git commit -m "Translate liquid soap dilution liquids and update the plan"
```
