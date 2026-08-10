# Production Lifecycle Corrections and Manufactured Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct the verified production lifecycle defects and implement the approved product/manufactured-ingredient output, ready-date, and manual-release model before this branch is considered for merge.

**Architecture:** A product keeps its Soap or Cosmetic formula family and separately declares whether it produces finished units or a bulk manufactured ingredient. Production runs snapshot output identity and effective ready delay; completion creates one physically present output lot awaiting manual release, while the advisory ready date never becomes a second availability gate. Focused services own ready-delay resolution, actual-lot eligibility, batch-number issuance, and transactional allocation so actions remain small and testable.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, PostgreSQL/SQLite, Pest 4, Alpine.js, Laravel translations.

**Authoritative design:** `docs/superpowers/specs/2026-08-10-manufactured-ingredient-output-and-release-design.md`

---

## Scope boundaries

- Work only in the `codex/production-lifecycle-redesign` worktree.
- Do not merge or cherry-pick to `main`.
- Do not modify the separate ingredient-database work in the main worktree.
- Do not repair the older August 7 migration rollbacks in this plan.
- Keep each database migration additive; do not rewrite migrations that may already have run.
- Use TDD for every behavioral change and commit after every task.

## Task 1: Add output, readiness, and issuance storage foundations

**Files:**

- Create: `app/Enums/ProductionOutputType.php`
- Create: `app/Models/ProductionOutputSetting.php`
- Create: `database/factories/ProductionOutputSettingFactory.php`
- Create: `database/migrations/2026_08_10_231000_create_production_output_settings_table.php`
- Create: `database/migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php`
- Create: `app/Models/ProductionRunNumberIssuance.php`
- Create: `database/factories/ProductionRunNumberIssuanceFactory.php`
- Create: `database/migrations/2026_08_10_231200_create_production_run_number_issuances_table.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Models/ProductionRun.php`
- Modify: `app/Models/StockLot.php`
- Modify: `app/Models/Workspace.php`
- Test: `tests/Feature/ProductionPlanningSchemaTest.php`
- Test: `tests/Feature/ProductionRunNumberStorageTest.php`

- [ ] **Step 1: Write failing schema and cast tests**

Assert the enum has `FinishedProduct = 'finished_product'` and `ManufacturedIngredient = 'manufactured_ingredient'`. Assert:

```php
expect(Schema::hasColumns('recipes', [
    'production_output_type',
    'output_ingredient_id',
    'ready_delay_days',
]))->toBeTrue();

expect(Schema::hasColumns('production_runs', [
    'production_output_type',
    'output_ingredient_id',
    'output_ready_delay_days',
    'estimated_ready_on',
]))->toBeTrue();

expect(Schema::hasColumn('stock_lots', 'estimated_ready_on'))->toBeTrue();
expect(Schema::hasTable('production_output_settings'))->toBeTrue();
expect(Schema::hasTable('production_run_number_issuances'))->toBeTrue();
```

Also test workspace-scoped uniqueness for issued batch numbers and allow the same rendered number in another workspace.

