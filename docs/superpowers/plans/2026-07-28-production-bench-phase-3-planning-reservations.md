# Production Bench Phase 3 Planning and Reservations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Let a small maker plan one or several production batches from a published product, automatically obtain dated follow-up tasks, understand future material demand, and explicitly reserve suitable stock lots only when ready.

**Architecture:** `ProductionRun` is the durable production record. It pins one published `RecipeVersion` and owns immutable-subject, rescalable planning requirements for ingredients and packaging. Reusable batch presets and task sets only prefill a production; generated production tasks snapshot their scheduling inputs. Planned requirements affect forecast demand but not available stock. Separate `StockReservation` rows connect one requirement to one or several lots after an explicit preview and confirmation. The Flash simulator reuses the same planning actions and remains non-persistent until the user confirms dated production rows.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, BCMath, PostgreSQL transactions and row locking, Vite 8, and `@event-calendar/core`.

**Execution:** Implement inline on `main`, in the numbered order below. Do not create a feature branch or worktree. Preserve unrelated worktree changes and stage only the files named by each task.

Every `git add` command below is intentionally path-specific. Replace migration `*` placeholders with the exact timestamped files generated for that task; never stage an entire application, migration, view, test, or language directory.

**Scope boundary:** This plan implements Checkpoint 3 only. It creates forecasts and reservations but no stock movements. Starting production, actual lot consumption, finished output, and actual historical production cost remain Checkpoint 4.

---

## Product Invariants

- Customer language is **Production** or **Production batch**; the internal model is `ProductionRun`.
- A production belongs to one workspace, product (`Recipe`), and pinned published `RecipeVersion`.
- A later formula publication never rewrites an existing production.
- Soap productions scale from initial oil mass; cosmetic productions scale from total formula mass.
- Packaging scales from expected finished units.
- A production may be planned despite missing current prices or insufficient stock.
- Draft and planned quantities may be edited before reservation; the pinned product/version never changes.
- Editing planned quantities rebuilds requirements atomically and is rejected while reservations exist.
- Planning creates forecast demand only. It does not change physical or available stock.
- Reservation changes available stock but never physical stock.
- One visible requirement may be fulfilled by several lot reservations.
- Reservation confirmation is explicit, idempotent, all-or-nothing for the selected productions, and protected by row locks.
- Cross-workspace references and mutations in a cancelled/read-only Production Bench are rejected.
- Batch-size presets and task sets are optional conveniences, never prerequisites.
- Generated tasks snapshot their name, offset, duration, and calculated date; template edits do not rewrite them.
- The first generated task is the production-date anchor: its offset is always zero and its date always equals the production date.
- Before production starts, changing the first task date changes the production date, and changing the production date changes the first task date.
- The first task is never shifted for a weekend or holiday chosen explicitly by the user.
- Later automatic tasks move forward to the next working day; completed and custom-dated tasks do not move.
- Flash simulation does not persist anything. Flash generation creates independent planned productions, without waves, capacity records, or reservations.

## Status Contract

Store the durable enum values below while presenting the simpler customer labels:

| Internal status | Customer label | Checkpoint 3 behavior |
|---|---|---|
| `Draft` | Draft | Editable; no forecast demand |
| `Scheduled` | Planned | Remaining requirements contribute to forecast |
| `Reserved` | Stock prepared | All remaining requirements are fully reserved |
| `InProduction` | In production | Reserved for Checkpoint 4 |
| `Completed` | Completed | Reserved for Checkpoint 4 |
| `Cancelled` | Cancelled | Terminal; reservations released |
| `Aborted` | Aborted | Reserved for Checkpoint 4 |

Only `Draft`, `Scheduled`, `Reserved`, and `Cancelled` are mutated in this checkpoint.

## Canonical Quantity Contract

- Ingredient requirement quantities are decimal grams (`decimal(20, 9)`).
- Packaging requirement quantities are positive whole units (`bigInteger`).
- Production basis is stored as canonical grams plus the entered value/unit snapshot used for display.
- Percentage, quantity, price, and value arithmetic uses BCMath-backed services; never PHP floats.
- Persist both stable foreign keys and human-readable snapshots needed to understand the plan after catalog edits.

---

## Task 1: Production Lifecycle and Requirement Schema

This is the first implementation slice. It establishes the durable records and lifecycle rules without yet adding customer UI.

**Files:**

- Create: `app/ProductionRunStatus.php`
- Create: `app/ProductionRequirementKind.php`
- Create: `app/ProductionRunSource.php`
- Create: `app/ProductionBasisKind.php`
- Create: `app/Models/ProductionRun.php`
- Create: `app/Models/ProductionRequirement.php`
- Create: `database/factories/ProductionRunFactory.php`
- Create: `database/factories/ProductionRequirementFactory.php`
- Create: `database/migrations/*_create_production_runs_table.php`
- Create: `database/migrations/*_create_production_requirements_table.php`
- Create: `app/Policies/ProductionRunPolicy.php`
- Modify: `lang/en/production_bench.php`
- Modify: `app/Models/Workspace.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Models/RecipeVersion.php`
- Create: `tests/Feature/ProductionPlanningSchemaTest.php`

