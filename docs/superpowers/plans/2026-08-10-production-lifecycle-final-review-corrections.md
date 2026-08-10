# Production Lifecycle Final Review Corrections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve the confirmed lifecycle, numbering, costing, migration, and localization findings before integrating the production work into `codex/ingredient-catalog-curation`, while leaving `main` untouched.

**Architecture:** Centralize date-sensitive stock eligibility in one policy and scheduled-date mutation in one locked service. Keep lifecycle actions as transaction owners using workspace-first lock order. Preserve permanent number history with an additive backfill and a later PostgreSQL trigger replacement, then cover every corrected boundary with focused Pest tests before the final branch review.

**Tech Stack:** PHP 8.5, Laravel 13, PostgreSQL/SQLite, Eloquent transactions and row locks, Pest 4, Livewire 4, database-backed interface translations.

---

## Fixed decisions and boundaries

- Rescheduling a stock-prepared production releases all active reservations, changes the run from `Reserved` to `Scheduled`, and requires stock preparation again. This is intentionally simpler than trying to retain or reallocate reservations invisibly.
- Starting a production validates reserved lots against the actual start date. Saving substitute actual lots validates against the current execution date. Completion validates every actual lot again against the confirmed manufacture date.
- A completed task may be reopened while the output is Awaiting release. A released output permanently freezes its production tasks.
- Existing permanent batch numbers are permanent retroactively and must be backfilled into issuance history.
- Permanent-number history is enforced in application code and at the PostgreSQL boundary.
- A manufactured ingredient's stock cost per gram includes every direct material actually consumed by the run, including packaging or consumables when present.
- Repair the reversible behavior of the new `2026_08_10_231100` output migration. The explicitly deferred August 7 rollback overhaul and conversion of the deliberate forward-only `2026_08_10_180000` migration remain outside this plan.
- Integration target is `codex/ingredient-catalog-curation`; never merge this work directly to `main`.

## File responsibility map

- Create `app/Services/Production/ConsumableStockLotPolicy.php`: one authority for Released, hard-availability-date, and expiry checks on an explicit date.
- Create `app/Services/Production/ProductionDateRescheduler.php`: mutate a locked production date, release stale reservations, recompute readiness, and move eligible automatic tasks.
- Modify `app/Actions/Production/RescheduleProduction.php`: transaction/access orchestration only; delegate date mutation.
- Modify `app/Actions/Production/UpdateProductionPlan.php`: route Scheduled date changes through the shared date service while preserving atomic rescaling.
- Modify `app/Actions/Production/StartProduction.php`: lock reservation lots and validate them on the start date before changing status.
- Modify `app/Actions/Production/SaveProductionActuals.php`: apply the shared lot policy on the current execution date.
- Modify `app/Services/Production/ProductionCompletionService.php`: apply the same policy on the confirmed manufacture date and value manufactured output using total direct cost.
- Modify `app/Actions/Production/ReopenProductionTask.php`: use workspace-first lock order and freeze only after output release.
- Modify `database/migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php`: restore migrated hard availability dates in `down()` before dropping the advisory column.
- Create `database/migrations/2026_08_10_231300_backfill_production_run_number_issuances.php`: idempotently record all existing permanent numbers.
- Create `database/migrations/2026_08_10_231400_enforce_production_run_number_issuance_integrity.php`: replace the PostgreSQL function after the issuance table exists.
- Modify production lifecycle actions/services and `lang/en/production_bench.php`: remove remaining user-facing English literals.
- Modify `database/seeders/data/interface-translations.json`: provide DE/ES/FR/IT/NL text for every new key.
- Create `tests/Feature/ProductionBenchLocalizationTest.php`: exercise translated failure paths rather than checking only catalogue shape.

### Task 1: Centralize date-sensitive stock-lot eligibility

**Files:**
- Create: `app/Services/Production/ConsumableStockLotPolicy.php`
- Modify: `app/Actions/Production/SaveProductionActuals.php:20-220`
- Modify: `app/Services/Production/ProductionCompletionService.php:40-387`
- Test: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Add failing execution-date tests**

Add three focused Pest cases named `accepts an actual lot available on the execution date even when it expires before the old planned date`, `rejects an actual lot that is unavailable on the current execution date`, and `rechecks actual lots against the confirmed manufacture date before posting movements`.