- [ ] **Step 2: Run the tests and verify the missing schema fails**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionRunNumberStorageTest.php
```

Expected: FAIL on missing enum, tables, and columns.

- [ ] **Step 3: Generate model and migration shells**

```bash
php artisan make:model ProductionOutputSetting --factory --migration --no-interaction
php artisan make:model ProductionRunNumberIssuance --factory --migration --no-interaction
php artisan make:migration add_output_configuration_to_recipes_runs_and_lots --no-interaction
```

Rename generated migrations to the exact paths listed above.

- [ ] **Step 4: Implement the schemas**

`production_output_settings`:

```php
$table->id();
$table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
$table->unsignedInteger('soap_ready_delay_days')->default(21);
$table->unsignedInteger('cosmetic_ready_delay_days')->default(3);
$table->timestamps();
```

Recipe fields:

```php
$table->string('production_output_type', 32)->default('finished_product')->index();
$table->foreignId('output_ingredient_id')->nullable()->constrained('ingredients')->restrictOnDelete();
$table->unsignedInteger('ready_delay_days')->nullable();
```

Run snapshot fields use nullable output type, output ingredient, unsigned `output_ready_delay_days`, and `estimated_ready_on` columns so pre-migration runs remain identifiable as legacy. New-run application actions always populate the first three fields. Stock lots receive nullable `estimated_ready_on`.

Issuance schema:

```php
$table->id();
$table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
$table->foreignId('production_run_id')->nullable()->constrained()->nullOnDelete();
$table->string('batch_number', 120);
$table->unsignedBigInteger('serial');
$table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('issued_at');
$table->timestamps();
$table->unique(['workspace_id', 'batch_number']);
$table->index(['workspace_id', 'serial']);
```

Insert one 21/3 output-settings row for every existing workspace and backfill existing recipes as finished output. Leave existing production-run output snapshot columns null so an already-planned intermediate run continues through the explicit legacy completion path. Copy `available_from` into `estimated_ready_on` for existing production-output lots, then set `available_from = null` for those lots so their advisory estimate cannot remain a hard availability gate. Do not change purchased/opening lots.

- [ ] **Step 5: Add model relationships and casts**

Add workspace `productionOutputSetting()` and `productionRunNumberIssuances()`, recipe/run `outputIngredient()`, and issuance `workspace()`, `productionRun()`, and `issuedBy()`. Cast output type to the enum, delay fields to integer, and estimate/issuance dates to date/datetime.

- [ ] **Step 6: Run schema tests and migrate the local database**

```bash
php artisan migrate --no-interaction
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionRunNumberStorageTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the storage foundation**

```bash
git add app/Enums/ProductionOutputType.php app/Models/ProductionOutputSetting.php app/Models/ProductionRunNumberIssuance.php app/Models/Recipe.php app/Models/ProductionRun.php app/Models/StockLot.php app/Models/Workspace.php database/factories database/migrations/2026_08_10_231000_create_production_output_settings_table.php database/migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php database/migrations/2026_08_10_231200_create_production_run_number_issuances_table.php tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionRunNumberStorageTest.php
git commit -m "feat: add production output and readiness storage"
```

## Task 2: Add editable workspace ready-delay settings

**Files:**

- Create: `app/Actions/Production/SaveProductionOutputSettings.php`
- Create: `app/Services/Production/ProductionReadyDateService.php`
- Modify: `app/Livewire/ProductionBench/Production/SettingsIndex.php`
- Modify: `resources/views/livewire/production-bench/production/settings-index.blade.php`
- Modify: `app/Services/WorkspaceProvisioner.php`
- Test: `tests/Feature/ProductionBenchProductionSettingsTest.php`
- Test: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Write failing settings and fallback tests**

Test initial values 21/3, saving `30/5`, rejecting blank/negative/fractional values, read-only access, new workspace provisioning, and resolver precedence:

```php
expect($readyDates->delayDays($soapRecipe, $workspace))->toBe(21)
    ->and($readyDates->delayDays($cosmeticRecipe, $workspace))->toBe(3);

$cosmeticRecipe->update(['ready_delay_days' => 28]);
expect($readyDates->delayDays($cosmeticRecipe->fresh(), $workspace))->toBe(28);
```

- [ ] **Step 2: Run focused tests and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/ProductionPlanningTest.php --filter='ready delay|output settings'
```

Expected: FAIL because settings and resolver do not exist.

- [ ] **Step 3: Implement settings persistence and date resolution**

`SaveProductionOutputSettings::handle()` must assert configuration access, lock workspace then settings, normalize non-negative whole integers, and save both values transactionally. `ProductionReadyDateService` exposes:

```php
public function delayDays(Recipe $recipe, Workspace $workspace): int;
public function estimatedReadyOn(string $baseDate, int $delayDays): string;
```

Precedence is product override, family-specific workspace setting, then platform fallback 21/3. Determine the family from `productFamily.calculation_basis`, not the recipe name.

- [ ] **Step 4: Add the Production Settings form**

Add two always-populated inputs under a “Ready dates” section. `0` is valid. Explain that Soap/Cosmetic refers to the workbench used to create the formula; a soap-noodle formula from the Cosmetic workbench uses the Cosmetic default.

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/ProductionPlanningTest.php --filter='ready delay|output settings'
git add app/Actions/Production/SaveProductionOutputSettings.php app/Services/Production/ProductionReadyDateService.php app/Livewire/ProductionBench/Production/SettingsIndex.php resources/views/livewire/production-bench/production/settings-index.blade.php app/Services/WorkspaceProvisioner.php tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "feat: configure production ready date defaults"
```

