# Production Execution Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resolve the production-execution code review for the Checkpoint 4 scope (`4e7d844..85fab0b`): correct actual-cost math, propagate intermediate costs, preserve and extend actuals, fix currency handling, tighten permissions, concurrency, schema invariants, journal scope, and output-issue semantics — plus two product additions: a default ready/available date on output lots, and consistent number formatting across production views.

**Design authority:** [Production Bench Design](../specs/2026-07-28-production-bench-design.md) §Production Run Model, §Output Quarantine and Release, §Costing; [Checkpoint 4 plan](2026-07-28-production-bench-phase-4-execution-costing.md) as amended by this plan.

**Verified facts this plan builds on (checked against the code):**

- Receipts store canonical **grams** (`ReceivePurchaseOrder` → `massConverter->toGrams(...)`); `historical_unit_cost` is therefore cost **per gram**; the completion service divides by 1000 again → **1000× understated production cost** (Critical 1).
- `ProductionDetail` gates mutation controls by `isReadOnly` (27 blade usages) instead of `canMutate` (2) — viewer-role members see enabled controls.
- The phase-4 plan's deletion task still says a permanent number blocks deletion; the later decision (deletable when unreserved, number burned) must be reconciled in the plan document.
- Journal entries store escaped plain text; no attachment mechanism is wired.
- No database invariants exist for completion-columns-together, positive totals, one output lot per run, or one consumption row per requirement/lot.

**Approved product corrections (recorded here; to be reflected in the design doc during execution):**

1. **Ready date** — output lots get `available_from = manufacture_date + 21 days` by default (curing window). Release stays manual and remains blocked until `available_from` (already enforced). This resolves the "curing delay" review item: quarantine + default ready date + manual release. Documented as the curing mechanism; no per-product configuration in this pass.
2. **Number formatting** — every displayed production number (masses, units, costs, coverage, output, issue quantities, totals) uses the user's workspace number format via `NumberLocale` (decimal separators, thousands grouping) with `auth()->user()?->number_locale`; editable inputs normalize on save with `NumberLocale::normalizeDecimalString` (the convention already used by the planning form).

---

## Product invariants

- Actual ingredient cost = consumed grams × lot cost **per gram** (receipt truth).
- Completion never silently treats a missing lot cost as zero: unpriced lines are visible and reported, totals reflect only priced lines, and the UI shows a warning.
- An intermediate output lot carries its producing batch's cost per gram and currency; a later production consuming it prices it correctly.
- Actuals survive page reloads; defaults are only suggestions for requirements with no saved actuals.
- One ingredient may be consumed from several lots; the sheet records one quantity per lot.
- All production costing lines resolve to one workspace currency (converted at completion via the exchange-rate snapshot) or completion is rejected for mixed currencies.
- Finished-product output lots are not reservable and issue without reservation checks; intermediate output lots subtract active reservations from physical stock before issuing.
- All mutation controls on the production sheet require mutation rights (`canMutate`), not merely an active entitlement.
- Every production action locks workspace → run → requirements → lots → reservations in one deterministic order.
- Completion readiness is visible before submission: missing actuals, coverage, output, manufacture date, batch number, and unpriced lots.
- Schema invariants are enforced at the database boundary.
- A deleted production burns its permanent batch number (already implemented); the plan documents reflect it.

---

### Task 1: Fix the per-gram production cost (Critical 1)

**Files:**

- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`
- Modify: `tests/Feature/ProductionReceiptCostingTest.php` (create if absent)

- [ ] **Step 1: Write a failing regression test that uses a real receipt**

Post a real goods receipt through `ReceivePurchaseOrder` / `PostGoodsReceiptLine` (e.g., €60 for 4,800 g → `historical_unit_cost` 0.0125/g), plan and start a production consuming 4,000 g of that lot, complete it, and assert `actual_ingredient_total = 50.000000000` (not `0.05`). Replace the current manual `historical_unit_cost = 4.000000000` fixture with the realistic per-gram value.

- [ ] **Step 2: Run the focused test and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionReceiptCostingTest.php
```

