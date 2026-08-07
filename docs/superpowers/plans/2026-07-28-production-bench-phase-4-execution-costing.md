# Production Execution, Output, and Actual Cost Implementation Plan (Checkpoint 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a reserved Production Bench run be started, executed, completed, costed, quarantined, released, or aborted — with actual consumption, controlled negative stock, an output lot, immutable actual costs, and a production journal — while keeping every run immutable once started.

**Architecture:** The statuses, completion contract, and stock-truth rules defined in the approved [Production Bench design](../specs/2026-07-28-production-bench-design.md) are the durable contract. This plan implements its "Production Run Model", "Output Quarantine and Release", "Production Journal", "Finished-Goods Issue", and "Costing" sections on top of the existing planning/reservation primitives, the batch-numbering engine, and the independent formula snapshots delivered by the August 6 plan. Completion is a single atomic transaction; actuals are recorded incrementally beforehand and never post movements until the terminal action.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, BCMath, PostgreSQL/SQLite migrations, existing private media usage for attachments.

**Design authority:** [Production Bench Design](../specs/2026-07-28-production-bench-design.md) §Production Run Model, §Output Quarantine and Release, §Production Journal, §Finished-Goods Issue, §Costing — as amended by the corrections below. The Basic `ProductionBatch` snapshot product is independent and never linked (see the August 5 batch-numbering design and the reconciled §Basic Production History Compatibility).

**Approved corrections (recorded in the design doc alongside this plan):**

1. **Deletion** — a `draft` or `scheduled` run without reservations and without a permanent batch number may be deleted. Everything else stays immutable.
2. **Start gate** — starting never performs reservation. Start requires the `Reserved` state (every requirement fully covered) **and an assigned permanent batch number** (the identity on printed sheets, and later the output-lot code).
3. **Partial stock preparation** — reserving is no longer all-or-nothing per run: whatever can be reserved is posted, shortfalls stay visible, and coverage can be completed later from the stock-preparation page or the production sheet. A run reaches `Reserved` only when fully covered. Release becomes partial-capable as well (per requirement).

**Working-tree safety:** The branch may contain unrelated uncommitted work (ingredient-table change, built assets). Preserve those files; stage path-by-path per task. No `app/Filament` file belongs to this plan.

---

## Product invariants

- The lifecycle is `Draft → Scheduled → Reserved → In production → Completed`, with `Cancelled` (pre-start) and `Aborted` (post-start) terminal paths.
- A run may be deleted only when `draft` or `scheduled`, has no reservations, and has no permanent batch number.
- Starting requires full reservation coverage and an assigned permanent batch number; starting freezes the plan.
- Actual consumption is recorded incrementally during `In production` and posts no stock movement until the terminal action.
- Completion is atomic: consumption movements, unused-reservation release, actual-cost snapshot, output-lot creation, output movement, and run close either all happen or none do.
- Actual consumption may exceed reservations and create visible negative stock; only explicit consumption or adjustment actions may do so.
- The permanent batch number is the output-lot code. A completed run has exactly one output lot.
- Quarantined output is not available; release (optionally after `available_from`) makes it available.
- Actual batch costs are immutable once completion posts them; later price changes never rewrite them.
- A started run cannot be deleted or casually cancelled; abort requires recording actuals and reconciling stock.
- Production Bench records are never linked to Basic `ProductionBatch` snapshots.
- The production sheet (detail) shows per-requirement reservation coverage and is the primary place to resume partial preparation.
- `public_id` (UUID) is a URL-only identifier and never appears in production views.

## Data contract

### `production_runs` additions

- `started_at`, `started_by_user_id` (nullable FK `users` `nullOnDelete`);
- `completed_at`, `completed_by_user_id` (nullable FK);
- `aborted_at`, `aborted_by_user_id` (nullable FK);
- `abort_reason` (nullable text);
- `manufacture_date` (nullable date);
- `actual_output_units` (nullable unsignedInteger);
- `actual_output_mass_grams` (nullable decimal 20,9);
- cost snapshot: `cost_currency` (nullable string 3), `actual_ingredient_total`, `actual_packaging_total`, `actual_total_cost` (nullable decimal 20,9), `actual_cost_per_unit` (nullable decimal 20,9).