## Task 3: Configure product output and quick-create manufactured ingredients

**Files:**

- Create: `app/Actions/Ingredients/CreateManufacturedIngredient.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeVersionRecordService.php`
- Modify: `app/Services/RecipeWorkbenchVersionPayloadMapper.php`
- Modify: `app/Services/RecipeWorkbenchDraftPayloadMapper.php`
- Modify: `app/Services/RecipeWorkbenchViewDataBuilder.php`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/payload.js`
- Modify: `resources/js/recipe-workbench/snapshot.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Write failing output-configuration tests**

Cover finished defaults, bulk output requiring a workspace-owned active manufactured ingredient, rejection of platform/foreign/inactive ingredients, nullable product override, round-trip through save/publish/load/duplicate, and quick creation:

```php
$ingredient = app(CreateManufacturedIngredient::class)->handle($owner, $workspace, 'Turmeric oil macerate');

expect($ingredient->is_manufactured)->toBeTrue()
    ->and($ingredient->workspace_id)->toBe($workspace->id);
```

- [ ] **Step 2: Run tests and verify failure**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php --filter='manufactured ingredient|production output|ready delay'
```

- [ ] **Step 3: Implement server-side validation and persistence**

Normalize `production_output_type`, `output_ingredient_id`, and `ready_delay_days`. Enforce:

```php
if ($outputType === ProductionOutputType::ManufacturedIngredient && ! $outputIngredient instanceof Ingredient) {
    throw ValidationException::withMessages([
        'output_ingredient_id' => __('production_bench.production.validation.output_ingredient_required'),
    ]);
}

if ($outputType === ProductionOutputType::FinishedProduct && $outputIngredientId !== null) {
    throw ValidationException::withMessages([
        'output_ingredient_id' => __('production_bench.production.validation.finished_output_has_no_ingredient'),
    ]);
}
```

The selected ingredient must be active, workspace-owned, and `is_manufactured = true`. Persist configuration on `recipes`, not version rows.

- [ ] **Step 4: Implement manufactured-ingredient quick creation**

The action accepts actor, workspace, and display name; asserts create permission/entitlement; creates a private workspace ingredient through `IngredientDataEntryService`; sets `is_manufactured = true`; and returns it. It must not create a supplier or supplier listing.

- [ ] **Step 5: Add workbench controls and round-trip state**

In formula settings add Output type, conditional manufactured-ingredient search selector, “Create manufactured ingredient,” and optional Ready delay override. Preserve the configuration through Alpine payload/snapshot modules. Rename the Ingredient form toggle label from “Manufactured” to “Manufactured in-house.”

- [ ] **Step 6: Build frontend assets and run tests**

```bash
npm run build
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php --filter='manufactured ingredient|production output|ready delay'
```

Expected: PASS.

- [ ] **Step 7: Commit product output configuration**

```bash
git add app/Actions/Ingredients/CreateManufacturedIngredient.php app/Models/Recipe.php app/Services/RecipeWorkbenchPayloadNormalizer.php app/Services/RecipeVersionRecordService.php app/Services/RecipeWorkbenchVersionPayloadMapper.php app/Services/RecipeWorkbenchDraftPayloadMapper.php app/Services/RecipeWorkbenchViewDataBuilder.php app/Livewire/Dashboard/RecipeWorkbench.php resources/js/recipe-workbench resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php app/Filament/Resources/Ingredients/Schemas/IngredientForm.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php public/build
git commit -m "feat: configure manufactured product output"
```

## Task 4: Snapshot output identity and generate ready dates in every planning path

**Files:**

- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `app/Actions/Production/UpdateProductionPlan.php`
- Modify: `app/Actions/Production/RescheduleProduction.php`
- Modify: `app/Services/Production/FlashProductionSimulator.php`
- Modify: `app/Services/Production/FlashDateProposalService.php`
- Modify: `app/Actions/Production/GenerateFlashProductions.php`
- Modify: `resources/views/livewire/production-bench/production/flash-planner.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/FlashPlanner.php`
- Test: `tests/Feature/ProductionPlanningTest.php`
- Test: `tests/Feature/GenerateFlashProductionsTest.php`
- Test: `tests/Feature/ProductionBenchFlashPlannerTest.php`

- [ ] **Step 1: Add failing direct and Flash snapshot tests**

Assert each created run snapshots output type, output ingredient, effective delay, and `planned_for + delay`. Test Soap 21, Cosmetic 3, product override 28, multiple Flash dates, and later product changes not altering existing runs.

- [ ] **Step 2: Run the tests and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/ProductionBenchFlashPlannerTest.php --filter='ready|output snapshot|flash dates'
```

