# Production Run Lifecycle and Page Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make production runs progress through a clear manual lifecycle, merge formula and stock information into one operational page, stock-track calculated lye, record real material and output quantities, and protect deletion and release invariants at every entry point.

**Architecture:** Keep `ProductionRunStatus` unchanged in storage and translate it into customer-facing lifecycle labels. Build one immutable formula/material snapshot at creation, add lot-backed requirements for calculated NaOH/KOH, keep water as a non-stock formula actual, and assemble the detail page through a dedicated presenter. All stock mutations remain transactional and follow `workspace → run → requirements → lots → reservations`; production completion and output-lot release remain separate state transitions.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, PostgreSQL 17, SQLite test database, Pest 4, Blade, Tailwind CSS 4, Laravel translation loader.

---

## Implementation constraints

- Work in an isolated `codex/production-lifecycle-redesign` worktree because the current worktree contains unrelated ingredient-catalog changes.
- Before each framework-facing task, use Laravel Boost `search-docs` when available with broad queries for the APIs being changed. If Boost is unavailable, inspect the installed vendor signatures and existing sibling code; do not browse version-mismatched examples.
- Do not edit already-run migrations. Both schema changes below are forward migrations.
- Do not backfill current production runs with lye requirements or calculated actuals.
- Keep the stored enum values `scheduled` and `reserved`; only their customer labels become **Planned** and **Stock prepared**.
- Do not introduce an Allocated state, material substitution, or wastage tables in this implementation.
- Treat actual output variance as yield information only. Planned 288 and actual 283 is valid and displays `-5` and `-1.74%`; it does not create a waste record.
- Keep non-English interface translations in `database/seeders/data/interface-translations.json`. The project intentionally does not maintain non-English `production_bench.php` files.

## Task 1: Enforce the date boundary between Draft and Planned

**Files:**

- Modify: `tests/Feature/ProductionBenchProductionCreateTest.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionCreate.php`
- Modify: `app/Actions/Production/PlanProduction.php`
- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `lang/en/production_bench.php`

- [ ] **Step 1: Add failing Livewire and action tests**

Add one UI test proving `plan` rejects an empty `plannedFor` on that field while `saveDraft` still creates a Draft run with `planned_for = null`. Add one direct-action test proving an alternate caller cannot create `ProductionRunStatus::Scheduled` without a date.

```php
Livewire::actingAs($fixture['owner'])
    ->test(ProductionCreate::class)
    ->set('recipeId', (string) $fixture['recipe']->id)
    ->set('basisInputValue', '12')
    ->set('basisInputUnit', 'kg')
    ->set('expectedUnits', '288')
    ->set('plannedFor', '')
    ->call('plan')
    ->assertHasErrors(['plannedFor' => 'required']);

expect(fn () => app(CreateProductionDraft::class)->handle(
    actor: $fixture['owner'],
    workspace: $fixture['workspace'],
    recipe: $fixture['recipe'],
    basisInputValue: '12',
    basisInputUnit: MassUnit::Kilogram,
    expectedUnits: 288,
    idempotencyKey: 'planned-without-date',
    plannedFor: null,
    status: ProductionRunStatus::Scheduled,
))->toThrow(ValidationException::class);
```

- [ ] **Step 2: Run the focused tests and confirm failure**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionPlanningTest.php
```

Expected: the new missing-date assertions fail because planning currently accepts `null`.

- [ ] **Step 3: Implement the invariant at both boundaries**

In `ProductionCreate::plan()`, use:

```php
'plannedFor' => ['required', 'date_format:Y-m-d'],
```

Pass the validated string directly to `PlanProduction`. Change `PlanProduction::handle()` to require `string $plannedFor` with no default. Keep `CreateProductionDraft::$plannedFor` nullable because it also owns the Draft path, but add this before date-format validation:

```php
if ($status === ProductionRunStatus::Scheduled && $plannedFor === null) {
    throw ValidationException::withMessages([
        'planned_for' => __('production_bench.production.validation.planned_date_required'),
    ]);
}
```

Use a translation key for the working-day and invalid-format messages touched in this path. Update named callers such as Flash planning so every Scheduled creation supplies a date.

- [ ] **Step 4: Re-run focused tests**

Run the same command. Expected: all tests pass.

- [ ] **Step 5: Commit the date invariant**

```bash
git add app/Actions/Production/CreateProductionDraft.php app/Actions/Production/PlanProduction.php app/Livewire/ProductionBench/Production/ProductionCreate.php lang/en/production_bench.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "fix: require dates for planned productions"
```

## Task 2: Persist actual quantities for non-stock calculated materials

**Files:**

- Create: `database/migrations/2026_08_10_170000_add_actual_mass_to_production_formula_lines.php`
- Modify: `app/Models/ProductionFormulaLine.php`
- Modify: `database/factories/ProductionFormulaLineFactory.php`
- Modify: `tests/Feature/ProductionFormulaSnapshotTest.php`

- [ ] **Step 1: Add a failing schema/model test**

Assert the new column exists, is nullable for old and planned runs, and casts to the existing nine-decimal canonical scale.

```php
expect(Schema::hasColumn('production_formula_lines', 'actual_mass_grams'))->toBeTrue();