DB checks (pgsql CHECK + sqlite triggers): at most one of `actual_output_units` / `actual_output_mass_grams`; completion columns appear together; positive totals when present.

### `production_consumption`

Actual consumption rows recorded during `In production`, one row per requirement and lot:

- `production_run_id` (required, cascade);
- `production_requirement_id` (nullable FK `nullOnDelete`);
- `stock_lot_id` (nullable FK `nullOnDelete`);
- `kind`: `ingredient` | `packaging`;
- `subject_name_snapshot`;
- `quantity` (decimal 20,9);
- `unit_snapshot`: `g` | `unit`;
- `price_per_unit`, `line_cost` (nullable decimals, filled at completion);
- `recorded_by_user_id` (nullable FK), `note` (nullable text);
- `created_at` / `updated_at`.

Checks: ingredient rows are mass-positive with unit `g`; packaging rows are count-positive integers with unit `unit`. Rows are draft data until the terminal action; completion and abort both consume them.

### `stock_lots` addition

- `production_run_id` (nullable FK `nullOnDelete`) — links output lots to their run; `StockLotOrigin::ProductionOutput` already exists.

### `production_journal_entries`

- `production_run_id` (required, cascade);
- `body` (text, rich content);
- `created_by_user_id` (nullable FK `nullOnDelete`);
- `created_at` / `updated_at`.

Attachments reuse the existing polymorphic private media usage pattern (`MediaAssetUsage`), exactly as production documents do.

---

### Task 1: Lifecycle schema, deletion rule, and URL-only identity

**Files:**

- Create: `database/migrations/2026_08_07_120000_add_production_execution_schema.php`
- Create: `app/Models/ProductionConsumption.php`
- Create: `app/Models/ProductionJournalEntry.php`
- Create: `database/factories/ProductionConsumptionFactory.php`
- Create: `database/factories/ProductionJournalEntryFactory.php`
- Modify: `app/Models/ProductionRun.php`
- Modify: `database/factories/ProductionRunFactory.php`
- Modify: `tests/Feature/ProductionPlanningSchemaTest.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Write failing schema, deletion-rule, and URL-only tests**

Assert the new `production_runs` columns, `production_consumption`, `production_journal_entries`, and `stock_lots.production_run_id` exist; consumption rows reject invalid kinds/units/quantities; deleting a run cascades consumption and journal rows. Assert `public_id` no longer renders on list, detail, or stock-preparation pages while the production card, detail header, and prepare page still render (update the existing `assertSee(public_id)` list assertion to `assertDontSee`).

- [ ] **Step 2: Run the focused test and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionBenchProductionsTest.php
```

Expected: FAIL because the columns/tables/relationships do not exist and the views still show `public_id`.

- [ ] **Step 3: Implement the migration, models, and relationships**

Mirror the trigger-preservation discipline of the August 6 migration: any SQLite rebuild of `production_runs` must recreate the nine existing integrity triggers. Add `consumption(): HasMany`, `journalEntries(): HasMany`, `outputLot(): HasOne` to `ProductionRun`; casts for the new columns. Add the check constraints and SQLite triggers for consumption rows and the output-column exclusivity.

- [ ] **Step 4: Remove `public_id` from the three views**

Delete the `public_id` elements from `production-index.blade.php`, `production-detail.blade.php`, and `stock-preparation.blade.php`. Routes and calendar URLs keep using `public_id`.