### Step 1: Search version-specific documentation

Use Laravel Boost `search-docs` for Eloquent enum casts, policies, database checks, model factories, and transaction testing before editing code.

### Step 2: Generate the model, migration, policy, factory, and Pest test files

Use Laravel Artisan generators with `--no-interaction`. Keep models in `app/Models`, enums in `app`, and policies in `app/Policies`, matching existing project conventions.

### Step 3: Write the failing schema and relationship tests

Cover:

- all lifecycle values and their customer labels;
- UUID public route IDs through `HasPublicId`;
- workspace, recipe, pinned recipe version, actor, source, production date, basis, expected units, notes, and idempotency fields;
- workspace-scoped unique idempotency keys;
- ingredient versus packaging requirement kinds;
- database-enforced exactly-one subject (`ingredient_id` XOR `packaging_item_id`);
- mass requirements accepting positive decimal grams;
- packaging requirements accepting positive whole units;
- requirement snapshots for label, phase/position, formula percentage or components per unit, and sort order;
- workspace, recipe, and version relationships in both directions;
- policy denial for users outside the workspace and for mutation when Production Bench is read-only.

Run and confirm the expected failure:

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php
```

Expected: FAIL because the production tables and classes do not exist.

### Step 4: Implement the minimal schema and models

`production_runs` stores:

- `public_id`, `workspace_id`, `recipe_id`, `recipe_version_id`;
- `status`, `source`, `planned_for`;
- `basis_kind` (`oil_mass` or `total_formula_mass`), canonical `basis_quantity_grams`, entered value and unit snapshots;
- positive whole `expected_units`;
- nullable notes;
- `idempotency_key`, `created_by_user_id`, cancellation actor/reason/time;
- timestamps.

`production_requirements` stores:

- production and exactly one ingredient/packaging subject;
- optional source recipe-item/packaging-row IDs for traceability;
- kind plus exactly one canonical quantity column: decimal grams for ingredients or whole units for packaging;
- human-readable subject, phase/position, percentage/components-per-unit, and unit snapshots;
- sort order and timestamps.

Add database checks for exactly one subject, matching kind/subject, positive quantities, and whole packaging quantities. Add indexes for workspace/date/status and requirement subject lookups.

The policy is defense-in-depth. Domain actions in later tasks must still validate workspace ownership explicitly.

### Step 5: Verify and commit Task 1

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php
vendor/bin/pint --dirty --format agent
git add app/ProductionRunStatus.php app/ProductionRequirementKind.php app/ProductionRunSource.php app/ProductionBasisKind.php app/Models/ProductionRun.php app/Models/ProductionRequirement.php app/Models/Workspace.php app/Models/Recipe.php app/Models/RecipeVersion.php app/Policies/ProductionRunPolicy.php database/factories/ProductionRunFactory.php database/factories/ProductionRequirementFactory.php database/migrations/*_create_production_runs_table.php database/migrations/*_create_production_requirements_table.php lang/en/production_bench.php tests/Feature/ProductionPlanningSchemaTest.php
git commit -m "feat: add production planning schema"
```

---

## Task 2: Requirement Builder and Planning Lifecycle Actions

**Files:**

- Create: `app/Services/Production/ProductionRequirementBuilder.php`
- Create: `app/Actions/Production/CreateProductionDraft.php`
- Create: `app/Actions/Production/UpdateProductionPlan.php`
- Create: `app/Actions/Production/ScheduleProduction.php`
- Create: `app/Actions/Production/PlanProduction.php`
- Create: `tests/Feature/ProductionPlanningTest.php`

### Step 1: Write failing domain tests

Prove:

- a soap production scales every ingredient from initial oil mass;
- a cosmetic production scales from total formula mass;
- packaging requirements scale from expected units;
- the latest published version is pinned at creation and later publications do not change it;
- unpublished/current working versions cannot be planned;
- planning works when prices are missing and when stock is insufficient;
- the routine `PlanProduction` path creates requirements and `Scheduled` status atomically;
- updating basis or expected units rebuilds requirements without changing the pinned version;
- updating is limited to `Draft` and `Scheduled` states;
- scheduling, updating, and direct planning reject cross-workspace subjects and read-only access;
- duplicate workspace/idempotency input returns the same production without duplicate requirements.

Run and confirm failure:

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php
```

### Step 2: Implement requirement calculation

Reuse the published `RecipeVersion` structure, the existing exact mass converter, and the calculation rules already exercised by `RecipeVersionCostPreviewBuilder`. Do not require price rows and do not write `CurrentMaterialPrice`.

Return an in-memory requirement collection first. Persist it only inside the calling action's transaction.

### Step 3: Implement the lifecycle actions

- `CreateProductionDraft` pins the accessible published version and persists requirement snapshots.
- `UpdateProductionPlan` locks a draft/planned production, validates no active reservation, and atomically replaces its derived requirements.
- `ScheduleProduction` locks a draft and changes it to `Scheduled`.
- `PlanProduction` is the common one-click orchestration used by the customer form and later by Flash; it creates and schedules in one retried transaction.

Validate through `ProductionBenchAccess` and explicit same-workspace checks in every action.

### Step 4: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/CreateProductionDraft.php app/Actions/Production/UpdateProductionPlan.php app/Actions/Production/ScheduleProduction.php app/Actions/Production/PlanProduction.php app/Services/Production/ProductionRequirementBuilder.php tests/Feature/ProductionPlanningTest.php
git commit -m "feat: plan production requirements"
```