- [ ] **Step 3: Snapshot configuration during draft creation**

Inside the locked recipe/workspace transaction, resolve effective delay and write:

```php
'production_output_type' => $lockedRecipe->production_output_type,
'output_ingredient_id' => $lockedRecipe->output_ingredient_id,
'output_ready_delay_days' => $readyDates->delayDays($lockedRecipe, $lockedWorkspace),
'estimated_ready_on' => $plannedFor === null
    ? null
    : $readyDates->estimatedReadyOn($plannedFor, $effectiveDelay),
```

Validate the bulk ingredient again under lock.

- [ ] **Step 4: Recalculate only the estimate when a pre-start date changes**

`UpdateProductionPlan` and `RescheduleProduction` recompute `estimated_ready_on` from the run's snapshotted delay. They never reread current product configuration.

- [ ] **Step 5: Include ready dates in Flash proposals and preview**

Every proposal contains `production_date` and `estimated_ready_on`; persistence asserts both in idempotency checks. Display both columns in preview so users do not edit generated runs individually.

- [ ] **Step 6: Run planning tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/ProductionBenchFlashPlannerTest.php
git add app/Actions/Production/CreateProductionDraft.php app/Actions/Production/UpdateProductionPlan.php app/Actions/Production/RescheduleProduction.php app/Services/Production/FlashProductionSimulator.php app/Services/Production/FlashDateProposalService.php app/Actions/Production/GenerateFlashProductions.php app/Livewire/ProductionBench/Production/FlashPlanner.php resources/views/livewire/production-bench/production/flash-planner.blade.php tests/Feature/ProductionPlanningTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/ProductionBenchFlashPlannerTest.php
git commit -m "feat: snapshot production output ready dates"
```

## Task 5: Complete and manually release configured output

**Files:**

- Modify: `app/Actions/Production/CompleteProduction.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `app/Actions/Production/ReleaseOutputLot.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `app/Services/Production/ProductionDetailPresenter.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Test: `tests/Feature/ProductionExecutionTest.php`
- Test: `tests/Feature/ProductionDetailPresenterTest.php`
- Test: `tests/Feature/ProductionOutputReconciliationTest.php`

- [ ] **Step 1: Add failing configured-output completion tests**

Test finished output creates a count/recipe lot; bulk output creates a mass/ingredient lot; both have `origin = production_output`, `supplier_listing_id = null`, one output movement, `status = quarantined`, and operator-confirmed `estimated_ready_on`. Verify no completion-time ingredient selector is needed for new runs.

- [ ] **Step 2: Add failing release tests**

Cover: tasks must be complete; release after estimate; early release first returns a confirmation requirement; confirmed early release succeeds; estimate remains unchanged; release creates no movement/receipt; released bulk lot becomes available ingredient stock.