- [ ] **Step 5: Verify Task 1 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionBenchStockPreparationTest.php
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_07_120000_add_production_execution_schema.php app/Models/ProductionConsumption.php app/Models/ProductionJournalEntry.php database/factories/ProductionConsumptionFactory.php database/factories/ProductionJournalEntryFactory.php app/Models/ProductionRun.php database/factories/ProductionRunFactory.php tests/Feature/ProductionPlanningSchemaTest.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/livewire/production-bench/production/production-detail.blade.php resources/views/livewire/production-bench/production/stock-preparation.blade.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: add production execution schema"
```

Expected: focused tests PASS.

---

### Task 2: Delete draft and scheduled runs without reservations

**Files:**

- Create: `app/Actions/Production/DeleteProductionRun.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/ProductionPlanningTest.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Write failing deletion tests**

Cover: deleting a `draft` run works and removes requirements, formula lines, tasks, and consumption rows; deleting a `scheduled` run without reservations works; deletion is rejected for `reserved`, `in_production`, `completed`, `cancelled`, `aborted` runs; deletion is rejected when any reservation exists; a numbered run without reservations is deletable and its number is burned; the list page exposes a delete action only for deletable runs and confirms before deleting.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBenchProductionsTest.php
```

Expected: FAIL because no delete action exists.

- [ ] **Step 3: Implement `DeleteProductionRun`**

Lock the run; reject unless status is `draft` or `scheduled`; reject when any active reservation exists; reject when `batch_number` is not null; delete the run in one transaction (requirements, formula lines, tasks, consumption, and journal cascade).

- [ ] **Step 4: Wire the list UI**

Add a per-card delete action (with confirmation) visible only for deletable runs; add `delete` handling and notifications in `ProductionIndex`.

- [ ] **Step 5: Verify Task 2 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBenchProductionsTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/DeleteProductionRun.php app/Livewire/ProductionBench/Production/ProductionIndex.php resources/views/livewire/production-bench/production/production-index.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: delete unstarted production drafts"
```

Expected: focused tests PASS.

---

### Task 3: Start production with the reserved and permanent-number gates

**Files:**

- Create: `app/Actions/Production/StartProduction.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Write failing start tests**

Cover: a fully reserved run with an assigned permanent batch number starts and records `started_at`/`started_by_user_id`; start is rejected for `draft`, `scheduled`, `completed`, `cancelled`, `aborted` runs; start is rejected while any requirement is under-covered (partial reservation); start is rejected without a permanent batch number; starting twice is rejected; the detail page shows the Start action only when eligible.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php
```

Expected: FAIL because `StartProduction` does not exist.

- [ ] **Step 3: Implement `StartProduction`**

Lock the run and workspace; require status `reserved`, `batch_number` non-null, and full reservation coverage (`productionIsFullyReserved` equivalent over locked requirements); set `status = in_production`, `started_at = now()`, `started_by_user_id = actor`.

- [ ] **Step 4: Wire the detail page**

Show a Start button when status is `reserved`, with disabled-state hints when the permanent number is missing.

- [ ] **Step 5: Verify Task 3 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/StartProduction.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionPlanningTest.php
git commit -m "feat: start production runs"
```

Expected: focused tests PASS.

---

### Task 4: Record actual consumption during production

**Files:**

- Create: `app/Actions/Production/SaveProductionActuals.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Create: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing actuals tests**

Cover: actual rows can be saved for ingredient and packaging requirements during `in_production`; quantities are validated (positive, integer units for packaging, mass for ingredients); rows can be updated and deleted before the terminal action; rows cannot be saved outside `in_production`; a consumption row may exceed its reservation quantity; per-row notes are kept; saving posts **no** stock movement and changes no stock position.

- [ ] **Step 2: Run the focused test and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because no actuals action exists.

- [ ] **Step 3: Implement `SaveProductionActuals`**

Lock the run; require status `in_production`; upsert the provided rows keyed by `production_requirement_id` + `stock_lot_id`; validate against the requirement's subject and unit; never touch stock.

- [ ] **Step 4: Wire the actuals section on the detail page**

Per-requirement quantity inputs prefilled with reserved quantities, per-lot breakdown when lots are reserved, note field, save-as-draft behavior with clear "not yet posted" labeling.

