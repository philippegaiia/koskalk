# Production Bench UX Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the production run cycle visible and understandable at a glance: a real draft stage, statuses that "shout" where the run is, explicit partial-reservation signals, a proper table list with a header, a compact task section, and a clear primary action per status.

**Design authority:** [Production Bench Design](../specs/2026-07-28-production-bench-design.md) §Production Run Model (Draft/Scheduled/Reserved/In production/Completed/Cancelled/Aborted), [Checkpoint 4 plan](2026-07-28-production-bench-phase-4-execution-costing.md), and the August review-fixes plan — all delivered.

**Verified starting points (checked against the code):**

- `ProductionCreate` validates `plannedFor` as `required` → the UI cannot produce a `draft`; every creation goes straight to `scheduled` ("planned"). `ScheduleProduction` (draft → scheduled) exists as an action but has **no UI**.
- The production list is a card stack (`divide-y`) with **no column headers**; the status is a single `accent-soft` pill identical for every status (does not communicate urgency).
- The detail page shows controls contextually but there is no prominent "where are we" banner or per-status primary CTA; some actions (delete, abort) are plain text links that are easy to miss.
- Partial reservation exists in data (coverage shown on detail) but is **not signaled** on the list or as an alert.
- The task rows stack department + employee selects vertically because both are `min-w-48` (12rem).

---

## Product invariants (unchanged)

- The lifecycle remains `Draft → Scheduled → Reserved → In production → Completed` with `Cancelled` / `Aborted` terminal paths; the fix list below only makes existing states reachable and visible — it does not change the state machine.
- Draft = no date, no stock effect; scheduling a draft sets the date (existing `ScheduleProduction`).
- Partially reserved = `scheduled` with active reservations below full coverage; fully covered → `reserved`.
- Every mutation control remains gated by `canMutate`; read-only/entitlement rules unchanged.
- Number formatting stays `NumberLocale`-driven everywhere.

---

### Task 1: Reachable draft stage

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionCreate.php`
- Modify: `resources/views/livewire/production-bench/production/production-create.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionBenchProductionCreateTest.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`

- [ ] **Step 1: Write failing draft-flow tests**

Cover: creating a production without a date succeeds as `draft` (date stays null, no working-calendar validation); creating with a date remains `scheduled`; a draft can be scheduled from the list and from the detail page via the existing `ScheduleProduction` action (date becomes required at that point, working-day check applies); scheduling is rejected for non-drafts; the draft's requirements/formula snapshot are created exactly like a scheduled run (same builder path); cancellation of a draft has no stock effect.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionPlanningTest.php
```

- [ ] **Step 3: Implement**

`ProductionCreate`: make `plannedFor` optional (`nullable|date_format`); pass null to `PlanProduction` when empty. Drafts keep the existing idempotency and snapshot creation paths. Wire a **Schedule** action on draft rows (list + detail) calling the existing `ScheduleProduction` with a date picker; validate the working-day rule there. Keep "Create & schedule" (with date) as the primary button and add "Save as draft" (no date) as secondary.

- [ ] **Step 4: Verify Task 1 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionPlanningTest.php
vendor/bin/pint --dirty --format agent
git add app/Livewire/ProductionBench/Production/ tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionPlanningTest.php lang/en/production_bench.php
git commit -m "feat: reachable draft stage with scheduling"
```

---

### Task 2: Statuses that shout

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Write failing status-visibility tests**

Assert each status renders a distinct badge style (class or data attribute) on the list and the detail page: draft neutral, scheduled blue, reserved amber, in_production green, completed solid, cancelled/aborted muted. Assert the detail page shows a status banner with the correct primary action label per status: draft → Schedule; scheduled → Prepare stock; reserved → Assign batch number (or Start when numbered); in_production → Complete production; completed → Output lot; cancelled/aborted → muted history banner.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
```

- [ ] **Step 3: Implement**

Per-status badge classes (mapped via a small blade/component helper keyed on `ProductionRunStatus`), and a prominent banner on the detail page under the header showing status + the single next action as a strong primary button (reusing the existing actions; the banner is informational + CTA, not new state logic). Ensure contrast meets the existing design tokens.