$line = ProductionFormulaLine::factory()->create([
    'actual_mass_grams' => '283.125',
]);

expect($line->actual_mass_grams)->toBe('283.125000000');
```

- [ ] **Step 2: Run the test and confirm failure**

```bash
php artisan test --compact tests/Feature/ProductionFormulaSnapshotTest.php
```

Expected: failure because `actual_mass_grams` does not exist.

- [ ] **Step 3: Create the forward migration and model mapping**

Generate the migration with Artisan, then use the exact filename above in this branch:

```bash
php artisan make:migration add_actual_mass_to_production_formula_lines --table=production_formula_lines --no-interaction
```

Immediately rename Artisan's generated file to `database/migrations/2026_08_10_170000_add_actual_mass_to_production_formula_lines.php` before editing it.

Migration body:

```php
public function up(): void
{
    Schema::table('production_formula_lines', function (Blueprint $table): void {
        $table->decimal('actual_mass_grams', 20, 9)->nullable()->after('planned_mass_grams');
    });
}

public function down(): void
{
    Schema::table('production_formula_lines', function (Blueprint $table): void {
        $table->dropColumn('actual_mass_grams');
    });
}
```

Add `actual_mass_grams` to `#[Fillable]` and cast it as `decimal:9`. Add a nullable factory default. Do not write any data migration.

- [ ] **Step 4: Re-run the focused test**

Expected: pass.

- [ ] **Step 5: Commit the schema change**

```bash
git add database/migrations/2026_08_10_170000_add_actual_mass_to_production_formula_lines.php app/Models/ProductionFormulaLine.php database/factories/ProductionFormulaLineFactory.php tests/Feature/ProductionFormulaSnapshotTest.php
git commit -m "feat: store calculated production actuals"
```

## Task 3: Turn calculated NaOH and KOH into stock requirements

**Files:**

- Create: `app/Services/Production/ProductionLyeMaterialResolver.php`
- Create: `app/Services/Production/ProductionCalculatedRequirementBuilder.php`
- Create: `tests/Feature/ProductionCalculatedMaterialsTest.php`
- Modify: `app/Services/Production/ProductionFormulaSnapshotBuilder.php`
- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `tests/Feature/ProductionFormulaSnapshotTest.php`

- [ ] **Step 1: Add failing calculated-material tests**

Seed or factory-create platform ingredients with `catalog_key` values `CH1` and `CH3`. Cover these cases:

- an NaOH formula line stores the `CH1` ingredient id and produces one matching ingredient requirement;
- a KOH formula line stores the `CH3` ingredient id and produces one matching requirement;
- mixed lye produces both requirements;
- water remains a formula line with `ingredient_id = null` and produces no requirement;
- a non-soap/total-formula production creates no calculated lye requirement;
- missing `CH1` or `CH3` fails creation atomically with a translated validation error.

The core assertion is:

```php
expect($production->formulaLines->firstWhere('component', ProductionFormulaComponent::Naoh)?->ingredient_id)
    ->toBe($naoh->id)
    ->and($production->requirements->firstWhere('ingredient_id', $naoh->id)?->required_mass_grams)
    ->toBe($production->formulaLines->firstWhere('component', ProductionFormulaComponent::Naoh)?->planned_mass_grams)
    ->and($production->requirements->contains(fn (ProductionRequirement $requirement): bool =>
        $requirement->subject_name_snapshot === 'Water'))
    ->toBeFalse();
```

- [ ] **Step 2: Run the tests and confirm failure**

```bash
php artisan test --compact tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionFormulaSnapshotTest.php
```

Expected: calculated lye lines have no ingredient identity and no requirements.