- [ ] **Step 3: Run tests and verify current behavior fails**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionOutputReconciliationTest.php --filter='output|release|ready|intermediate'
```

- [ ] **Step 4: Derive output from run snapshots during completion**

Replace `outputIngredientId` for new runs with the snapshotted output type/id. Accept `estimatedReadyOn` from the completion form, defaulted from manufacture date plus snapshotted delay. Validate positive integer output for finished units and positive decimal mass for manufactured ingredients. Keep the old selector only for legacy runs missing output snapshots.

- [ ] **Step 5: Make ready date advisory and release authoritative**

Change `ReleaseOutputLot::handle()` to accept `bool $earlyReleaseConfirmed = false`. Under workspace → production → tasks → lot locks:

```php
if ($lockedLot->estimated_ready_on?->isFuture() && ! $earlyReleaseConfirmed) {
    throw ValidationException::withMessages([
        'early_release_confirmation' => __('production_bench.production.validation.early_release_confirmation', [
            'date' => $lockedLot->estimated_ready_on->toDateString(),
        ]),
    ]);
}
```

Do not hard-block early release after confirmation and do not change the estimate. Release updates status/metadata only.

- [ ] **Step 6: Update the output UI**

Use “Awaiting release” in the production UI, display the estimate, dispatch a translated early-release confirmation, and keep the Release action disabled only for incomplete tasks/read-only state—not merely because the estimate is in the future.

- [ ] **Step 7: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionOutputReconciliationTest.php
git add app/Actions/Production/CompleteProduction.php app/Services/Production/ProductionCompletionService.php app/Actions/Production/ReleaseOutputLot.php app/Livewire/ProductionBench/Production/ProductionDetail.php app/Services/Production/ProductionDetailPresenter.php resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionOutputReconciliationTest.php
git commit -m "feat: release configured production output manually"
```

## Task 6: Prevent aggregate over-reservation

**Files:**

- Modify: `app/Actions/Production/PrepareProductionStock.php`
- Test: `tests/Feature/ProductionStockPreparationTest.php`
- Test: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Correct the competing-production regression test**

For 20 g physical stock and requirements of 10 g and 100 g, assert reservations are 10 g + 10 g, never 30 g. Add a manual competing-requirement case for the same lot. Add a PostgreSQL-only two-connection contention test following `ProductionRunBatchNumberingTest`: the first connection locks the workspace, the second uses `SET lock_timeout = '250ms'`, and a competing preparation must block/fail rather than allocate around the workspace lock.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionPlanningTest.php --filter='prepar|reserv|contention'
```

- [ ] **Step 3: Maintain transaction-local balances**

Initialize `remainingByLot` from locked proposal availability, cap each automatic allocation, subtract immediately, and reject manual allocations exceeding the current map. Preserve FEFO ordering and existing workspace/lot locks.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionPlanningTest.php --filter='prepar|reserv|contention'
git add app/Actions/Production/PrepareProductionStock.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "fix: prevent aggregate production over-reservation"
```

## Task 7: Enforce actual-material lot eligibility twice

**Files:**

- Create: `app/Services/Production/ConsumableStockLotPolicy.php`
- Modify: `app/Actions/Production/SaveProductionActuals.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Test: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Add failing eligibility tests**

Reject awaiting-release, expired, foreign, and wrong-subject lots. Preserve the hard `available_from` gate for procurement/legacy lots. Prove a production-output lot explicitly released before its advisory estimate is accepted. Mutate a saved lot back to quarantined before completion and assert full rollback.

- [ ] **Step 2: Run tests and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter='eligible|quarantined|expired|early released'
```

- [ ] **Step 3: Implement the shared policy**

```php
public function assertConsumable(StockLot $lot, CarbonInterface $onDate, string $field): void
{
    if ($lot->status !== StockLotStatus::Released) {
        throw ValidationException::withMessages([$field => __('production_bench.production.validation.actual_lot_not_released')]);
    }

    if ($lot->available_from !== null && $lot->available_from->isAfter($onDate)) {
        throw ValidationException::withMessages([$field => __('production_bench.production.validation.actual_lot_not_available')]);
    }

    if ($lot->expires_at !== null && $lot->expires_at->isBefore($onDate)) {
        throw ValidationException::withMessages([$field => __('production_bench.production.validation.actual_lot_expired')]);
    }
}
```