Use the existing `productionExecutionFixture()` and `productionExecutionRun()` helpers. In the first case, start early and make the selected lot valid today but expired by `planned_for`; saving actuals must succeed. In the second, set `available_from` after today and assert `SaveProductionActuals` returns an error on the row's `stock_lot_id`. In the third, save the row while eligible, complete with a manufacture date after expiry, assert a `production` error, and compare movement/output-lot counts before and after to prove full transaction rollback.

- [ ] **Step 2: Run the tests to verify the planned-date implementation fails**

Run:

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter='execution date|confirmed manufacture date'
```

Expected: the early-start cases fail because both boundaries currently use `planned_for`.

- [ ] **Step 3: Create the shared policy**

Generate the class with `php artisan make:class Services/Production/ConsumableStockLotPolicy --no-interaction`, then implement this interface:

```php
final class ConsumableStockLotPolicy
{
    public function assertConsumable(
        StockLot $lot,
        CarbonInterface $onDate,
        string $field,
    ): void {
        if ($lot->status !== StockLotStatus::Released) {
            throw ValidationException::withMessages([
                $field => __('production_bench.production.validation.actual_lot_not_released'),
            ]);
        }

        if ($lot->available_from !== null && $lot->available_from->isAfter($onDate)) {
            throw ValidationException::withMessages([
                $field => __('production_bench.production.validation.actual_lot_not_available'),
            ]);
        }

        if ($lot->expires_at !== null && $lot->expires_at->isBefore($onDate)) {
            throw ValidationException::withMessages([
                $field => __('production_bench.production.validation.actual_lot_expired'),
            ]);
        }
    }
}
```

Use constructor injection in both consumers. In `SaveProductionActuals`, pass `now()->startOfDay()` after workspace and subject validation. In `ProductionCompletionService`, pass `Carbon::parse($manufactureDate)->startOfDay()` while each lot is locked immediately before costing and movement creation. Remove both duplicated planned-date checks.

- [ ] **Step 4: Run the focused execution tests**

Run:

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter='eligible|quarantined|expired|execution date|confirmed manufacture date|early released'
```

Expected: PASS; Awaiting release, hard availability, expiry, early-release, and changed-lot rollback behavior all use the shared policy.

- [ ] **Step 5: Commit the policy correction**

```bash
git add app/Services/Production/ConsumableStockLotPolicy.php app/Actions/Production/SaveProductionActuals.php app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: validate production lots on execution dates"
```

### Task 2: Make rescheduling invalidate stock preparation safely

**Files:**
- Create: `app/Services/Production/ProductionDateRescheduler.php`
- Modify: `app/Actions/Production/RescheduleProduction.php:16-114`
- Test: `tests/Feature/ProductionStockPreparationTest.php`
- Test: `tests/Feature/ProductionTaskSchedulingTest.php`

- [ ] **Step 1: Add failing reserved-run rescheduling tests**

Add a Pest case that prepares a run, reschedules it, and asserts:

```php
expect($production->fresh()->status)->toBe(ProductionRunStatus::Scheduled)
    ->and($production->fresh()->planned_for->toDateString())->toBe('2026-08-24')
    ->and($production->reservations()->where('status', StockReservationStatus::Active)->count())->toBe(0)
    ->and($production->reservations()->where('status', StockReservationStatus::Released)->count())->toBeGreaterThan(0);
```

Also assert every released reservation receives `released_at`, the ready estimate is recomputed, and automatic incomplete tasks move relative to the new working date while completed/custom tasks remain unchanged.

- [ ] **Step 2: Run the tests to verify reservations remain active**

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionTaskSchedulingTest.php --filter='reschedul'
```

Expected: FAIL because `RescheduleProduction` currently retains active reservations and `Reserved` status.

- [ ] **Step 3: Implement the locked date-rescheduling service**

Generate `ProductionDateRescheduler` and give it dependencies on `ProductionWorkingCalendar` and `ProductionReadyDateService`. Its public method must receive already locked `Workspace` and `ProductionRun` instances:

```php
public function rescheduleLocked(
    Workspace $workspace,
    ProductionRun $production,
    string $plannedFor,
): void
```

Inside the method, in this order:

1. Reject a non-working date.
2. Lock all production tasks by `id` and reject moving a completed anchor task.
3. If status is `Reserved`, lock active reservations by `id`, mark each `Released`, set `released_at`, and change status to `Scheduled`.
4. Update `planned_for` and `estimated_ready_on`.
5. Move only automatic, incomplete tasks through `ProductionWorkingCalendar::dateRelativeToProduction()`.

Keep the outer transaction, workspace-first lock order, authorization, allowed-status check, and date-format validation in `RescheduleProduction`; replace its inline mutation with the service call.

- [ ] **Step 4: Run reservation and task rescheduling tests**

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionTaskSchedulingTest.php
```