- [ ] **Step 3: Implement the stable catalog resolver**

`ProductionLyeMaterialResolver` must query by identity, never translated display text:

```php
public function forComponent(ProductionFormulaComponent $component): ?Ingredient
{
    $catalogKey = match ($component) {
        ProductionFormulaComponent::Naoh => 'CH1',
        ProductionFormulaComponent::Koh => 'CH3',
        default => null,
    };

    if ($catalogKey === null) {
        return null;
    }

    $ingredient = Ingredient::query()
        ->withoutGlobalScopes()
        ->whereNull('workspace_id')
        ->where('catalog_key', $catalogKey)
        ->first();

    if (! $ingredient instanceof Ingredient) {
        throw ValidationException::withMessages([
            'recipe' => __('production_bench.production.validation.lye_material_missing', ['key' => $catalogKey]),
        ]);
    }

    return $ingredient;
}
```

Inject it into `ProductionFormulaSnapshotBuilder` and assign the resolved id/name to NaOH and KOH lines. Water remains unresolved.

- [ ] **Step 4: Build requirements from the completed formula snapshot**

`ProductionCalculatedRequirementBuilder::build(Collection $formulaLines, int $startingSortOrder): Collection` returns requirement attribute arrays only for NaOH/KOH lines. Preserve the calculated mass, formula percentage, phase snapshots, note, gram unit, and deterministic sort order. Set `recipe_item_id = null` because these are calculated lines.

In `CreateProductionDraft`, build explicit requirements, build the complete formula snapshot, then merge calculated requirements before `createMany()`:

```php
$requirements = $requirements->concat(
    $this->calculatedRequirementBuilder->build(
        formulaLines: $formulaSnapshot['lines'],
        startingSortOrder: $requirements->max('sort_order') + 1,
    ),
)->values();
```

Keep formula lines and requirements in the same creation transaction so missing catalog materials leave no production row.

- [ ] **Step 5: Re-run the tests**

Expected: all calculated-material and snapshot tests pass.

- [ ] **Step 6: Commit lye inventory integration**

```bash
git add app/Actions/Production/CreateProductionDraft.php app/Services/Production/ProductionCalculatedRequirementBuilder.php app/Services/Production/ProductionFormulaSnapshotBuilder.php app/Services/Production/ProductionLyeMaterialResolver.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionFormulaSnapshotTest.php
git commit -m "feat: stock track calculated production lye"
```

## Task 4: Preserve reservation semantics and canonical locking

**Files:**

- Modify: `app/Actions/Production/PrepareProductionStock.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`
- Modify: `tests/Feature/ProductionRunBatchNumberingTest.php`

- [ ] **Step 1: Add failing lifecycle coverage tests for lye**

Create a soap run with its ordinary materials fully available but NaOH only partially available. Assert the partial quantity is hard-reserved and the run remains `Scheduled`. Add the remaining NaOH lot, prepare again, and assert status becomes `Reserved`. Assert release deactivates all reservations and returns the run to `Scheduled`.

- [ ] **Step 2: Add a lock-order regression test**

Follow the existing `DB::listen()` convention in `ProductionRunBatchNumberingTest`. On PostgreSQL, record `FOR UPDATE` statements and assert the workspace lock index precedes the production-run lock, which precedes requirements, lots, and reservations.

```php
expect($workspaceLock)->toBeLessThan($runLock)
    ->and($runLock)->toBeLessThan($requirementLock)
    ->and($requirementLock)->toBeLessThan($lotLock)
    ->and($lotLock)->toBeLessThan($reservationLock);
```

Skip only the SQL-order assertion on SQLite; run the behavior assertions on both databases.

- [ ] **Step 3: Run tests and confirm the order test fails**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionRunBatchNumberingTest.php
```

Expected: current `PrepareProductionStock` locks runs before the workspace.

- [ ] **Step 4: Reorder transaction locks**

Inside `PrepareProductionStock`, lock in this exact sequence:

1. workspace;
2. selected production runs ordered by id;
3. their requirements ordered by id;
4. matching stock lots ordered by id;
5. competing active reservations ordered by id.

Retain `attempts: 5`, all workspace/status checks after locking, partial FEFO behavior, and idempotency behavior.

- [ ] **Step 5: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionRunBatchNumberingTest.php
git add app/Actions/Production/PrepareProductionStock.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionRunBatchNumberingTest.php
git commit -m "fix: standardize production stock lock order"
```