- [ ] **Step 5: Verify Task 4 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/SaveProductionActuals.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: record actual production consumption"
```

Expected: focused tests PASS.

---

### Task 5: Complete production atomically

**Files:**

- Create: `app/Services/Production/ProductionCompletionService.php`
- Create: `app/Actions/Production/CompleteProduction.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing completion tests**

Cover the full happy path: actual ingredient and packaging consumption posts `ProductionConsumption` movements against the recorded lots; unused reservations are released; actual costs are snapshotted from each consumed lot's historical unit cost (ingredient + packaging totals, cost per unit of output); one output lot is created with `origin = production_output`, `production_run_id` set, and internal code equal to the run's permanent batch number; the `ProductionOutput` movement posts; the run closes with `completed_at`, totals, and `actual_output_units` (or `actual_output_mass_grams` for intermediates); later lot-price changes do not alter the snapshot.

Cover readiness: completion is rejected while status is not `in_production`; while any requirement has no actuals; while actual output is missing; while `manufacture_date` is missing; while the permanent batch number is missing.

Cover atomicity: force a failure mid-completion (e.g., an invalid lot) and assert zero movements, zero reservations released, zero output lots, and the run still `in_production`. Cover negative stock: over-consumption beyond the lot's physical stock posts and the position goes negative and visible. Cover packaging count-based consumption rejecting fractional units.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because no completion service/action exists.

- [ ] **Step 3: Implement `ProductionCompletionService`**

One transaction: lock the run, reservations, and lots (ID order); validate readiness; post consumption movements (quantity, date, actor, note; `StockMovementType::ProductionConsumption`); release active reservations not covered by actuals; compute and snapshot costs from consumed lots' historical unit costs (`CurrentMaterialPriceService`/lot price columns, mirroring `ProductionBatch` totals); create the output lot (code = permanent batch number, `StockLotOrigin::ProductionOutput`, quantity = actual output, quarantine status, `available_from` from the product's default curing delay when known); post the `ProductionOutput` movement; write run totals; close the run.

- [ ] **Step 4: Wire the Complete action**

Detail page: readiness summary listing what is missing, then Complete with confirmation.

- [ ] **Step 5: Verify Task 5 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionCompletionService.php app/Actions/Production/CompleteProduction.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: complete production runs atomically"
```

Expected: focused tests PASS.

---

### Task 6: Abort production with reconciliation

**Files:**

- Create: `app/Actions/Production/AbortProduction.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing abort tests**

Cover: aborting an `in_production` run posts consumption movements for recorded actuals, releases all remaining active reservations, and closes the run as `aborted` with `aborted_at`, `aborted_by_user_id`, and reason; abort is rejected outside `in_production`; abort with zero actuals posts no consumption movements but still releases reservations; atomic rollback on failure.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because no abort action exists.

- [ ] **Step 3: Implement `AbortProduction`**

Reuse the actuals machinery: lock run + reservations + lots; post consumption for recorded actuals; release remaining active reservations; close as `aborted` with reason. No output lot, no cost snapshot.

- [ ] **Step 4: Wire the Abort UI**

Detail page: Abort action (with reason prompt) only during `in_production`, distinct from Cancel (pre-start).

- [ ] **Step 5: Verify Task 6 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/AbortProduction.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: abort production runs with reconciliation"
```

Expected: focused tests PASS.

---

### Task 7: Output quarantine, release, and finished-goods issue

**Files:**

- Create: `app/Actions/Production/ReleaseOutputLot.php`
- Create: `app/Actions/Production/IssueFinishedGoods.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing output tests**

Cover: the completed run's output lot starts quarantined; a quarantined lot is unavailable for reservation/issue; `ReleaseOutputLot` sets release state and respects `available_from` (release before `available_from` is rejected unless forced); released output is available; issue movements (`Shipment`, `Sample`, `Damaged`, `InternalUse`) post against the released output lot with quantity, reason, actor, and note; issuing more than available is rejected; issue is rejected while quarantined.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because no release/issue actions exist.

- [ ] **Step 3: Implement `ReleaseOutputLot` and `IssueFinishedGoods`**