Expected: FAIL with the 1000× understatement.

- [ ] **Step 3: Remove the `/1000` division**

`lineCost()` for ingredients becomes `bcmul($row->quantity, $pricePerUnit, 9)` — grams × cost-per-gram. Keep packaging unchanged (units × cost-per-unit).

- [ ] **Step 4: Verify Task 1 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionReceiptCostingTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionReceiptCostingTest.php
git commit -m "fix: production ingredient cost per gram"
```

---

### Task 2: Number formatting across production views (product addition)

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Write failing formatting tests**

Assert the detail page renders a production with `basis_input_value 1234.5` and user locale `fr` (decimal comma, space thousands) as `1 234,5` (or the app's `NumberLocale` convention), and coverage/actuals/output/totals values render through `NumberLocale` rather than raw strings. Assert the planning form's basis input still normalizes comma decimals on save.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
```

- [ ] **Step 3: Apply `NumberLocale` everywhere numbers are displayed**

Cover: list cards (basis, units, planning reference unchanged), detail (basis, expected units, requirement masses/units, coverage reserved/short, formula-line masses/percentages, actuals, output quantity, cost totals, cost per unit, issue quantities), stock preparation (quantities, available). Editable inputs keep raw values but parse via `NumberLocale::normalizeDecimalString` on save; add an `@error` display if normalization fails.

- [ ] **Step 4: Verify Task 2 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
npm run build
git add resources/views/livewire/production-bench/production/ app/Livewire/ProductionBench/Production/ lang/en/production_bench.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: format production numbers per workspace locale"
```

---

### Task 3: Propagate intermediate output costs (Critical 2)

**Files:**

- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing intermediate-cost tests**

Produce intermediate A (e.g., 12,000 g output, €44 ingredient cost → €0.003667/g), assert its output lot stores `historical_unit_cost` (per gram) and `currency`. Then produce B consuming A's lot; complete B and assert B's ingredient total prices A's lot per gram (realistic values, no silent zero). Assert that completing with unpriced lots still works but reports them (visible warning, totals from priced lines only) — never a silent zero for a priced-elsewhere line.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

On completion of an intermediate: `cost_per_gram = actual_ingredient_total / actual_output_mass_grams`; write `historical_unit_cost` + `costing_unit_cost` + `currency`/`costing_currency` on the output lot. Unpriced consumption lines are counted and exposed (e.g., `unpriced_line_count` derived from consumption rows with null `price_per_unit`) so the UI can warn instead of silently zeroing.

- [ ] **Step 4: Verify Task 3 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: propagate intermediate output costs"
```

---

### Task 4: Preserve actuals across reloads

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing reload tests**

Save actuals, re-mount the detail page (fresh Livewire test), assert the inputs show the saved quantities — not the reservation defaults. Assert defaults are used only for requirements with no saved actuals.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Load consumption into `$actualRows` on mount**

Populate `$actualRows` from the run's consumption rows during `mount()` (or the first render) so reloads show real actuals; `defaultActualRows` remains the fallback only.

- [ ] **Step 4: Verify Task 4 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Livewire/ProductionBench/Production/ProductionDetail.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: preserve production actuals across reloads"
```

---

### Task 5: Multi-lot actual consumption

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing multi-lot tests**

Two lots reserved for one ingredient requirement (e.g., 8,000 g + 6,000 g of the 14,000 g requirement). Assert the sheet renders one row per lot with the lot code, both quantities are saved (rows keyed by requirement + lot), and completion consumes both lots with correct per-lot movements and costs.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Rework the actuals section**

Rows keyed by `requirement_id + lot_id`; each row shows the lot code and an editable quantity; the total per requirement is displayed and compared to the required amount. `SaveProductionActuals` already accepts multiple rows per requirement — only the state shape and blade change.

- [ ] **Step 4: Verify Task 5 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
npm run build
git add resources/views/livewire/production-bench/production/production-detail.blade.php app/Livewire/ProductionBench/Production/ProductionDetail.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: multi-lot actual consumption on the production sheet"
```