New production outputs have `available_from = null`; their estimate is advisory in `estimated_ready_on`.

- [ ] **Step 4: Apply at save and completion boundaries**

Call the policy after workspace/subject checks in `SaveProductionActuals` and again after locking each consumption lot immediately before costing and movement creation.

- [ ] **Step 5: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
git add app/Services/Production/ConsumableStockLotPolicy.php app/Actions/Production/SaveProductionActuals.php app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: enforce actual material lot eligibility"
```

## Task 8: Preserve scheduled dates and make task generation atomic

**Files:**

- Modify: `app/Actions/Production/UpdateProductionPlan.php`
- Modify: `app/Actions/Production/ScheduleProduction.php`
- Modify: `app/Actions/Production/GenerateProductionTasks.php`
- Test: `tests/Feature/ProductionPlanningTest.php`
- Test: `tests/Feature/ProductionTaskSchedulingTest.php`

- [ ] **Step 1: Add failing invariant tests**

Assert Scheduled cannot receive a null/non-working date while Draft may remain undated. Create a saved Draft with an invalid configured task set; scheduling must throw and leave status Draft, date null, estimate null, and tasks empty.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php --filter='scheduled date|rolls back scheduling'
```

- [ ] **Step 3: Validate under locks and combine transactions**

Check the Scheduled date invariant after locking the current run/workspace and before rescaling. Refactor task generation with `generateForLockedProduction(User, ProductionRun, Workspace)`. Schedule status/date/estimate update and task creation in one outer transaction.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php
git add app/Actions/Production/UpdateProductionPlan.php app/Actions/Production/ScheduleProduction.php app/Actions/Production/GenerateProductionTasks.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionTaskSchedulingTest.php
git commit -m "fix: schedule productions atomically"
```

## Task 9: Freeze tasks only after output release

**Files:**

- Modify: `app/Actions/Production/ReopenProductionTask.php`
- Test: `tests/Feature/ProductionTaskSchedulingTest.php`
- Test: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Add failing quarantine-versus-release tests**

Assert a completed task may reopen while output awaits release. Complete it again, release output, and assert reopening fails without changing `completed_at`.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php --filter='reopen'
```

- [ ] **Step 3: Match release lock order and enforce the boundary**

Lock workspace → production → all tasks ordered by id → output lot. Reject Completed task reopening only when the output lot is Released.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php --filter='task|release|reopen'
git add app/Actions/Production/ReopenProductionTask.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: freeze production tasks after output release"
```

## Task 10: Make permanent batch-number burning durable

**Files:**

- Modify: `app/Actions/Production/AssignProductionBatchNumbers.php`
- Modify: `app/Services/Production/ProductionRunNumberService.php`
- Modify: `app/Actions/Production/SaveProductionRunNumberSettings.php`
- Test: `tests/Feature/ProductionRunBatchNumberingTest.php`
- Test: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Add failing reissue tests**

Assign a number, delete the unreserved run, reset settings to that rendered identity, and assert the reset/assignment is rejected while the issuance row survives with `production_run_id = null`.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionPlanningTest.php --filter='burn|reissue|deleted'
```

- [ ] **Step 3: Record and consult issuance history atomically**