Reuse `QuarantineStockLot`/`ReleaseStockLot` semantics for the lot state; issue posts the movement and reduces the lot position.

- [ ] **Step 4: Wire the output card on the detail page**

Show the output lot, its code (permanent batch number), quarantine/release state, `available_from`, and issue buttons with reason labels.

- [ ] **Step 5: Verify Task 7 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/ReleaseOutputLot.php app/Actions/Production/IssueFinishedGoods.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: release output lots and issue finished goods"
```

Expected: focused tests PASS.

---

### Task 8: Production journal

**Files:**

- Create: `app/Actions/Production/SaveProductionJournalEntry.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing journal tests**

Cover: entries are created with author and timestamp during `draft` through `in_production`; entries are read-only after `completed`, `aborted`, or `cancelled`; rich text is accepted and private media attachments attach to an entry; entries list chronologically on the detail page.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because no journal action exists.

- [ ] **Step 3: Implement the journal action and UI**

Reuse the existing rich-content and media-usage patterns (`ProductionDocument` / recipe media); enforce the status read-only rule.

- [ ] **Step 4: Verify Task 8 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/SaveProductionJournalEntry.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: production journal entries"
```

Expected: focused tests PASS.

---

### Task 9: Partial stock preparation and partial release

**Files:**

- Modify: `app/Actions/Production/PrepareProductionStock.php`
- Modify: `app/Actions/Production/ReleaseProductionStock.php`
- Modify: `app/Livewire/ProductionBench/Production/StockPreparation.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionStockPreparationTest.php`
- Modify: `tests/Feature/ProductionBenchStockPreparationTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing partial-coverage tests**

Cover: confirming preparation with a shortage posts the available reservations and leaves the run `scheduled` with visible per-requirement shortfalls; returning to the prepare page later reserves the remaining quantities and the run reaches `reserved` only when fully covered; per-requirement release frees only that requirement's reservations while others stay active; releasing the last active reservations returns the run to `scheduled`; the production sheet shows per-requirement coverage (reserved/required/short) with a direct resume action; multi-run preparation still posts atomically for the confirmed subset.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because preparation still blocks on any shortage and release is all-or-nothing.

- [ ] **Step 3: Rework `PrepareProductionStock`**

Remove the all-or-nothing shortage block; the automatic path proposes what is available, the manual path validates only the provided allocations, and confirmation posts the confirmed subset. Keep per-run atomicity for the posted rows and the cross-run "posted together" guarantee. Status becomes `reserved` only when `productionIsFullyReserved` still holds.

- [ ] **Step 4: Rework `ReleaseProductionStock`**

Accept an optional `productionRequirementId`; release only that requirement's active reservations; return the run to `scheduled` only when no active reservations remain.

- [ ] **Step 5: Add coverage to the production sheet**

Per-requirement coverage display and a resume action on the detail page, backed by the existing prepare flow.

- [ ] **Step 6: Verify Task 9 and commit**

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/PrepareProductionStock.php app/Actions/Production/ReleaseProductionStock.php app/Livewire/ProductionBench/Production/StockPreparation.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/stock-preparation.blade.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: partial stock preparation and release"
```

Expected: focused tests PASS.

---

### Task 10: Production sheet execution UI and translations

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing UI tests**

The detail page shows: lifecycle actions appropriate to status (Start / Record actuals / Complete / Abort / Release stock / Cancel), per-requirement coverage, the actuals section with "not yet posted" labeling, the journal, the output lot card, and the readiness summary. The list page shows delete only for deletable runs. All copy keys exist in every supported locale.

- [ ] **Step 2: Run focused UI tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
```

Expected: FAIL because the execution UI does not exist.

- [ ] **Step 3: Build the execution sections on the detail page**

Order: identity and status → coverage and stock → actuals (editable while `in_production`) → journal → output lot → history of terminal actions. Keep the page livewire-simple; defer heavy inline lot pickers to the prepare page.

- [ ] **Step 4: Translate contextually**