---

### Task 6: Workspace-currency costing

**Files:**

- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing mixed-currency tests**

Create two lots with different source currencies (e.g., EUR and USD with a fixed exchange-rate snapshot). Assert completion either (a) converts all lines to the workspace default currency using the lot's `costing_unit_cost`/`costing_currency` (the receipt flow already stores workspace-converted values) and produces correct totals in one currency, or (b) rejects with a clear mixed-currency error — pick one behavior and test it. Also assert the single-currency path stays unchanged.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

Prefer `costing_unit_cost` + `costing_currency` (workspace-converted at receipt) as the price source; fall back to `historical_unit_cost` + `currency` only when the costing values are absent. If lines resolve to different currencies and conversion is unavailable, reject completion with the offending lot names. `cost_currency` on the run = the workspace currency.

- [ ] **Step 4: Verify Task 6 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionCompletionService.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: workspace-currency production costing"
```

---

### Task 7: Output-issue semantics for finished products versus intermediates

**Files:**

- Modify: `app/Actions/Production/IssueFinishedGoods.php`
- Modify: `app/Actions/Production/ReleaseOutputLot.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing semantics tests**

Intermediate output lot (mass, ingredient subject) with active reservations: issuing more than (physical − reserved) is rejected; issuing within it succeeds. Finished-product output lot (count, recipe subject): issue proceeds without reservation checks; fractional issue quantities are rejected for count lots; decimal grams remain allowed for intermediate lots. `ReleaseOutputLot` and `IssueFinishedGoods` reject lots whose origin is not `production_output`.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

`IssueFinishedGoods`: for mass/intermediate lots, compute `available = physical − active reservations` before the over-issue check; reject fractional quantities when `unit_kind` is count; verify `origin === production_output` on both actions.

- [ ] **Step 4: Verify Task 7 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/IssueFinishedGoods.php app/Actions/Production/ReleaseOutputLot.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: output issue semantics for intermediates and finished goods"
```

---

### Task 8: Permission gating and deterministic lock ordering

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `app/Actions/Production/StartProduction.php`
- Modify: `app/Actions/Production/CompleteProduction.php`
- Modify: `app/Actions/Production/AbortProduction.php`
- Modify: `app/Actions/Production/DeleteProductionRun.php`
- Modify: `app/Actions/Production/ReleaseProductionStock.php`
- Modify: `app/Actions/Production/SaveProductionActuals.php`
- Modify: `app/Actions/Production/SaveProductionJournalEntry.php`
- Modify: `app/Actions/Production/PrepareProductionStock.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing permission tests**

A viewer-role workspace member sees disabled/hidden start, complete, abort, release, issue, cancel, and journal controls on the detail page (assert `disabled` attributes or absent buttons), while an editor sees them enabled. Assert the actions still reject at the boundary (already true via `assertWritable`).

- [ ] **Step 2: Write failing lock-order tests**

Assert every production action locks in the order workspace → run → requirements → lots → reservations. This is structural: add a test that completes, cancels, and releases stock concurrently (parallel Livewire/action calls) and assert no deadlock or partial state; document the canonical order in a comment shared across actions. `isFullyReserved()` in `StartProduction`/`ReleaseProductionStock` locks the reservation rows it reads.

- [ ] **Step 3: Implement**

Blade: replace `@disabled($isReadOnly)` with `@disabled($isReadOnly || ! $canMutate)` on all mutation controls (and hide the journal form and issue form when `! $canMutate`). Actions: extract the canonical lock sequence (workspace first, then run, then requirements, lots, reservations in ID order) into a shared helper or consistent inline pattern; `isFullyReserved` queries use `lockForUpdate`.