Expected: PASS, including existing custom/completed task behavior.

- [ ] **Step 5: Commit the rescheduling boundary**

```bash
git add app/Services/Production/ProductionDateRescheduler.php app/Actions/Production/RescheduleProduction.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionTaskSchedulingTest.php
git commit -m "fix: require stock preparation after rescheduling"
```

### Task 3: Route scheduled plan edits through the working calendar

**Files:**
- Modify: `app/Actions/Production/UpdateProductionPlan.php:18-182`
- Modify: `app/Services/Production/ProductionDateRescheduler.php`
- Test: `tests/Feature/ProductionPlanningTest.php`
- Test: `tests/Feature/ProductionTaskSchedulingTest.php`

- [ ] **Step 1: Add failing non-working-date and task-movement tests**

Add cases proving a Scheduled run update:

- rejects a weekend and a workspace holiday without changing mass, units, date, estimate, requirements, or tasks;
- accepts a working date and moves automatic incomplete tasks through the same calendar rules as the dedicated reschedule action;
- preserves a custom task date and a completed task date;
- still rejects `plannedFor: null`.

- [ ] **Step 2: Verify the direct update path fails**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php --filter='scheduled plan update'
```

Expected: weekend/holiday dates are accepted or task dates remain stale.

- [ ] **Step 3: Delegate Scheduled date changes inside the existing transaction**

Inject `ProductionDateRescheduler` into `UpdateProductionPlan`. After locking and before rescaling:

```php
$scheduledDateChanged = $lockedProduction->status === ProductionRunStatus::Scheduled
    && $lockedProduction->planned_for?->toDateString() !== $plannedFor;

if ($scheduledDateChanged) {
    $this->dateRescheduler->rescheduleLocked($lockedWorkspace, $lockedProduction, $plannedFor);
}
```

Do not write `planned_for` or `estimated_ready_on` a second time for a changed Scheduled date. Draft updates may remain undated and continue using the existing direct snapshot update. Keep rescaling, date/task movement, and final model update in one transaction so any invalid task set or rescale error rolls everything back.

- [ ] **Step 4: Run planning and task tests**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php
```

Expected: PASS with atomic rollback assertions.

- [ ] **Step 5: Commit the unified scheduled-date path**

```bash
git add app/Actions/Production/UpdateProductionPlan.php app/Services/Production/ProductionDateRescheduler.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php
git commit -m "fix: unify scheduled production date changes"
```

### Task 4: Revalidate locked reservations when production starts

**Files:**
- Modify: `app/Actions/Production/StartProduction.php:17-121`
- Modify: `app/Services/Production/ConsumableStockLotPolicy.php`
- Test: `tests/Feature/ProductionExecutionTest.php`
- Test: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Add failing start-boundary tests**

Create cases where a fully reserved run is blocked at start because its reserved lot is:

- no longer Released;
- not yet available on the actual start date;
- expired before the actual start date.

Also prove a lot that is valid today starts successfully even if the old planned date is later. For every rejection, assert status remains `Reserved`, `started_at` remains null, and no actual defaults are written.

- [ ] **Step 2: Run the focused start tests**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionPlanningTest.php --filter='starting.*lot|start.*expired|start.*available'
```

Expected: FAIL because start currently checks reservation quantities only.

- [ ] **Step 3: Lock and validate reservation lots before status mutation**

In `StartProduction`, after full-coverage validation:

1. Lock active reservations ordered by `id`.
2. Collect their lot IDs, lock the corresponding `StockLot` rows ordered by `id`, and key them by ID.
3. Reject missing lot references.
4. Call `ConsumableStockLotPolicy::assertConsumable()` for each lot using `now()->startOfDay()`.
5. Only then default water actuals and update the production to `InProduction`.

Retain workspace → production → reservations → lots ordering consistently in tests and future actions.

- [ ] **Step 4: Run start and execution tests**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionPlanningTest.php
```