## Task 5: Default and save actual material usage atomically

**Files:**

- Modify: `app/Actions/Production/StartProduction.php`
- Modify: `app/Actions/Production/SaveProductionActuals.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Add failing execution tests**

Cover:

- Start copies each active reservation into the page defaults without posting stock movements.
- Start sets the water line's `actual_mass_grams` to `planned_mass_grams`.
- Saving actuals can change water and lot-backed rows in one transaction.
- Invalid water rolls back both water and lot-backed edits.
- Saving is allowed only In production.
- Completion rejects a missing, zero, or negative water actual when a water line exists.
- Completion posts only lot-backed actuals; water creates no stock movement.
- Completed and Aborted runs cannot change water actuals.

Use a request shape that keeps the two domains explicit:

```php
$saveProductionActuals->handle(
    actor: $owner,
    production: $production,
    rows: $lotBackedRows,
    calculatedRows: [
        ['production_formula_line_id' => $waterLine->id, 'actual_mass_grams' => '1540.5'],
    ],
);
```

- [ ] **Step 2: Run the execution tests and confirm failure**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Initialize water when production starts**

After locking workspace and run, lock calculated formula lines ordered by id. For `ProductionFormulaComponent::Water`, set `actual_mass_grams = planned_mass_grams` only when the actual is null. Do not create `ProductionConsumption` or `StockMovement` rows at Start.

- [ ] **Step 4: Extend the actual-saving transaction**

Add `array $calculatedRows = []` to `SaveProductionActuals::handle()`. Validate every formula line belongs to the locked production, is `Water`, and has a positive decimal actual. Update these rows in the same transaction as `production_consumption` upserts.

Use translation keys for all new validation messages. Keep requirement/lot subject matching and whole-unit packaging validation unchanged.

- [ ] **Step 5: Enforce completion readiness**

In `ProductionCompletionService::assertCompletable()`, require every Water line to have `actual_mass_grams > 0`. Do not add water to ingredient totals or movement posting. The formula-line actual becomes immutable naturally because the save action rejects every status except In production.

- [ ] **Step 6: Update Livewire state mapping**

Add a `calculatedActualRows` property keyed by formula-line id. Load persisted water actuals and pass normalized values to `SaveProductionActuals`. After save, reload both state arrays from the fresh run.

- [ ] **Step 7: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
git add app/Actions/Production/SaveProductionActuals.php app/Actions/Production/StartProduction.php app/Livewire/ProductionBench/Production/ProductionDetail.php app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: record defaulted production actuals"
```

## Task 6: Reconcile planned and actual output at completion

**Files:**

- Create: `app/Services/Production/ProductionOutputReconciliation.php`
- Create: `tests/Feature/ProductionOutputReconciliationTest.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Add failing output reconciliation tests**

For finished output, complete a plan of 288 with 283 actual units and assert:

```php
expect($production->refresh()->expected_units)->toBe(288)
    ->and($production->actual_output_units)->toBe(283)
    ->and($production->outputLot->initial_quantity)->toBe('283.000000000');
```

Assert the detail view exposes planned `288`, actual `283`, signed variance `-5`, and percentage `-1.74%`. Add positive and zero-variance cases. Add the equivalent mass assertions for intermediate output using `basis_quantity_grams` and `actual_output_mass_grams`.

- [ ] **Step 2: Run the tests**

The persistence assertion should already pass; the presentation assertions should fail. This confirms the implementation is exposing an existing domain capability rather than adding duplicate output storage.

- [ ] **Step 3: Add one output reconciliation service**

Put comparison arithmetic in `ProductionOutputReconciliation`, not Livewire or Blade. The summary shape must be:

```php
[
    'unit' => 'unit',
    'planned' => '288',
    'actual' => '283',
    'variance' => '-5',
    'variance_percentage' => '-1.74',
]
```

For intermediate output use grams. Return `null` percentage only when planned quantity is zero. Preserve the actual-output validation already in `ProductionCompletionService`: finished products are positive whole units; intermediates are positive decimals.

- [ ] **Step 4: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionBenchProductionsTest.php
git add app/Livewire/ProductionBench/Production/ProductionDetail.php app/Services/Production/ProductionCompletionService.php app/Services/Production/ProductionOutputReconciliation.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionOutputReconciliationTest.php
git commit -m "feat: show production output variance"
```

## Task 7: Gate output release on date and task completion

**Files:**