- [ ] **Step 4: Verify Task 8 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/ app/Services/Production/ProductionCompletionService.php resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "fix: permission gating and lock ordering for production actions"
```

---

### Task 9: Completion readiness summary

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing readiness tests**

An `in_production` run without actuals shows a visible checklist: missing actual quantities (named), missing coverage, missing output quantity, missing manufacture date, missing batch number, unpriced lots (after Task 3). Each item resolves as the operator fills it. The checklist updates without a page reload (Livewire reactivity).

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

Compute a readiness array in `render()` (actuals coverage per requirement, output quantity, manufacture date, batch number, unpriced consumption lines) and render it above the completion form with per-item status.

- [ ] **Step 4: Verify Task 9 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
npm run build
git add app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: completion readiness summary on the production sheet"
```

---

### Task 10: Journal rich text and private attachments

**Files:**

- Modify: `app/Actions/Production/SaveProductionJournalEntry.php`
- Modify: `app/Models/ProductionJournalEntry.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing journal tests**

Rich text (HTML) is stored and rendered without escaping the intended formatting while remaining safe (no script execution). Private attachments attach to an entry through the existing morphable document/media pattern and are workspace-scoped. Entries stay chronological and read-only after close (existing rule).

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

Reuse the `ProductionDocument` morphable pattern (`documentable`) or the existing private media usage mechanism for attachments; render entry bodies as rich content with the app's existing sanitization; add the upload control to the journal form.

- [ ] **Step 4: Verify Task 10 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
npm run build
git add app/Actions/Production/SaveProductionJournalEntry.php app/Models/ProductionJournalEntry.php resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: journal rich text and private attachments"
```

---

### Task 11: Database invariants, rollback order, and documentation reconciliation

**Files:**

- Modify: `database/migrations/2026_08_07_120000_add_production_execution_schema.php` (new migration instead if altering a run migration is undesirable; prefer a new migration `2026_08_07_150000_harden_production_execution_invariants.php`)
- Modify: `app/Actions/Production/SaveProductionActuals.php`
- Modify: `app/Actions/Production/PrepareProductionStock.php`
- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `docs/superpowers/plans/2026-07-28-production-bench-phase-4-execution-costing.md`
- Modify: `docs/superpowers/specs/2026-07-28-production-bench-design.md`
- Modify: `tests/Feature/ProductionPlanningSchemaTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`
- Modify: `tests/Feature/ProductionStockPreparationTest.php`

- [ ] **Step 1: Write failing invariant tests**

Assert database enforcement of: completion columns appearing together (`completed_at`/`completed_by_user_id`/`manufacture_date`/totals) and positive totals when present; exactly one output lot per production (partial unique index on `stock_lots.production_run_id` where origin = production_output); at most one consumption row per (run, requirement, lot) (unique index); mixed/manual allocations cannot exceed the requirement total. Also assert rollback (`migrate:rollback` on a scratch DB) succeeds with correct ordering — constraints dropped before tables/columns.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionStockPreparationTest.php
```

- [ ] **Step 3: Implement**

New migration with pgsql CHECKs + sqlite triggers (trigger-preservation discipline for any rebuilds) and the unique indexes. `SaveProductionActuals` rejects lot-less rows with a clear message (instead of failing later at completion/abort with `findOrFail(null)`). `PrepareProductionStock` caps manual allocations at the requirement total. `ProductionCompletionService` surfaces unpriced lines (already Task 3). Down() in the new migration drops constraints before dropping tables/columns.

- [ ] **Step 4: Reconcile documentation**

Update the phase-4 plan's Task 2 wording (numbered productions are deletable when unreserved; the number is burned) and the design doc with the ready-date correction (output lots default `available_from = manufacture_date + 21 days`) and the curing-deferral note.

- [ ] **Step 5: Verify Task 11 and commit**

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionStockPreparationTest.php
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_07_150000_harden_production_execution_invariants.php app/Actions/Production/SaveProductionActuals.php app/Actions/Production/PrepareProductionStock.php app/Services/Production/ProductionCompletionService.php docs/superpowers/plans/2026-07-28-production-bench-phase-4-execution-costing.md docs/superpowers/specs/2026-07-28-production-bench-design.md tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionStockPreparationTest.php
git commit -m "feat: harden production execution invariants"
```