Expected: PASS; early-start confirmation behavior remains unchanged.

- [ ] **Step 5: Commit start-time eligibility**

```bash
git add app/Actions/Production/StartProduction.php app/Services/Production/ConsumableStockLotPolicy.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "fix: recheck reserved lots when production starts"
```

### Task 5: Permit task correction until output release

**Files:**
- Modify: `app/Actions/Production/ReopenProductionTask.php:14-66`
- Test: `tests/Feature/ProductionTaskSchedulingTest.php`
- Test: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Replace the obsolete completed-status test**

Change the existing test at `ProductionTaskSchedulingTest.php:194` into two explicit cases named `reopens a completed task while production output awaits release` and `does not reopen a production task after output release`. The first completes the run with a completed task and Quarantined output, invokes `ReopenProductionTask`, and asserts `completed_at` becomes null. The second completes the task, releases the output, invokes the same action, and asserts both the translated validation failure and unchanged `completed_at`.

Add a PostgreSQL-only lock-order regression that captures or coordinates queries sufficiently to prove workspace is locked before production, ordered tasks, and output lot.

- [ ] **Step 2: Verify Awaiting-release reopening currently fails**

```bash
php artisan test --compact tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php --filter='reopen'
```

Expected: FAIL because Completed status is currently rejected unconditionally.

- [ ] **Step 3: Align the reopen transaction with release lock order**

Refactor the transaction to:

1. Read the task reference only to obtain stable IDs.
2. Lock workspace.
3. Lock production.
4. Lock all production tasks ordered by `id`, then select the requested task from that locked collection.
5. Lock the output lot.
6. Reject only when that output lot is `Released`; allow Completed + Quarantined/Awaiting release.
7. Clear `completed_at` on the target task.

Use the same translated validation key for the released-output boundary. Do not change production status when reopening a task.

- [ ] **Step 4: Run task/release tests**

```bash
php artisan test --compact tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php --filter='task|release|reopen'
```

Expected: PASS without changing release's requirement that all tasks be complete.

- [ ] **Step 5: Commit the task-release boundary**

```bash
git add app/Actions/Production/ReopenProductionTask.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: freeze production tasks only after release"
```

### Task 6: Backfill and enforce permanent-number history

**Files:**
- Create: `database/migrations/2026_08_10_231300_backfill_production_run_number_issuances.php`
- Create: `database/migrations/2026_08_10_231400_enforce_production_run_number_issuance_integrity.php`
- Modify: `tests/Feature/ProductionRunNumberStorageTest.php`
- Modify: `tests/Feature/ProductionRunBatchNumberingTest.php`

- [ ] **Step 1: Add failing backfill and PostgreSQL boundary tests**

Add tests proving:

- a numbered run with no issuance row is backfilled with workspace, run, batch number, serial, actor, and assignment date;
- running the backfill twice is idempotent;
- deleting that run nulls `production_run_id` but preserves the issuance;
- resetting settings and attempting reuse remains rejected;
- on PostgreSQL, direct SQL cannot set a permanent number without its matching issuance row;
- on PostgreSQL, a planning reference cannot reuse a historical issued permanent number;
- the normal `AssignProductionBatchNumbers` transaction still succeeds because it creates the matching issuance before updating the run.

Load the anonymous migration in tests using the existing repository pattern:

```php
$migration = require database_path(
    'migrations/2026_08_10_231300_backfill_production_run_number_issuances.php',
);
$migration->up();
$migration->up();
```

- [ ] **Step 2: Run the number-storage tests and verify the gaps**

```bash
php artisan test --compact tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionRunBatchNumberingTest.php --filter='backfill|direct SQL|historical issued'
```

Expected: missing issuance rows remain missing and PostgreSQL direct updates bypass historical issuance checks.

- [ ] **Step 3: Generate and implement the idempotent backfill migration**

Generate it with:

```bash
php artisan make:migration backfill_production_run_number_issuances --no-interaction
```

Rename only if necessary to preserve ordering after `231200`. In `up()`, stream numbered runs by ID and call `insertOrIgnore()` with:

```php
[
    'workspace_id' => $run->workspace_id,
    'production_run_id' => $run->id,
    'batch_number' => $run->batch_number,
    'serial' => $run->batch_number_serial,
    'issued_by_user_id' => $run->batch_number_assigned_by_user_id,
    'issued_at' => $run->batch_number_assigned_at,
    'created_at' => $run->batch_number_assigned_at,
    'updated_at' => $run->batch_number_assigned_at,
]
```