- [ ] **Step 4: Verify Task 2 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
npm run build
git add resources/views/livewire/production-bench/production/ app/Livewire/ProductionBench/Production/ProductionDetail.php lang/en/production_bench.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: status banners and per-status badges"
```

---

### Task 3: Partial reservation signals

**Files:**

- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing partial-coverage tests**

A `scheduled` run with active reservations below full coverage shows a visible "Partially reserved — short by :short" badge on the list row and a banner on the detail page; a fully covered run shows no such badge; a run with no reservations shows nothing (draft/planned without stock is normal); the badge text uses formatted numbers (`NumberLocale`).

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

Add a computed coverage signal to the list query (sum active reservations per run in one aggregate query — avoid N+1) and to the detail render (reuse the existing readiness/coverage computation). Render the badge/banner only for `scheduled` runs with `0 < reserved < required`.

- [ ] **Step 4: Verify Task 3 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
npm run build
git add app/Livewire/ProductionBench/Production/ tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php lang/en/production_bench.php
git commit -m "feat: partial reservation signals"
```

---

### Task 4: Production table with header

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Write failing table tests**

The list renders a `<table>` with `<thead>` columns: selection | production (identifier · product) | status | production date | batch size | expected units | tasks | actions. Each row shows the batch size in the workspace unit format and the task count; the delete action remains row-scoped and confirmed; the multi-select checkbox remains; the bulk action bar remains above the table.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
```

- [ ] **Step 3: Implement**

Convert the card stack to a table (responsive: stacked card layout on small screens via the existing design tokens, table ≥ `lg`). Keep all existing filters, search (snapshot name), recipe filter, selection, bulk actions, and the row delete with `wire:click.stop`.

- [ ] **Step 4: Verify Task 4 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
npm run build
git add resources/views/livewire/production-bench/production/production-index.blade.php app/Livewire/ProductionBench/Production/ProductionIndex.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: production list table with header"
```

---

### Task 5: Compact task section

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`

- [ ] **Step 1: Write failing compactness tests**

The task row renders name, department select, employee select, date, and toggle on one line at desktop width (assert the selects use a narrow width class, e.g. `w-36`/`w-40`, not `min-w-48`); the section keeps the existing assign/reschedule/toggle behaviors and errors.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
```

- [ ] **Step 3: Implement**

Single-row task layout: task name (fixed), inline narrow selects, inline date input, toggle button; wrap gracefully on small screens. Keep the completion checkbox semantics unchanged.

- [ ] **Step 4: Verify Task 5 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
npm run build
git add resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: compact inline task controls"
```

---

### Task 6: Button hierarchy and visibility

**Files:**

- Modify: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `tests/Feature/ProductionBenchProductionsTest.php`
- Modify: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Write failing hierarchy tests**

Assert the primary action per status uses the primary button style, secondary actions use outline, and destructive actions (abort, delete, cancel) use a visible danger style (class containing danger) rather than plain text links.

- [ ] **Step 2: Verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
```

- [ ] **Step 3: Implement**

Upgrade destructive controls (delete on list, abort on detail) to solid/visible danger buttons; keep the primary CTA per status (from Task 2) as the strongest element; remove ambiguity between "cancel production" and "delete".

- [ ] **Step 4: Verify Task 6 and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
npm run build
git add resources/views/livewire/production-bench/production/ lang/en/production_bench.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php
git commit -m "feat: button hierarchy and destructive visibility"
```

---

### Task 7: Regression and release verification

**Files:**

- Verify: all files changed by Tasks 1–6

- [ ] **Step 1: Run the complete affected suite**

```bash
php artisan test --compact \
  tests/Feature/ProductionBenchProductionsTest.php \
  tests/Feature/ProductionBenchProductionCreateTest.php \
  tests/Feature/ProductionExecutionTest.php \
  tests/Feature/ProductionPlanningTest.php \
  tests/Feature/ProductionPlanningSchemaTest.php \
  tests/Feature/ProductionStockPreparationTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php
```

- [ ] **Step 2: Format, build, full suite, graph**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
graphify update .
```

Expected: full suite PASS (or only the documented pre-existing `UserIngredientAuthoringTest` failure); graph refreshed.

- [ ] **Step 3: Commit final adjustments**

```bash
git add docs/superpowers/plans/2026-08-07-production-bench-ux-improvements.md
git commit -m "docs: finalize production bench ux improvements"
```

---

## Review checkpoint

1. Create a production without a date → it is a `draft`; schedule it later from list or detail; the calendar rule applies only at scheduling.
2. The list is a table with headers (date, batch size, expected units, tasks) and per-status colored badges; a partially reserved run shows "short by X".
3. The detail page opens with a banner: "Draft — Schedule", "Planned — Prepare stock", "Stock prepared — Assign batch number", "In production — Complete", "Completed — Output lot"; cancelled/aborted show a muted history banner.
4. Task rows are one line at desktop width with inline selects; destructive actions are visibly danger-styled.

## Explicitly deferred

- Journal rich-text editor (attachments already wired).
- Checkpoint 5 traceability (genealogy, search, print/export) — separate plan.
- Any lifecycle state-machine changes (this plan only makes existing states reachable and visible).