- Modify: `app/Actions/Production/ReleaseOutputLot.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`
- Modify: `lang/en/production_bench.php`

- [ ] **Step 1: Add failing release-gate tests**

Cover all four combinations:

| `available_from` reached | all tasks complete | result |
| --- | --- | --- |
| no | no | reject for date first |
| no | yes | reject for date |
| yes | no | reject and name/count incomplete tasks |
| yes | yes | release |

Also prove a run with no tasks releases after its family ready date and that completing production while tasks remain pending is allowed.

- [ ] **Step 2: Run the execution test and confirm pending tasks do not currently block release**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement release with deterministic locks**

Resolve the originating run through `stock_lots.production_run_id`. In one transaction lock:

1. workspace;
2. production run;
3. production tasks ordered by id;
4. output lot.

Require `ProductionRunStatus::Completed`, production-output origin, quarantined lot status, reached `available_from`, and no task with `completed_at = null`. A missing production link is a validation failure. Return a translated message containing the ready date or incomplete task count.

- [ ] **Step 4: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
git add app/Actions/Production/ReleaseOutputLot.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: require completed tasks for batch release"
```

## Task 8: Build a single production-detail presenter

**Files:**

- Create: `app/Services/Production/ProductionDetailPresenter.php`
- Create: `tests/Feature/ProductionDetailPresenterTest.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`

- [ ] **Step 1: Add failing presenter tests**

Build a production containing an explicit ingredient, NaOH or KOH, water, packaging, split reservations, saved actuals, and an output lot. Assert the presenter returns:

- compact identity data;
- lifecycle steps with `completed/current/upcoming/terminal` states;
- exactly one primary action;
- grouped material rows in formula order plus one packaging group;
- formula percentage inline data;
- planned quantity and unit;
- reservation total and split lot entries;
- actual input entries or non-stock calculated actual;
- `Not stock tracked` for water;
- output reconciliation and release readiness.

Use this explicit row contract:

```php
/**
 * @return array{
 *   key: string,
 *   group_key: string,
 *   material_name: string,
 *   percentage: ?string,
 *   note: ?string,
 *   planned: array{quantity: string, unit: string},
 *   reservation: array{tracked: bool, total: ?string, lots: list<array{id: int, code: string, quantity: string}>},
 *   actual: array{mode: 'hidden'|'editable'|'readonly', rows: list<array{state_key: string, lot_id: ?int, quantity: string, note: ?string}>}
 * }
 */
```

- [ ] **Step 2: Run and confirm failure**

```bash
php artisan test --compact tests/Feature/ProductionDetailPresenterTest.php
```

- [ ] **Step 3: Implement the presenter**

Inject `ProductionOutputReconciliation` and apply number formatting only at the display boundary; keep canonical values as strings. Match formula lines to requirements primarily by `ingredient_id`/`recipe_item_id`, not translated names. Append packaging requirements after formula phases. Merge multiple reservations and consumption rows without duplicating the material heading.

Primary action selection:

```php
return match ($production->status) {
    ProductionRunStatus::Draft => 'schedule',
    ProductionRunStatus::Scheduled => 'prepare_stock',
    ProductionRunStatus::Reserved => $production->batch_number === null ? 'assign_batch_number' : 'start',
    ProductionRunStatus::InProduction => 'complete',
    ProductionRunStatus::Completed => $this->hasQuarantinedOutput($production) ? 'release_batch' : null,
    default => null,
};
```

For a directly Planned run, mark Draft completed and Planned current. Cancelled and Aborted return terminal landmarks rather than pretending to complete later steps.

- [ ] **Step 4: Thin the Livewire render method**

Eager-load all presenter relations once, call `ProductionDetailPresenter::present($production, $actualRows, $calculatedActualRows)`, and remove coverage arithmetic and formula/requirement joins from `render()`.

- [ ] **Step 5: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchProductionsTest.php
git add app/Livewire/ProductionBench/Production/ProductionDetail.php app/Services/Production/ProductionDetailPresenter.php tests/Feature/ProductionDetailPresenterTest.php
git commit -m "refactor: present production detail as one workflow"
```

## Task 9: Redesign the production detail page and early-start interaction

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Add failing page-contract tests**

Assert the rendered page has:

- one compact identity header;
- one lifecycle landmark;
- one primary next-action control;
- no duplicate status card/action set;
- one `batch-materials-table` and no separate formula/requirements tables;
- rows for lye, water, ordinary ingredients, and packaging;
- percentage on the same material line;
- reserved total and lot codes;
- actuals hidden before Start, editable In production, and read-only after completion;
- planned/actual/variance output summary;
- contextual Release stock and separated Cancel/Abort actions.

Prefer stable `data-testid` attributes over class-name assertions.

- [ ] **Step 2: Add failing early-start tests**

Freeze time before `planned_for`. Calling the first Start interaction must dispatch an `early-start-confirmation-requested` browser event and leave status Reserved. Calling `confirmEarlyStart` must start the run. On or after the date, `start` must proceed without confirmation.

- [ ] **Step 3: Run focused tests and confirm failure**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 4: Implement the workflow-first Blade layout**

Render only presenter data. Use one responsive header and lifecycle landmark, then one materials table grouped by phase. Keep multiple lot chips/lines inside a material cell. Water's reservation cell renders the translated non-stock label. The actual column binds either `actualRows` or `calculatedActualRows` based on the presenter state key.

The primary action renderer switches on the presenter's action id. It must not render disabled future actions alongside the current action. Keep task, journal, output-lot, cancellation, and abort detail sections below the operational table without repeating identity/status.

- [ ] **Step 5: Implement early-start confirmation**

In `ProductionDetail::start()`, compare `planned_for` to `today()` before invoking the action. Dispatch the confirmation event and return when early. Add `confirmEarlyStart(StartProduction $action)` to invoke the same private start helper. The domain action continues allowing early, normal, and late Start; this is an explicit operator confirmation, not a business prohibition.

- [ ] **Step 6: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
git add app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: redesign production workflow page"
```

## Task 10: Harden production deletion in PostgreSQL and SQLite

**Files:**

- Create: `database/migrations/2026_08_10_180000_harden_production_run_reservation_delete_guard.php`
- Modify: `tests/Feature/ProductionRunNumberStorageTest.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Correct and extend failing deletion tests**

Test at the application and raw-database boundaries:

- unnumbered Draft with an active reservation cannot be deleted;
- numbered Planned with an active reservation cannot be deleted;
- unnumbered and numbered Draft/Planned runs without active reservations can be deleted;
- Stock prepared must release stock before application deletion;
- deleting an unreserved numbered run does not decrement the workspace counter, so the next number skips the burned serial;
- Cancelled and Aborted history cannot be deleted through the application.

Replace the stale PostgreSQL assertion that expects every numbered deletion to fail.

- [ ] **Step 2: Run tests and confirm the unnumbered direct delete fails to be blocked**

```bash
php artisan test --compact tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionPlanningTest.php
```

- [ ] **Step 3: Create the forward migration**

Generate with Artisan and use the exact filename above:

```bash
php artisan make:migration harden_production_run_reservation_delete_guard --no-interaction
```

Immediately rename Artisan's generated file to `database/migrations/2026_08_10_180000_harden_production_run_reservation_delete_guard.php` before editing it.

The PostgreSQL function's DELETE branch must be:

```sql
IF TG_OP = 'DELETE' THEN
    PERFORM pg_advisory_xact_lock(OLD.workspace_id);

    IF EXISTS (
        SELECT 1
        FROM public.stock_reservations
        WHERE production_run_id = OLD.id
          AND status = 'active'
    ) THEN
        RAISE EXCEPTION 'reserved production runs cannot be deleted';
    END IF;

    RETURN OLD;
END IF;
```

Use `public.production_runs` and `public.stock_reservations` throughout the replacement PostgreSQL function. Preserve every update/insert integrity check from the current function.

For SQLite, replace `production_runs_number_integrity_delete` with:

```sql
CREATE TRIGGER production_runs_number_integrity_delete
BEFORE DELETE ON production_runs
WHEN EXISTS (
    SELECT 1
    FROM stock_reservations
    WHERE production_run_id = OLD.id
      AND status = 'active'
)
BEGIN
    SELECT RAISE(ABORT, 'reserved production runs cannot be deleted');
END
```

The migration `down()` restores the immediately previous trigger/function definition so rollbacks are deterministic.

- [ ] **Step 4: Assert the installed PostgreSQL definition**

Keep the existing `pg_get_functiondef(...)` test and assert it contains the schema-qualified reservation query and does not contain `OLD.batch_number IS NOT NULL AND EXISTS`.

- [ ] **Step 5: Re-run and commit**