Filter to complete audit tuples because the database integrity trigger already requires all permanent-number fields together. Make `down()` intentionally non-destructive: historical issuance records must not be deleted by a rollback of a data backfill.

- [ ] **Step 4: Generate and implement the PostgreSQL trigger replacement**

Generate:

```bash
php artisan make:migration enforce_production_run_number_issuance_integrity --no-interaction
```

For PostgreSQL, replace `production_runs_enforce_batch_number_integrity()` after the issuance table and backfill exist. Preserve every current check and add these rules:

```sql
IF NEW.batch_number IS NOT NULL AND NOT EXISTS (
    SELECT 1
    FROM public.production_run_number_issuances issuance
    WHERE issuance.workspace_id = NEW.workspace_id
      AND issuance.production_run_id = NEW.id
      AND issuance.batch_number = NEW.batch_number
      AND issuance.serial = NEW.batch_number_serial
) THEN
    RAISE EXCEPTION 'production run permanent batch number requires matching issuance history';
END IF;

IF NEW.planning_batch_number IS NOT NULL AND EXISTS (
    SELECT 1
    FROM public.production_run_number_issuances issuance
    WHERE issuance.workspace_id = NEW.workspace_id
      AND issuance.batch_number = NEW.planning_batch_number
) THEN
    RAISE EXCEPTION 'production run planning reference conflicts with issued batch history';
END IF;
```

The migration `down()` must restore the exact prior function definition from `2026_08_10_180000_harden_production_run_reservation_delete_guard.php`; it must not drop the function or weaken reservation-delete protection. SQLite keeps application-level issuance enforcement and its existing triggers.

- [ ] **Step 5: Run numbering tests on SQLite and PostgreSQL**

```bash
php artisan test --compact tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionPlanningTest.php --filter='number|issuance|burn|reissue|historical'
```

Expected: PASS. PostgreSQL-only assertions may skip under SQLite but must run in the configured PostgreSQL verification environment.

- [ ] **Step 6: Commit permanent history enforcement**

```bash
git add database/migrations/2026_08_10_231300_backfill_production_run_number_issuances.php database/migrations/2026_08_10_231400_enforce_production_run_number_issuance_integrity.php tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionRunBatchNumberingTest.php
git commit -m "fix: enforce historical production batch numbers"
```

### Task 7: Carry full direct cost into manufactured ingredient stock

**Files:**
- Modify: `app/Services/Production/ProductionCompletionService.php:74-207`
- Modify: `tests/Feature/ProductionExecutionTest.php:1184-1230`

- [ ] **Step 1: Correct the failing costing expectation first**

In the intermediate-output propagation test, calculate the expected cost from both consumed categories. With the current fixture:

```php
// Ingredient: 11,000 g × EUR 0.0125 = EUR 137.50
// Packaging: 98 units × EUR 0.50 = EUR 49.00
// Output: EUR 186.50 / 12,000 g = EUR 0.015541666 per gram
expect($intermediateLot->historical_unit_cost)->toBe('0.015541666')
    ->and($intermediateLot->costing_unit_cost)->toBe('0.015541666');
```

Also assert the downstream run prices its consumed intermediate using that same per-gram value.

- [ ] **Step 2: Run the costing test and verify it fails**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter='prices an intermediate output lot per gram'
```

Expected: actual value remains `0.011458333`, proving packaging was excluded.

- [ ] **Step 3: Compute the output-lot value from total direct cost**

Move `$totalCost = bcadd($ingredientTotal, $packagingTotal, 9)` before output-lot creation and calculate:

```php
$intermediateCostPerGram = $isIntermediate && bccomp($totalCost, '0', self::GuardScale) > 0
    ? bcdiv($totalCost, $outputQuantity, 9)
    : null;
```

Reuse the same `$totalCost` for `actual_total_cost` and `actual_cost_per_unit`. Do not create a second costing basis.

- [ ] **Step 4: Run production costing tests**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter='cost|prices|currency|intermediate'
```

Expected: PASS, including mixed-currency rejection and downstream propagation.

- [ ] **Step 5: Commit full-cost propagation**

```bash
git add app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: include direct packaging in intermediate cost"
```

### Task 8: Complete lifecycle localization behavior