---

## Task 3: Optional Product Batch-Size Presets

**Files:**

- Create: `app/Models/ProductionBatchPreset.php`
- Create: `database/factories/ProductionBatchPresetFactory.php`
- Create: `database/migrations/*_create_production_batch_presets_table.php`
- Create: `app/Policies/ProductionBatchPresetPolicy.php`
- Create: `app/Actions/Production/SaveProductionBatchPreset.php`
- Modify: `app/Models/Recipe.php`
- Create: `tests/Feature/ProductionBatchPresetTest.php`

### Step 1: Write failing preset tests

Cover zero, one, and several presets per product; examples such as `12 kg / 100 units`; one optional default; active/inactive behavior; exact mass conversion; same-workspace enforcement; and read-only mutation rejection.

### Step 2: Implement the preset model and action

A preset belongs to one recipe and stores name, canonical basis grams, entered value/unit, expected units, default flag, and active flag. It never constrains production and is never copied by reference into requirement arithmetic. Selection only prefills editable production inputs.

`SaveProductionBatchPreset` enforces one default per recipe in a locked transaction. Deactivating or deleting a preset must not alter existing productions.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionBatchPresetTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/ProductionBatchPreset.php app/Models/Recipe.php app/Policies/ProductionBatchPresetPolicy.php app/Actions/Production/SaveProductionBatchPreset.php database/factories/ProductionBatchPresetFactory.php database/migrations/*_create_production_batch_presets_table.php tests/Feature/ProductionBatchPresetTest.php
git commit -m "feat: add production batch presets"
```

---

## Task 4: Employees, Task Templates, and Working Calendar Schema

**Files:**

- Create: `app/Models/Employee.php`
- Create: `app/Models/ProductionTaskType.php`
- Create: `app/Models/ProductionTaskSet.php`
- Create: `app/Models/ProductionTaskSetItem.php`
- Create: `app/Models/ProductionTask.php`
- Create: `app/Models/ProductionHoliday.php`
- Create: `database/factories/EmployeeFactory.php`
- Create: `database/factories/ProductionTaskTypeFactory.php`
- Create: `database/factories/ProductionTaskSetFactory.php`
- Create: `database/factories/ProductionTaskSetItemFactory.php`
- Create: `database/factories/ProductionTaskFactory.php`
- Create: `database/factories/ProductionHolidayFactory.php`
- Create: `database/migrations/*_create_employees_table.php`
- Create: `database/migrations/*_create_production_task_types_table.php`
- Create: `database/migrations/*_create_production_task_sets_table.php`
- Create: `database/migrations/*_create_production_task_set_items_table.php`
- Create: `database/migrations/*_create_production_tasks_table.php`
- Create: `database/migrations/*_create_production_holidays_table.php`
- Create: `database/migrations/*_add_production_planning_defaults.php`
- Create: `app/Policies/EmployeePolicy.php`
- Create: `app/Policies/ProductionTaskTypePolicy.php`
- Create: `app/Policies/ProductionTaskSetPolicy.php`
- Create: `app/Policies/ProductionTaskPolicy.php`
- Create: `app/Policies/ProductionHolidayPolicy.php`
- Create: `app/Actions/Production/SaveEmployee.php`
- Create: `app/Actions/Production/SaveProductionTaskType.php`
- Create: `app/Actions/Production/SaveProductionTaskSet.php`
- Create: `app/Actions/Production/SaveProductionHoliday.php`
- Create: `app/Actions/Production/UpdateProductionWorkingCalendar.php`
- Modify: `app/Models/Workspace.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Models/ProductionRun.php`
- Create: `tests/Feature/ProductionTaskSchemaTest.php`

### Step 1: Write failing schema tests

Cover:

- lightweight employees with first name, last name, and active state only;
- task types with name, optional default minutes, optional colour, and active state;
- ordered task-set items with task type, days after production, and optional duration override;
- the first task-set item being action-constrained to offset zero;
- an optional default task set on a product;
- generated task snapshots for name, offset, duration, scheduled date, automatic/custom state, completion, and nullable employee;
- holidays with one date and recurring flag;
- workspace weekend-working preference;
- same-workspace constraints and policies.

### Step 2: Implement schema and relationships

The relative day belongs to `ProductionTaskSetItem`, not `ProductionTaskType` or the task-set header. Copy it to `ProductionTask` as a snapshot at generation time.

Employees are not users and have no authentication, payroll, shift, or permission fields.

Add nullable `production_task_set_id` to productions and nullable `default_production_task_set_id` to recipes. Deleting/deactivating setup records must preserve generated production history.

Keep setup writes behind small actions that enforce workspace ownership and `ProductionBenchAccess`. `SaveProductionTaskSet` normalizes ordering and forces the first item's offset to zero in one transaction.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionTaskSchemaTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/Employee.php app/Models/ProductionTaskType.php app/Models/ProductionTaskSet.php app/Models/ProductionTaskSetItem.php app/Models/ProductionTask.php app/Models/ProductionHoliday.php app/Models/Workspace.php app/Models/Recipe.php app/Models/ProductionRun.php app/Policies/EmployeePolicy.php app/Policies/ProductionTaskTypePolicy.php app/Policies/ProductionTaskSetPolicy.php app/Policies/ProductionTaskPolicy.php app/Policies/ProductionHolidayPolicy.php app/Actions/Production/SaveEmployee.php app/Actions/Production/SaveProductionTaskType.php app/Actions/Production/SaveProductionTaskSet.php app/Actions/Production/SaveProductionHoliday.php app/Actions/Production/UpdateProductionWorkingCalendar.php database/factories/EmployeeFactory.php database/factories/ProductionTaskTypeFactory.php database/factories/ProductionTaskSetFactory.php database/factories/ProductionTaskSetItemFactory.php database/factories/ProductionTaskFactory.php database/factories/ProductionHolidayFactory.php database/migrations/*_create_employees_table.php database/migrations/*_create_production_task_types_table.php database/migrations/*_create_production_task_sets_table.php database/migrations/*_create_production_task_set_items_table.php database/migrations/*_create_production_tasks_table.php database/migrations/*_create_production_holidays_table.php database/migrations/*_add_production_planning_defaults.php tests/Feature/ProductionTaskSchemaTest.php
git commit -m "feat: add production task setup"
```

Stage only the newly created/explicitly modified files; do not stage unrelated model changes.

---

## Task 5: Working-Day Calculation and Production Task Scheduling

**Files:**

- Create: `app/Services/Production/ProductionWorkingCalendar.php`
- Create: `app/Actions/Production/GenerateProductionTasks.php`
- Create: `app/Actions/Production/RescheduleProduction.php`
- Create: `app/Actions/Production/RescheduleProductionTask.php`
- Create: `app/Actions/Production/ResetProductionTaskDate.php`
- Create: `app/Actions/Production/CompleteProductionTask.php`
- Create: `app/Actions/Production/ReopenProductionTask.php`
- Modify: `app/Actions/Production/PlanProduction.php`
- Create: `tests/Feature/ProductionTaskSchedulingTest.php`

### Step 1: Write failing scheduling tests

Prove:

- the first task is always offset zero and exactly equals the chosen production date;
- an explicit production date on a weekend or holiday is preserved with no automatic shift;
- later automatic tasks landing on a configured non-working date move forward;
- recurring holidays match month/day in later years;
- changing production date moves the first task and all unfinished automatic later tasks;
- changing the first task date changes production date and recalculates later automatic tasks;
- the first task can never become independently custom-dated;
- changing a later task marks it custom and later production-date changes do not move it;
- reset reconnects a custom later task to its stored offset;
- completed tasks never move;
- template edits do not rewrite generated tasks;
- assignment accepts an active same-workspace employee and preserves a later deactivated assignment;
- date changes are rejected after the run reaches `InProduction`.

### Step 2: Implement the calendar and actions

`ProductionWorkingCalendar` is deterministic and accepts a workspace and date. It exposes whether a date is working and the next working date. Keep date-only arithmetic in the workspace timezone.

Generate task snapshots during scheduling. Rescheduling locks the production and tasks, applies the two-way first-task anchor, and recalculates only unfinished automatic later tasks.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionTaskSchedulingTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/GenerateProductionTasks.php app/Actions/Production/RescheduleProduction.php app/Actions/Production/RescheduleProductionTask.php app/Actions/Production/ResetProductionTaskDate.php app/Actions/Production/CompleteProductionTask.php app/Actions/Production/ReopenProductionTask.php app/Actions/Production/PlanProduction.php app/Services/Production/ProductionWorkingCalendar.php tests/Feature/ProductionTaskSchedulingTest.php
git commit -m "feat: schedule production tasks"
```

---

## Task 6: Production Setup Workspace

**Files:**

- Create: `app/Livewire/ProductionBench/Production/SettingsIndex.php`
- Create: `resources/views/production-bench/production/settings.blade.php`
- Create: `resources/views/livewire/production-bench/production/settings-index.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/en/production_bench.php`
- Modify: `lang/fr.json`
- Modify: `lang/de.json`
- Modify: `lang/es.json`
- Modify: `lang/it.json`
- Modify: `lang/nl.json`
- Create: `tests/Feature/ProductionBenchProductionSettingsTest.php`

### Step 1: Write failing Livewire tests

Test workspace-isolated creation/edit/deactivation for employees, task types, task sets and ordered items, recurring holidays, weekend behavior, product defaults, and batch presets. Test server-side validation, accessible labels/errors, and read-only controls.

### Step 2: Build the focused setup page

Keep reusable setup outside the routine production form. Use compact sections for:

- batch sizes by product;
- employees;
- task types and task sets;
- working calendar.

Avoid a generic administration console. Provide clear empty states and examples such as `12 kg oils / 100 units`.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionSettingsTest.php
npm run build
git add app/Livewire/ProductionBench/Production/SettingsIndex.php resources/views/production-bench/production/settings.blade.php resources/views/livewire/production-bench/production/settings-index.blade.php routes/web.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json tests/Feature/ProductionBenchProductionSettingsTest.php
git commit -m "feat: add production planning setup"
```

---

## Task 7: One-Page Production Creation

**Files:**

- Create: `app/Livewire/ProductionBench/Production/ProductionCreate.php`
- Create: `resources/views/production-bench/production/create.blade.php`
- Create: `resources/views/livewire/production-bench/production/production-create.blade.php`
- Create: `app/Services/Production/ProductionAvailabilityPreview.php`
- Modify: `routes/web.php`
- Modify: `resources/views/components/production-bench/navigation.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: locale JSON files
- Create: `tests/Feature/ProductionBenchProductionCreateTest.php`

### Step 1: Write failing customer-flow tests

Cover the routine path:

`Choose product → choose or enter batch size → choose date → schedule`

Test:

- only products with an accessible published version are selectable;
- selecting a product loads its default preset and task set when present;
- preset values remain editable;
- no preset is required;
- soap uses oil quantity and cosmetics use total formula quantity;
- expected units drive packaging;
- scaled ingredients, percentages, packaging, available, incoming, and shortages appear read-only;
- generated task dates are previewed;
- non-working production dates show a warning but remain allowed;
- the primary action creates one planned production without reservations;
- duplicate submission does not create a second production;
- read-only access disables mutation.

### Step 2: Implement the one-page Livewire form

Do not use a wizard. Keep advanced setup out of the form. Present one compact requirement preview under the few editable scheduling fields.

Use an opaque idempotency token generated when the form mounts and rotate it only after a successful post.

### Step 3: Verify responsive behavior and commit

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCreateTest.php
npm run build
git add app/Livewire/ProductionBench/Production/ProductionCreate.php app/Services/Production/ProductionAvailabilityPreview.php resources/views/production-bench/production/create.blade.php resources/views/livewire/production-bench/production/production-create.blade.php resources/views/components/production-bench/navigation.blade.php routes/web.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json tests/Feature/ProductionBenchProductionCreateTest.php
git commit -m "feat: add simple production planning flow"
```

---

## Task 8: Production List and Readable Detail

**Files:**

- Create: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Create: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Create: `resources/views/production-bench/production/index.blade.php`
- Create: `resources/views/production-bench/production/show.blade.php`
- Create: `resources/views/livewire/production-bench/production/production-index.blade.php`
- Create: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Create: `app/Actions/Production/CancelProduction.php`
- Modify: `routes/web.php`
- Modify: `lang/en/production_bench.php`
- Modify: locale JSON files
- Create: `tests/Feature/ProductionBenchProductionsTest.php`

### Step 1: Write failing list/detail tests

Cover search, date/status/product filters, stock-preparation state, pagination, workspace isolation, public IDs, task display, custom-date indicator, employee assignment, task completion/reopen, edit rules, and cancellation confirmation/reason.

### Step 2: Implement list and detail

List is the default Production view. Detail keeps requirements, tasks, stock state, and actions in a clear operational order. Do not expose internal enum names or turn the page into an ERP dashboard.

`CancelProduction` initially cancels draft/planned runs. Task 12 extends it to release reservations atomically.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php
npm run build
git add app/Livewire/ProductionBench/Production/ProductionIndex.php app/Livewire/ProductionBench/Production/ProductionDetail.php app/Actions/Production/CancelProduction.php resources/views/production-bench/production/index.blade.php resources/views/production-bench/production/show.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/livewire/production-bench/production/production-detail.blade.php routes/web.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: add production list and detail"
```

---

## Task 9: Planned Demand and Inventory Forecast

**Files:**

- Create: `app/Services/Production/ProductionDemandService.php`
- Modify: `app/Services/StockPositionService.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Create: `tests/Feature/ProductionForecastTest.php`

### Step 1: Write failing forecast tests

Prove separately for ingredients and packaging:

- draft runs do not affect forecast;
- planned requirements reduce subject forecast but do not reduce available;
- reserved requirements do not get subtracted twice;
- cancelled runs stop contributing demand;
- physical remains movement-derived only;
- incoming remains outstanding issued purchase-order quantity;
- display units convert without changing canonical arithmetic.

Use this identity in assertions:

`forecast = released physical + incoming - remaining active production demand`

Equivalent presentation is allowed:

`forecast = available + incoming - unreserved remaining demand`

### Step 2: Implement subject demand projection

Aggregate remaining requirements across `Scheduled` and `Reserved` productions. Keep one source of truth so inventory cards, production previews, reservations, and Flash use the same quantities.

Do not mutate stock lots, movements, or current prices.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionForecastTest.php tests/Feature/ProductionBenchInventoryTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/Production/ProductionDemandService.php app/Services/StockPositionService.php resources/views/livewire/production-bench/inventory-index.blade.php tests/Feature/ProductionForecastTest.php
git commit -m "feat: project planned production demand"
```

---

## Task 10: Lot Reservation Schema and FEFO Proposal

**Files:**

- Create: `app/StockReservationStatus.php`
- Create: `app/Models/StockReservation.php`
- Create: `database/factories/StockReservationFactory.php`
- Create: `database/migrations/*_create_stock_reservations_table.php`
- Create: `app/Policies/StockReservationPolicy.php`
- Create: `app/Services/Production/StockReservationProposalService.php`
- Modify: `app/Models/ProductionRequirement.php`
- Modify: `app/Models/StockLot.php`
- Create: `tests/Feature/StockReservationProposalTest.php`

### Step 1: Write failing proposal tests

Cover:

- one requirement splitting across the remainder of one lot and later lots;
- ingredient and packaging lots;
- released status only;
- `available_from` on/before production date;
- expiry absent or on/after production date;
- FEFO ordering by usable expiry, then stocked date, then internal lot code;
- null expiry after dated expiry;
- current movement balance minus other active reservations;
- no proposal mutation;
- a shortage response with required, proposed, and missing quantities;
- manual eligible lot override validation;
- cross-workspace lot rejection;
- bulk proposals ordered by production date and stable production ID.

### Step 2: Implement schema and proposal service

Each reservation joins one production requirement to one stock lot and stores canonical quantity, status, actor, timestamps, and an idempotency key/reference. Foreign keys protect record existence; the domain action locks both records and validates that the lot subject matches the requirement. All writes remain behind actions.

The proposal service returns value objects/arrays only. It must not create reservation rows.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/StockReservationProposalTest.php
vendor/bin/pint --dirty --format agent
git add app/StockReservationStatus.php app/Models/StockReservation.php app/Models/ProductionRequirement.php app/Models/StockLot.php app/Policies/StockReservationPolicy.php app/Services/Production/StockReservationProposalService.php database/factories/StockReservationFactory.php database/migrations/*_create_stock_reservations_table.php tests/Feature/StockReservationProposalTest.php
git commit -m "feat: propose split lot reservations"
```

---

## Task 11: Transactional Stock Preparation and Release

**Files:**

- Create: `app/Actions/Production/PrepareProductionStock.php`
- Create: `app/Actions/Production/ReleaseProductionStock.php`
- Modify: `app/Actions/Production/CancelProduction.php`
- Create: `tests/Feature/ProductionStockPreparationTest.php`

### Step 1: Write failing mutation tests

Prove:

- one fully covered planned production becomes `Reserved`;
- several selected productions reserve atomically only when all are fully covered;
- a shortage leaves every selected production unchanged;
- removing a short production from the request allows the remainder;
- reservation may split one visible requirement across several lots;
- packaging reservations are whole units;
- manual allocations must exactly cover requirements and use eligible matching lots;
- duplicate idempotency input returns the same result without duplicate reserved quantity;
- concurrent attempts cannot reserve the same availability;
- the action locks selected productions, requirements, lots, and competing active reservations in stable order;
- releasing returns a run to `Scheduled` without deleting history;
- cancellation requires a reason, releases active reservations, and marks the run cancelled atomically;
- physical stock never changes and no stock movement is posted;
- cross-workspace and read-only mutations fail.

### Step 2: Implement locked actions

Use retried database transactions. Recompute availability while locks are held; never trust quantities from the preview. Store released/cancelled reservation history instead of deleting it.

Only transition to `Reserved` when all remaining requirements are fully covered.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionStockPreparationTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/PrepareProductionStock.php app/Actions/Production/ReleaseProductionStock.php app/Actions/Production/CancelProduction.php tests/Feature/ProductionStockPreparationTest.php
git commit -m "feat: reserve production stock safely"
```

---

## Task 12: Individual and Bulk Prepare-Stock UI

**Files:**

- Create: `app/Livewire/ProductionBench/Production/StockPreparation.php`
- Create: `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: production list/detail Blade views
- Modify: `lang/en/production_bench.php`
- Modify: locale JSON files
- Create: `tests/Feature/ProductionBenchStockPreparationTest.php`

### Step 1: Write failing Livewire tests

Test one-run and multi-select flows:

1. select production(s);
2. open **Prepare stock**;
3. see each requirement, proposed lots, split quantities, and shortages;
4. change an eligible lot allocation;
5. remove a short production when desired;
6. confirm explicitly;
7. see status and available stock update.

Also test stale-preview conflicts, validation messaging, keyboard focus, disabled read-only controls, and tablet layout.

### Step 2: Implement preview-first UI

Keep one visible ingredient/packaging requirement row with nested lot allocations. Never duplicate the requirement merely because two lots are needed. Make shortage and conflict text understandable without inventory terminology training.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionBenchStockPreparationTest.php
npm run build
git add app/Livewire/ProductionBench/Production/StockPreparation.php app/Livewire/ProductionBench/Production/ProductionIndex.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/stock-preparation.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json tests/Feature/ProductionBenchStockPreparationTest.php
git commit -m "feat: add production stock preparation flow"
```

---

## Task 13: Production Calendar with Month, Week, and Agenda

**Files:**

- Modify: `package.json`
- Modify: `package-lock.json`
- Create: `resources/js/production-calendar.js`
- Modify: `resources/js/app.js`
- Create: `app/Livewire/ProductionBench/Production/ProductionCalendar.php`
- Create: `resources/views/production-bench/production/calendar.blade.php`
- Create: `resources/views/livewire/production-bench/production/production-calendar.blade.php`
- Modify: `routes/web.php`
- Modify: production navigation/list view
- Modify: `lang/en/production_bench.php`
- Modify: locale JSON files
- Create: `tests/Feature/ProductionBenchProductionCalendarTest.php`

### Step 1: Install the approved calendar dependency

```bash
npm install --save-dev @event-calendar/core
```

Do not install a Filament wrapper, jQuery calendar, or CDN asset.

### Step 2: Write failing server-side calendar tests

Cover workspace-scoped event payloads, date ranges, production/task/completed filters, public detail URLs, event type/style metadata, and no mutation endpoint for drag/drop.

### Step 3: Implement Livewire/JavaScript integration

Use `wire:ignore` around the calendar root. Initialize and destroy listeners safely across `wire:navigate`; avoid duplicate instances. Bundle with the existing Vite entry.

Views:

- `dayGridMonth` for Month;
- `dayGridWeek` for Week;
- list/agenda view for Agenda and narrow screens.

Production events are visually stronger than task events. Clicking opens the production detail. Filters are limited to Productions, Tasks, and Completed. Dates are changed only through explicit forms; no drag/drop.

### Step 4: Verify and commit

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php
npm run build
git add package.json package-lock.json resources/js/production-calendar.js resources/js/app.js app/Livewire/ProductionBench/Production/ProductionCalendar.php resources/views/production-bench/production/calendar.blade.php resources/views/livewire/production-bench/production/production-calendar.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/components/production-bench/navigation.blade.php routes/web.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json tests/Feature/ProductionBenchProductionCalendarTest.php
git commit -m "feat: add production calendar views"
```

---

## Task 14: Flash Production Simulator

**Files:**

- Create: `app/Services/Production/FlashProductionSimulator.php`
- Create: `app/Services/Production/FlashDateProposalService.php`
- Create: `app/Livewire/ProductionBench/Production/FlashPlanner.php`
- Create: `resources/views/production-bench/production/flash.blade.php`
- Create: `resources/views/livewire/production-bench/production/flash-planner.blade.php`
- Modify: `routes/web.php`
- Modify: production navigation/index
- Modify: `lang/en/production_bench.php`
- Modify: locale JSON files
- Create: `tests/Feature/FlashProductionSimulatorTest.php`
- Create: `tests/Feature/ProductionBenchFlashPlannerTest.php`

### Step 1: Write failing simulator tests

For several product lines, prove calculation of:

- desired, expected, and extra units;
- whole batches required;
- preset and manual per-batch configuration;
- total oil/formula basis;
- aggregated ingredient and packaging requirements;
- available, incoming, forecast, and shortage quantities;
- indicative current material value in workspace currency;
- missing-price warnings without failure;
- optional total task duration;
- no database mutation.

### Step 2: Implement the pure simulation services

Reuse `ProductionRequirementBuilder`, `ProductionDemandService`, `StockPositionService`, `CurrentMaterialPriceService`, and `ProductionWorkingCalendar`. Current prices are indicative only; never derive Flash value from mutable listing price or alter costing projections.

`FlashDateProposalService` accepts first date and temporary whole batches per working day. It proposes editable dated rows without persisting capacity, waves, or production lines.

### Step 3: Build the prominent Flash interface

The page first shows the live simulation. **Generate productions** opens a second reviewed date preview. Make whole batches and extra units explicit, and keep missing price separate from stock shortage.

### Step 4: Verify and commit

```bash
php artisan test --compact tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionBenchFlashPlannerTest.php
npm run build
git add app/Services/Production/FlashProductionSimulator.php app/Services/Production/FlashDateProposalService.php app/Livewire/ProductionBench/Production/FlashPlanner.php resources/views/production-bench/production/flash.blade.php resources/views/livewire/production-bench/production/flash-planner.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/components/production-bench/navigation.blade.php routes/web.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionBenchFlashPlannerTest.php
git commit -m "feat: add flash production simulator"
```

---

## Task 15: Confirmed Flash Production Generation

**Files:**

- Create: `app/Actions/Production/GenerateProductionsFromFlash.php`
- Modify: `app/Livewire/ProductionBench/Production/FlashPlanner.php`
- Modify: `resources/views/livewire/production-bench/production/flash-planner.blade.php`
- Create: `tests/Feature/FlashProductionGenerationTest.php`

### Step 1: Write failing generation tests

Prove:

- simulation alone remains non-persistent;
- confirmation uses the reviewed, individually editable production dates;
- temporary batches-per-day affects proposals only;
- weekends/holidays are skipped for automatic proposals but explicitly edited dates are respected;
- one independent `Scheduled` production is created per whole batch;
- every production receives the correct pinned version, requirements, and tasks;
- the first task equals that production's date;
- generation creates no reservation, wave, line, or capacity record;
- a duplicate confirmation returns the same productions;
- any invalid row rolls back all generated productions;
- cross-workspace and read-only requests fail.

### Step 2: Implement atomic generation

Use one generation token plus stable row sequence to derive per-production idempotency keys. Call the same domain planning path as the single-production form rather than duplicating formula scaling.

### Step 3: Verify and commit

```bash
php artisan test --compact tests/Feature/FlashProductionGenerationTest.php tests/Feature/ProductionPlanningTest.php
vendor/bin/pint --dirty --format agent
npm run build
git add app/Actions/Production/GenerateProductionsFromFlash.php app/Livewire/ProductionBench/Production/FlashPlanner.php resources/views/livewire/production-bench/production/flash-planner.blade.php tests/Feature/FlashProductionGenerationTest.php
git commit -m "feat: generate planned productions from flash"
```

---

## Task 16: Checkpoint Integration, Accessibility, and Roadmap Alignment

**Files:**

- Create: `tests/Feature/ProductionBenchPlanningCheckpointTest.php`
- Modify: `tests/Feature/ProductionBenchLayoutTest.php`
- Modify: `tests/Feature/ProductionBenchPagesTest.php`
- Modify: `lang/en/production_bench.php`
- Modify: locale JSON files
- Modify: `docs/superpowers/plans/2026-07-28-production-bench-delivery-roadmap.md`

### Step 1: Write the checkpoint review scenario

The integrated test and manual review fixture demonstrate:

1. a product with a published ingredient formula and packaging;
2. an optional `12 kg / 100 units` preset;
3. a task set whose first task is production day and whose later tasks avoid non-working dates;
4. planning one production without reserving stock;
5. planned demand changing Forecast but not Available;
6. a second production generated through Flash;
7. list, Month, Week, and Agenda views;
8. bulk stock preparation;
9. one ingredient requirement split across the remainder of one lot and a second lot;
10. Available reducing while Physical remains unchanged;
11. cancellation releasing the reservations;
12. customer-facing labels and usable tablet/narrow layouts.

### Step 2: Finish translations and accessibility

Remove untranslated keys. Verify labels, error associations, keyboard order, focus after modal actions, non-colour status cues, table/card responsiveness, and Agenda as the narrow-screen calendar default.

### Step 3: Align the roadmap

Update Checkpoint 3 to include presets, employees, tasks, working calendar, calendar views, Flash simulation/generation, and reservations. Remove Flash from Checkpoint 5 while leaving traceability and lifecycle completion there. Keep Checkpoint 4 execution unchanged.

### Step 4: Run full checkpoint verification

```bash
php artisan test --compact tests/Feature/ProductionPlanningSchemaTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionBatchPresetTest.php tests/Feature/ProductionTaskSchemaTest.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionForecastTest.php tests/Feature/StockReservationProposalTest.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/FlashProductionGenerationTest.php tests/Feature/ProductionBenchPlanningCheckpointTest.php
php artisan test --compact tests/Feature/ProductionBenchLayoutTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryTest.php tests/Feature/GoodsReceiptPostingTest.php
vendor/bin/pint --dirty --format agent
npm run build
graphify update .
git status --short
```

Expected: all focused and regression tests pass, the production build succeeds, the graph is refreshed, and only intended Checkpoint 3 files are staged for the final commit.

### Step 5: Commit the checkpoint finish

```bash
git add tests/Feature/ProductionBenchPlanningCheckpointTest.php tests/Feature/ProductionBenchLayoutTest.php tests/Feature/ProductionBenchPagesTest.php lang/en/production_bench.php lang/fr.json lang/de.json lang/es.json lang/it.json lang/nl.json docs/superpowers/plans/2026-07-28-production-bench-delivery-roadmap.md graphify-out/GRAPH_REPORT.md graphify-out/graph.html graphify-out/graph.json graphify-out/manifest.json graphify-out/cost.json
git commit -m "test: complete production planning checkpoint"
```

---

## Manual Review Gate

Stop after Task 16 and let the user validate the complete planning experience before writing or executing the Checkpoint 4 plan.

The review should answer five plain-language questions:

1. Can a maker plan a familiar product in under one minute?
2. Are task dates useful without feeling automatic or uncontrollable?
3. Can list and calendar views explain what is happening next?
4. Does Prepare stock make split-lot allocation understandable before committing it?
5. Does Flash feel impressive and useful while remaining clearly non-persistent until confirmation?

Do not start production, consume stock, create finished lots, or calculate actual batch cost during this review.

## Completion Criteria

- One production can be planned from a published product with or without a preset.
- First production task and production date remain synchronized before start.
- Later automatic tasks observe configured working days; custom/completed tasks remain stable.
- Planned production demand changes forecast but not availability.
- Explicit reservations reduce available but not physical stock.
- Split-lot and bulk reservation paths are safe under concurrency.
- Production list, Month, Week, and Agenda views are usable on desktop and tablet.
- Flash simulates several products and explicitly generates independent planned productions.
- All mutations enforce workspace, entitlement/read-only, idempotency, and lifecycle rules.
- No Checkpoint 4 inventory movement or production-cost behavior has leaked into Checkpoint 3.