---

### Task 12: Ready date on output lots (product addition)

**Files:**

- Modify: `app/Services/Production/ProductionCompletionService.php`
- Modify: `app/Models/StockLot.php` (if a ready-date helper is added)
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing ready-date tests**

Completing a production with `manufacture_date = 2026-08-20` creates the output lot with `available_from = 2026-09-10` (21 days later). Release before that date is rejected (existing rule); release on/after succeeds. The output card displays the ready date.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

`ProductionCompletionService` sets `available_from = manufacture_date + 21 days` on the output lot (constant, documented as the default curing window; quarantine + manual release remain). Display the ready date on the output card.

- [ ] **Step 4: Verify Task 12 and commit**

```bash
php artisan test --compact tests/Feature/ProductionExecutionTest.php
npm run build
git add app/Services/Production/ProductionCompletionService.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: default ready date on production output lots"
```

---

### Task 13: Regression, remaining additional items, and release verification

**Files:**

- Modify: `app/Actions/Production/ProductionDetail.php` (N+1 audit if needed)
- Verify: all files changed by Tasks 1–12

- [ ] **Step 1: Audit and fix N+1 queries in production views**

Check list (task counts), detail (coverage, actuals, journal authors), stock preparation (lot availability), and the flash planner; eager-load what the templates touch.

- [ ] **Step 2: Document `expires_at` on output lots**

Finished-product lots keep `expires_at = null` (no expiry concept); intermediate output lots may carry an optional expiry when the maker sets one. Add a one-line note to the design doc; no schema change.

- [ ] **Step 3: Run the complete affected suite**

```bash
php artisan test --compact \
  tests/Feature/ProductionExecutionTest.php \
  tests/Feature/ProductionPlanningTest.php \
  tests/Feature/ProductionPlanningSchemaTest.php \
  tests/Feature/ProductionStockPreparationTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php \
  tests/Feature/ProductionBenchProductionsTest.php \
  tests/Feature/ProductionReceiptCostingTest.php
```

Expected: all PASS.

- [ ] **Step 4: Format, build, full suite, graph**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
graphify update .
```

Expected: full suite PASS (or only the documented pre-existing `UserIngredientAuthoringTest` failure); graph refreshed.

- [ ] **Step 5: Commit final adjustments**

```bash
git add docs/superpowers/specs/2026-07-28-production-bench-design.md docs/superpowers/plans/2026-08-07-production-execution-review-fixes.md
git commit -m "docs: finalize production execution review fixes"
```

---

## Review checkpoint

1. A real receipt (€60 / 4,800 g) feeds a completed production consuming 4,000 g → ingredient total exactly €50.
2. An intermediate production prices its output lot per gram; a downstream production consumes it and shows real cost, never silent zero.
3. Mixed-currency lots either resolve to the workspace currency or completion is rejected with the offending lots named.
4. Actuals survive reload; two lots of one ingredient are recorded per lot and consumed correctly.
5. The completion form shows a live readiness checklist; missing items are named.
6. Viewer-role members see no enabled mutation controls; editors do.
7. Intermediates with reservations cannot be over-issued; finished goods issue freely; count lots reject fractions.
8. Concurrent completion/cancel/release produce no deadlocks; locks follow one documented order.
9. Schema invariants reject bad rows at the database boundary; `migrate:rollback` works in order.
10. Output lots show a ready date 21 days after manufacture; release is blocked until then.
11. All displayed numbers follow the user's workspace number format.

## Explicitly deferred

- Per-product curing-delay configuration (fixed 21-day default for now).
- Automatic output release (release stays manual).
- Per-line exchange-rate conversion UI at completion (uses the receipt-time workspace conversion).
- Basic `ProductionBatch` linking (unchanged).