```bash
php artisan test --compact tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionPlanningTest.php
git add database/migrations/2026_08_10_180000_harden_production_run_reservation_delete_guard.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionRunNumberStorageTest.php
git commit -m "fix: block deletion with active reservations"
```

## Task 11: Translate every new production term in all supported locales

**Files:**

- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Add focused translation expectations**

Extend the catalogue test with the complete new production key prefix and assert nonblank values for `de`, `es`, `fr`, `it`, and `nl`. Include at least:

- lifecycle labels: Draft, Planned, Stock prepared, In production, Completed, Cancelled, Aborted;
- action labels: Schedule, Prepare stock, Assign batch number, Start production, Complete production, Release batch, Release stock;
- early-start title/body/confirm/cancel;
- table labels: Batch materials, Material, Planned, Reserved lots, Actual used, Not stock tracked;
- output labels: Planned output, Actual output, Variance, Yield variance, Quarantined, Released;
- validation and release messages introduced by Tasks 1, 3, 5, and 7;
- empty states and reservation explanations rendered by the new page.

- [ ] **Step 2: Run the catalogue test and confirm missing entries**

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php
```

- [ ] **Step 3: Add English source keys and reviewed translations**

Use nested keys under `production_bench.production.lifecycle`, `.actions`, `.materials`, `.output`, `.confirmation`, and `.validation`. Replace hardcoded customer-facing strings touched by this implementation with `__()` calls.

Add one sorted row per new English source key to `database/seeders/data/interface-translations.json`, with all five locale values and preserved placeholders. Do not create `lang/fr/production_bench.php` or equivalent files.

For the key terminology, use these French meanings consistently:

```text
Planned = Planifiée
Stock prepared = Stock préparé
In production = En production
Completed = Terminée
Release batch = Libérer le lot
Actual output = Production réelle
Yield variance = Écart de rendement
Not stock tracked = Non suivi en stock
```

Use natural equivalent terminology in German, Spanish, Italian, and Dutch rather than literal word-for-word constructions.

- [ ] **Step 4: Verify catalogue completeness and one localized page**

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/ProductionBenchProductionsTest.php
```

Expected: every English-owned interface key has a reviewed translation in all five non-English locales, and the French production page renders translated lifecycle/table/output terms.

- [ ] **Step 5: Commit translations**

```bash
git add database/seeders/data/interface-translations.json lang/en/production_bench.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: translate production lifecycle workflow"
```

## Task 12: Full verification and handoff

**Files:**

- Modify only if verification reveals a production-lifecycle regression.

- [ ] **Step 1: Format changed PHP files**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: exit code 0. If Pint changes files, inspect the diff and include those formatting changes in the relevant final commit.

- [ ] **Step 2: Run focused production and localization suites**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionFormulaSnapshotTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: all tests pass with zero failures.

- [ ] **Step 3: Run the full suite**

```bash
php artisan test --compact
```

Expected: all tests pass. Investigate any failure before proceeding; do not classify failures as unrelated without reproducing them against the starting commit.

- [ ] **Step 4: Verify PostgreSQL-specific behavior**

Run the production-number tests against the configured local PostgreSQL connection and verify the installed trigger through the existing test. Expected: active reservations block direct deletes regardless of numbering; numbered unreserved Draft/Planned runs delete successfully.

- [ ] **Step 5: Refresh the code graph**

```bash
graphify update .
```

Expected: graph update succeeds and includes the new presenter and calculated-material services.

- [ ] **Step 6: Inspect the final diff and commit verification fixes**

```bash
git status --short
git diff --check
git diff --stat
```

Expected: no whitespace errors and no unrelated ingredient-catalog files in this branch. If verification produced code changes:

```bash
git add app database/factories database/migrations database/seeders/data/interface-translations.json lang/en/production_bench.php resources/views/livewire/production-bench/production/production-detail.blade.php tests
git commit -m "test: verify production lifecycle redesign"
```

## Explicit follow-up boundary: wastage and substitutions

This plan intentionally records only planned quantities, actual material use, and actual output. A later design may add production-event records for:

- permanently lost material or output;
- reusable recovered material returned to a destination stock lot;
- sellable secondary output created as its own lot;
- quantity, unit, reason, stage, operator, cost, and destination movement.

That future model must reconcile with actual input/output but must not infer wastage automatically from a yield variance. Ingredient substitution likewise remains a separate audited workflow preserving original material, replacement, lots, quantities, reason, operator, and cost.