**Files:**
- Modify: `app/Actions/Ingredients/CreateManufacturedIngredient.php`
- Modify: `app/Actions/Production/AssignProductionBatchNumbers.php`
- Modify: `app/Actions/Production/CompleteProduction.php`
- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `app/Actions/Production/GenerateFlashProductions.php`
- Modify: `app/Actions/Production/GenerateProductionTasks.php`
- Modify: `app/Actions/Production/PrepareProductionStock.php`
- Modify: `app/Actions/Production/ReleaseOutputLot.php`
- Modify: `app/Actions/Production/ReopenProductionTask.php`
- Modify: `app/Actions/Production/RescheduleProduction.php`
- Modify: `app/Actions/Production/SaveProductionActuals.php`
- Modify: `app/Actions/Production/SaveProductionOutputSettings.php`
- Modify: `app/Actions/Production/ScheduleProduction.php`
- Modify: `app/Actions/Production/StartProduction.php`
- Modify: `app/Actions/Production/UpdateProductionPlan.php`
- Modify: `app/Services/Production/FlashDateProposalService.php`
- Modify: `app/Services/Production/FlashProductionSimulator.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `app/Services/Production/ProductionDetailPresenter.php`
- Modify: `app/Services/Production/ProductionReadyDateService.php`
- Modify: `app/Services/Production/ProductionRunNumberService.php`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Create: `tests/Feature/ProductionBenchLocalizationTest.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Add locale-parameterized failure-path tests**

Create the Pest test with a dataset for `de`, `es`, `fr`, `it`, and `nl`. For every locale, import the committed catalogue and exercise at least these failures:

- unavailable/expired actual lot;
- invalid scheduled date and non-working date;
- rescheduling after production starts;
- task reopening after output release;
- early release confirmation and incomplete release tasks;
- missing or mismatched output ingredient;
- invalid actual output quantity;
- permanent-number conflict and missing planned date.

Assert each returned message equals the locale's catalogue value and does not equal the English source. Add one source-audit assertion that scans the bounded changed lifecycle files and fails when a `ValidationException::withMessages()` value contains a raw English sentence rather than `__()`.

- [ ] **Step 2: Run localization tests and capture the English fallbacks**

```bash
php artisan test --compact tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: failures identify raw literals in release, actuals, completion, rescheduling, task, and numbering paths.

- [ ] **Step 3: Replace lifecycle literals with named keys**

Add keys under `production.validation`, using stable semantic names such as:

```php
'workspace_missing' => 'The production workspace could not be found.',
'actuals_running_only' => 'Actual consumption can only be recorded while the production is running.',
'actual_lot_wrong_workspace' => 'The stock lot does not belong to this workspace.',
'actual_lot_wrong_subject' => 'The stock lot does not match the requirement subject.',
'completion_running_only' => 'Only a running production can be completed.',
'manufacture_date_invalid' => 'The manufacture date must use YYYY-MM-DD format.',
'output_quantity_positive' => 'The actual output quantity must be greater than zero.',
'finished_output_whole_units' => 'Finished output must be a whole number of units.',
'release_completed_only' => 'Only output from a completed production can be released.',
'release_tasks_incomplete' => 'Complete the remaining production tasks before releasing this output: :tasks.',
'reschedule_working_day' => 'The production date must be a working day.',
'reschedule_after_start' => 'The production date cannot be changed after production starts.',
'number_conflict' => 'One or more batch numbers to assign are already in use.',
```

Use placeholders for lot, task, currency, and date values. Replace literals only in the lifecycle files bounded by this feature; do not begin an unrelated application-wide translation rewrite.

- [ ] **Step 4: Add reviewed catalogue values for every supported locale**

Add every new `production_bench` key to `database/seeders/data/interface-translations.json` with non-empty `de`, `es`, `fr`, `it`, and `nl` values, preserving bytewise group/key ordering and locale ordering. Run the authoritative importer in the test database through the existing test helpers; do not add a legacy translation seeder.

- [ ] **Step 5: Run the complete localization suite**

```bash
php artisan test --compact tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
```

Expected: PASS; no tested lifecycle path falls back to English in DE/ES/FR/IT/NL.

- [ ] **Step 6: Commit localization completion**

```bash
git add app/Actions/Production app/Services/Production app/Livewire/Dashboard/RecipeWorkbench.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "fix: complete production lifecycle localization"
```

### Task 9: Preserve ready dates on rollback and complete the integration gate

**Files:**
- Modify: `database/migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php:53-80`
- Modify: `tests/Feature/ProductionPlanningSchemaTest.php`
- Modify: `docs/superpowers/plans/2026-08-10-production-lifecycle-final-review-corrections.md`

- [ ] **Step 1: Add a migration rollback regression**

Add a test that creates a production-output lot with an advisory `estimated_ready_on`, invokes this migration's `down()`, and asserts the date is restored to `available_from` before `estimated_ready_on` is removed. Then invoke `up()` and assert the date returns to `estimated_ready_on` and `available_from` becomes null.

Run this test for SQLite and in the PostgreSQL verification environment. Use representative data and the repository's existing anonymous-migration loading pattern.

- [ ] **Step 2: Verify the current down migration loses the date**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php --filter='ready date rollback'
```