Create an issuance row before updating each production within the existing assignment transaction. Extend permanent identity checks to query both runs and issuance history. Planning references remain checked against runs only.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionPlanningTest.php
git add app/Actions/Production/AssignProductionBatchNumbers.php app/Services/Production/ProductionRunNumberService.php app/Actions/Production/SaveProductionRunNumberSettings.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionPlanningTest.php
git commit -m "fix: preserve issued production batch numbers"
```

## Task 11: Localize every new lifecycle path

**Files:**

- Modify: `lang/en/production_bench.php`
- Modify: `lang/de.json`
- Modify: `lang/es.json`
- Modify: `lang/fr.json`
- Modify: `lang/it.json`
- Modify: `lang/nl.json`
- Modify: lifecycle actions/services changed in Tasks 2–10
- Test: `tests/Feature/ProductionBenchLocalizationTest.php`

- [ ] **Step 1: Add locale-parameterized tests**

Exercise representative output configuration, settings, Flash dates, early release, actual eligibility, task reopening, scheduled date, stock preparation, and batch issuance failures for `de`, `es`, `fr`, `it`, and `nl`.

- [ ] **Step 2: Add keys and replace literals**

Add explicit keys for output type, manufactured ingredient, ready settings, estimate, awaiting release, early-release warning, lot eligibility, scheduled date, task freeze, reservation, task generation, and issued batch identity. Dynamic text uses placeholders such as `:date`, `:lot`, `:tasks`, and `:currency`.

- [ ] **Step 3: Audit bounded files**

```bash
rg -n "ValidationException::withMessages|throw ValidationException" app/Actions/Production app/Services/Production app/Actions/Ingredients/CreateManufacturedIngredient.php
```

Expected: every user-facing message in files changed by this plan resolves through translation keys.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionBenchProductionSettingsTest.php
git add app/Actions/Production app/Actions/Ingredients/CreateManufacturedIngredient.php app/Services/Production lang/en/production_bench.php lang/de.json lang/es.json lang/fr.json lang/it.json lang/nl.json tests/Feature/ProductionBenchLocalizationTest.php
git commit -m "fix: localize production output lifecycle"
```

## Task 12: Fix early-start confirmation and complete verification

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Test: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Tighten the early-start event test**

Assert the event includes `plannedFor` and the exact translated `message` payload read by Alpine.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter='explicit confirmation'
```

- [ ] **Step 3: Dispatch the translated message**

```php
$plannedFor = $production->planned_for->format('Y-m-d');

$this->dispatch(
    'early-start-confirmation-requested',
    plannedFor: $plannedFor,
    message: __('production_bench.production.early_start_confirm', ['date' => $plannedFor]),
);
```

- [ ] **Step 4: Run required formatters and static UI checks**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
npm run build
```

Expected: all commands complete successfully; fix every non-auto-fixed Filament issue before continuing.

- [ ] **Step 5: Run the complete focused regression suite**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionRunNumberStorageTest.php tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionOutputReconciliationTest.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionBenchLocalizationTest.php
```

Expected: PASS with no failures.

- [ ] **Step 6: Refresh graph and inspect final state**

```bash
graphify update .
git diff --check
git status --short
```

Expected: graph refresh succeeds, `git diff --check` prints nothing, and only intended lifecycle, built-asset, test, translation, and graph changes remain.

- [ ] **Step 7: Request final independent code review**

Use `superpowers:requesting-code-review` against the implementation base and current HEAD. The review must specifically recheck aggregate reservation totals, actual-lot eligibility, early release, output provenance, permanent-number tombstones, task-release races, scheduled dates, and task-generation rollback.

- [ ] **Step 8: Commit the final confirmation fix**

```bash
git add app/Livewire/ProductionBench/Production/ProductionDetail.php tests/Feature/ProductionExecutionTest.php public/build graphify-out
git commit -m "fix: complete production lifecycle confirmations"
```

## Explicitly deferred

- No merge or cherry-pick to `main` until the separate ingredient database work is ready and the user explicitly requests integration.
- No synthetic internal supplier or supplier listing.
- No goods receipt when releasing production output; completion already posts the sole quantity-increasing output movement.
- No automatic release scheduler; manual release is authoritative for finished and manufactured-ingredient output.
- No combined bulk-manufacture-and-retail-packaging output in one run; packaged macerate is a separate finished product consuming the bulk ingredient.
- No automated quality-control templates or laboratory workflow.
- No repair of historical August 7 migration rollbacks or conversion of the deliberate forward-only August 10 trigger migration.
- No general-purpose multi-process concurrency harness; the plan includes a focused PostgreSQL two-connection reservation-contention test using the repository's existing lock-timeout pattern.