Add keys for start/complete/abort/release/issue/journal/coverage/readiness in every supported locale in `interface-translations.json`, respecting the catalogue's strict group+key sort.

- [ ] **Step 5: Verify Task 10 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
npm run build
git add app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php resources/views/livewire/production-bench/production/production-index.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: production sheet execution experience"
```

Expected: focused tests PASS.

---

### Task 11: Regression, documentation reconciliation, and release verification

**Files:**

- Modify: `docs/superpowers/specs/2026-07-28-production-bench-design.md`
- Modify: `docs/superpowers/plans/2026-07-28-production-bench-delivery-roadmap.md`
- Verify: all files changed by Tasks 1–10

- [ ] **Step 1: Mark Checkpoint 4 delivered in the roadmap** and confirm the design doc corrections (deletion, start gate, partial reservation) are recorded.

- [ ] **Step 2: Run the complete affected backend suite**

```bash
php artisan test --compact \
  tests/Feature/ProductionExecutionTest.php \
  tests/Feature/ProductionPlanningTest.php \
  tests/Feature/ProductionPlanningSchemaTest.php \
  tests/Feature/ProductionStockPreparationTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php \
  tests/Feature/ProductionBenchProductionsTest.php \
  tests/Feature/ProductionBenchProductionCalendarTest.php \
  tests/Feature/ProductionBenchProductionCreateTest.php \
  tests/Feature/GenerateFlashProductionsTest.php \
  tests/Feature/FlashProductionSimulatorTest.php \
  tests/Feature/ProductionTaskSchemaTest.php \
  tests/Feature/RecipeDeletionTest.php
```

Expected: all tests PASS.

- [ ] **Step 3: Run formatting, build, and the full suite**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
```

Expected: PASS, or only a proven pre-existing unrelated failure documented with its exact test name.

- [ ] **Step 4: Refresh the knowledge graph**

```bash
graphify update .
```

Expected: graph includes `ProductionCompletionService`, `StartProduction`, `CompleteProduction`, `AbortProduction`, `ProductionConsumption`, and the revised lifecycle edges.

- [ ] **Step 5: Commit documentation and final adjustments**

```bash
git add docs/superpowers/specs/2026-07-28-production-bench-design.md docs/superpowers/plans/2026-07-28-production-bench-delivery-roadmap.md docs/superpowers/plans/2026-07-28-production-bench-phase-4-execution-costing.md
git commit -m "docs: finalize production execution checkpoint"
```

---

## Review checkpoint

Demonstrate in one workspace:

1. Create a draft, reserve nothing, delete it; delete a scheduled run before reserving.
2. Reserve partially with a shortage; confirm the run stays `scheduled`, shortfalls are visible, and returning later completes coverage to `reserved`.
3. Assign the permanent batch number, print the sheet, and start the run; verify start is blocked without it.
4. Record actuals (including over-consumption) and journal entries during production; verify no stock changed yet.
5. Complete the run; verify consumption movements, released unused reservations, one output lot coded with the permanent batch number, negative stock where over-consumed, immutable actual costs, and a closed run.
6. Release the output lot after `available_from`; issue finished goods (shipment/sample/damaged/internal use).
7. Start a second run, record partial actuals, abort it; verify consumption, released reservations, and the `aborted` state.
8. Confirm the readiness summary lists everything missing before a run can complete.
9. Verify no `public_id` UUID appears on list, detail, or stock-preparation pages.
10. Re-run the completion test with a forced failure and confirm full atomic rollback.

## Explicitly deferred

- Labor, energy, tax, overhead allocation, and automatic freight allocation in batch costing.
- Mold, equipment, capacity, and time-tracking models (per the design's No Mold Model).
- Configurable QC templates and laboratory workflows (release stays lightweight).
- Customer and sales orders (finished-goods issue movements only).
- Merging or linking with Basic `ProductionBatch` snapshots.
- Automatic adoption of later formula changes by in-flight runs.
- `Repeat production` prefill convenience (may be added after Checkpoint 4 if desired).