Expected: FAIL because `down()` drops `estimated_ready_on` without copying it back.

- [ ] **Step 3: Restore the predecessor value before dropping the column**

At the start of `down()` add:

```php
DB::table('stock_lots')
    ->where('origin', 'production_output')
    ->whereNull('available_from')
    ->whereNotNull('estimated_ready_on')
    ->update([
        'available_from' => DB::raw('estimated_ready_on'),
    ]);
```

Then drop the new columns in the existing dependency-safe order. Do not alter the explicitly forward-only `2026_08_10_180000` migration or expand this task into the deferred August 7 rollback project.

- [ ] **Step 4: Run focused suites**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS with no failures. Warnings must be reviewed and any warning caused by these changes fixed.

- [ ] **Step 5: Format and refresh structural checks**

```bash
vendor/bin/pint --dirty --format agent
graphify update .
git diff --check
```

If any file under `app/Filament` was changed incidentally, also run:

```bash
vendor/bin/filacheck --fix
```

Expected: formatter and graph update succeed; `git diff --check` prints nothing.

- [ ] **Step 6: Run the full relevant verification with a valid local APP_KEY**

```bash
php artisan test --compact
npm run build
```

Expected: all tests and the production asset build pass. Do not dismiss view failures caused by a missing local `APP_KEY`; configure a valid uncommitted test key and rerun.

- [ ] **Step 7: Request independent final review**

Use `superpowers:requesting-code-review` with the correction-plan base SHA and final HEAD. Require explicit rechecks for:

- reserved-run rescheduling and start-time eligibility;
- Scheduled calendar/task mutation;
- task reopen/release race and lock order;
- save/completion effective dates;
- historical issuance backfill and PostgreSQL trigger behavior;
- intermediate full-cost propagation;
- DE/ES/FR/IT/NL failure paths;
- `231100` up → down → up data preservation.

Expected verdict: no Critical or Important findings remain.

- [ ] **Step 8: Commit the migration correction and final plan state**

```bash
git add database/migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php tests/Feature/ProductionPlanningSchemaTest.php docs/superpowers/plans/2026-08-10-production-lifecycle-final-review-corrections.md
git commit -m "fix: preserve production readiness through rollback"
```

- [ ] **Step 9: Integrate only into the approved target**

Confirm ancestry and cleanliness before integration:

```bash
git status --short
git merge-base --is-ancestor codex/ingredient-catalog-curation codex/production-lifecycle-redesign
```

Expected: clean production worktree and exit code 0 for ancestry. Then merge or fast-forward `codex/ingredient-catalog-curation` to the reviewed production HEAD. Do not check out, modify, or merge `main`.

## Completion criteria

- A rescheduled Reserved run has no active reservations and visibly returns to Scheduled.
- Start cannot proceed with an unreleased, future-available, expired, or missing reserved lot.
- Scheduled plan updates use working-calendar and task-rescheduling guarantees.
- Actual lots are checked on current execution date and again on confirmed manufacture date.
- Awaiting-release tasks can be corrected; released-output tasks cannot.
- Every historical permanent number has an issuance row and PostgreSQL rejects boundary bypasses.
- Manufactured ingredient stock receives total direct cost per gram.
- DE/ES/FR/IT/NL lifecycle failures are translated behaviorally, not only present in a catalogue.
- The new ready-date migration preserves dates through up → down → up.
- Focused suite, full suite, asset build, Pint, graph update, and independent review pass.
- Final integration targets `codex/ingredient-catalog-curation`; `main` remains untouched.
