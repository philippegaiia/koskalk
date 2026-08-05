# Production Run Batch Numbering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every professional Production Run an immutable planning reference immediately, support configurable immutable permanent batch numbers assigned individually or in bulk, and expose both identities in Production Bench surfaces before calendar drag-and-drop is implemented.

**Architecture:** Add a workspace-scoped `ProductionRunNumberSetting` row containing both sequence counters and the permanent-number format. A dedicated `ProductionRunNumberService` owns formatting, locking, planning-reference allocation, and permanent assignment; Livewire actions call it after validating workspace access and lifecycle state. `CreateProductionDraft` allocates the planning reference inside its existing transaction, Flash inherits that behavior, and the future Start Production action will reuse the permanent allocator. Basic `production_batches` remains unrelated.

**Tech Stack:** Laravel 13, Livewire 4, Eloquent, PostgreSQL/SQLite migrations and triggers, Pest 4, Blade, existing Production Bench navigation and toast components.

---

## Scope and implementation rules

- Work directly on `main`; do not create a worktree or branch.
- Preserve all unrelated dirty-worktree changes. Stage only files belonging to this feature.
- Follow `docs/superpowers/specs/2026-08-05-production-run-batch-numbering-design.md`.
- Do not modify Basic `ProductionBatch`, its controller, or its retention rules.
- Do not implement calendar drag-and-drop, Production Sheet printing, Start Production, reservations, or inventory execution in this plan.
- Do not assign permanent numbers during Flash generation.
- Use dependency injection; do not add new `app(...)` calls in domain actions or Livewire methods.
- Format every changed PHP file with `vendor/bin/pint --dirty --format agent`.
- Run `graphify update .` after implementation changes, not while preparing this plan.

## File map

Create:

- `app/Models/ProductionRunNumberSetting.php`
- `app/Services/Production/ProductionRunNumberService.php`
- `app/Actions/Production/SaveProductionRunNumberSettings.php`
- `app/Actions/Production/AssignProductionBatchNumbers.php`
- `app/Livewire/ProductionBench/Production/NumberingSettings.php`
- `resources/views/livewire/production-bench/production/numbering-settings.blade.php`
- `resources/views/production-bench/production/numbering.blade.php`
- `database/migrations/2026_08_05_120000_create_production_run_number_settings_table.php`
- `database/migrations/2026_08_05_120001_add_batch_numbers_to_production_runs_table.php`
- `database/migrations/2026_08_05_120002_backfill_production_run_planning_references.php`
- `database/migrations/2026_08_05_120003_enforce_production_run_batch_number_integrity.php`.
- `tests/Feature/ProductionRunBatchNumberingTest.php`
- `tests/Feature/ProductionRunNumberSettingsPageTest.php`

Modify:

- `app/Models/ProductionRun.php`, `app/Models/Workspace.php`, and `database/factories/ProductionRunFactory.php`.
- `app/Actions/Production/CreateProductionDraft.php`.
- `app/Services/ProductionBenchAccess.php`.
- `app/Livewire/ProductionBench/Production/ProductionIndex.php`, `app/Livewire/ProductionBench/Production/ProductionDetail.php`, and `app/Livewire/ProductionBench/Production/ProductionCalendar.php`.
- `resources/views/livewire/production-bench/production/production-index.blade.php`, `resources/views/livewire/production-bench/production/production-detail.blade.php`, and `resources/views/livewire/production-bench/production/production-calendar.blade.php`.
- `routes/web.php` and `resources/views/components/production-bench/production-settings-navigation.blade.php`.
- `lang/en/production_bench.php` and `database/seeders/data/interface-translations.json`.
- `tests/Feature/ProductionBenchProductionCreateTest.php`, `tests/Feature/GenerateFlashProductionsTest.php`, `tests/Feature/FlashProductionSimulatorTest.php`, `tests/Feature/ProductionBenchFlashPlannerTest.php`, `tests/Feature/ProductionBenchProductionsTest.php`, and `tests/Feature/ProductionBenchProductionCalendarTest.php`.
- `docs/superpowers/specs/2026-08-04-production-planning-execution-design.md` and `docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md` for the numbering prerequisite and independent Basic boundary.

---

## Task 1: Persist Production Run identities and workspace numbering settings

**Files:** `database/migrations/2026_08_05_120000_create_production_run_number_settings_table.php`, `database/migrations/2026_08_05_120001_add_batch_numbers_to_production_runs_table.php`, `database/migrations/2026_08_05_120002_backfill_production_run_planning_references.php`, `database/migrations/2026_08_05_120003_enforce_production_run_batch_number_integrity.php`, `app/Models/ProductionRunNumberSetting.php`, `app/Models/ProductionRun.php`, `app/Models/Workspace.php`, `database/factories/ProductionRunFactory.php`, and `tests/Feature/ProductionRunBatchNumberingTest.php`.

- [ ] **Step 1: Write failing schema/model tests**

Add tests proving that a workspace has one settings row, defaults are `B-`, padding `5`, and every created run has a workspace-unique planning reference while its permanent number is nullable. Add a duplicate planning-reference test scoped to one workspace and a cross-workspace duplicate test proving the same rendered value may exist in another workspace.

Run:

```bash
php artisan test --compact tests/Feature/ProductionRunBatchNumberingTest.php
```

Expected: failures because the table, fields, relation, and factory values do not exist.

- [ ] **Step 2: Create the settings table**

Generate the migration with Artisan. Define:

```php
Schema::create('production_run_number_settings', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('next_planning_serial')->default(1);
    $table->string('permanent_prefix', 24)->default('B-');
    $table->string('permanent_suffix', 24)->default('');
    $table->unsignedSmallInteger('permanent_padding')->default(5);
    $table->unsignedBigInteger('next_permanent_serial')->default(1);
    $table->timestamps();
});
```

- [ ] **Step 3: Add run columns and indexes**

Add nullable `planning_batch_number`, nullable `batch_number`, nullable `batch_number_serial`, nullable `batch_number_assigned_at`, and nullable `batch_number_assigned_by_user_id` in `database/migrations/2026_08_05_120001_add_batch_numbers_to_production_runs_table.php`. Add workspace-scoped unique indexes for planning and permanent numbers, plus a workspace/batch-number lookup index. Keep planning nullable until the backfill completes; the backfill migration then makes it non-null after every existing run has a value.

- [ ] **Step 4: Add models, relations, casts, and factory values**

Add `ProductionRun::batchNumberAssignedBy()`, `Workspace::productionRunNumberSetting()`, fillable fields, and casts. Add `ProductionRun::displayIdentifier()` returning permanent batch number first and planning reference otherwise. The factory may accept explicit numbers for tests and should generate a unique five-digit planning reference when none is supplied; production creation remains the authoritative allocator.

- [ ] **Step 5: Backfill planning references**

The backfill in `database/migrations/2026_08_05_120002_backfill_production_run_planning_references.php` must create settings rows for workspaces with runs, process runs in workspace/id order, assign `T` plus minimum five-digit padding, advance `next_planning_serial`, leave permanent numbers null, and fail loudly on duplicates. The same migration makes planning references non-null only after the backfill succeeds. Do not infer permanent numbers.

- [ ] **Step 6: Enforce immutability in the database**

The migration in `database/migrations/2026_08_05_120003_enforce_production_run_batch_number_integrity.php` must add PostgreSQL and SQLite triggers proving:

- planning reference cannot change after insert;
- permanent number may change from null to a value once;
- a non-null permanent number cannot be changed or cleared;
- assignment metadata cannot be rewritten after issuance.

Test both model updates and `ProductionRun::query()->update()`; model events alone are insufficient.

- [ ] **Step 7: Run, format, and commit**

```bash
php artisan test --compact tests/Feature/ProductionRunBatchNumberingTest.php
vendor/bin/pint --dirty --format agent
git add app/Models/ProductionRun.php app/Models/Workspace.php app/Models/ProductionRunNumberSetting.php database/factories/ProductionRunFactory.php database/migrations tests/Feature/ProductionRunBatchNumberingTest.php
git commit -m "feat: add production run batch number storage"
```

---

## Task 2: Implement validation, formatting, and locked allocation

**Files:** `ProductionRunNumberService.php`, `SaveProductionRunNumberSettings.php`, `AssignProductionBatchNumbers.php`, `ProductionBenchAccess.php`, and `tests/Feature/ProductionRunBatchNumberingTest.php`.

- [ ] **Step 1: Write failing formatter/settings tests**

Cover prefix, suffix, padding, live example, minimum-width behavior, safe-character validation, length limits, positive serials, and rendered-number collision errors. The pure formatter must produce `SOAP-00042-FR` for prefix `SOAP-`, serial `42`, padding `5`, suffix `-FR`, and must produce `B-100000` for serial `100000` with five-digit padding.

- [ ] **Step 2: Implement the formatter and settings action**

`ProductionRunNumberService::formatPermanentNumber()` returns prefix + left-padded serial + suffix and never truncates. `SaveProductionRunNumberSettings` must call writable access, then require Owner/Admin role, lock workspace and settings, validate safe characters and bounds, reject an already-issued candidate, update future settings only, and return refreshed settings. Add a shared `assertCanConfigure()` access method so UI and action use the same permission rule.

- [ ] **Step 3: Write failing assignment tests**

Cover chronological assignment, already-numbered idempotent skips, empty selections, Draft rejection, missing/cross-workspace IDs, role/entitlement rejection, cancellation retention, and candidate collisions. Use a test that selects runs in reverse order but expects assignment by `planned_for`, then `id`.

- [ ] **Step 4: Implement the locked assignment action**

`AssignProductionBatchNumbers::handle(User $actor, Workspace $workspace, array $productionIds): array` must normalize IDs, require a non-empty selection, assert writable access, lock workspace/settings/runs, reject missing or cross-workspace IDs, skip already-numbered runs, require Scheduled or Reserved plus a planned date, sort eligible runs by date/id, render every candidate before updates, reject the entire transaction on any collision, update number/serial/time/user, advance the counter once, and return `assigned` plus `alreadyAssigned` counts.

Acquire run locks by ascending primary key to avoid deadlocks. Do not assign Draft. Leave a narrow future allocator entry point for Start Production.

- [ ] **Step 5: Prove rollback and concurrency**

Test duplicate submission, collision rollback, workspace isolation, Viewer/Editor/Admin permissions, inactive/cancelled entitlement, and a concurrent allocation using separate transactions/connections where supported. No failed transaction may advance a counter or partially assign a selection.

- [ ] **Step 6: Run, format, and commit**

```bash
php artisan test --compact tests/Feature/ProductionRunBatchNumberingTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/AssignProductionBatchNumbers.php app/Actions/Production/SaveProductionRunNumberSettings.php app/Services/Production/ProductionRunNumberService.php app/Services/ProductionBenchAccess.php tests/Feature/ProductionRunBatchNumberingTest.php
git commit -m "feat: allocate immutable production run batch numbers"
```

---

## Task 3: Allocate planning references in direct and Flash creation

**Files:** `app/Actions/Production/CreateProductionDraft.php`, `tests/Feature/ProductionBenchProductionCreateTest.php`, `tests/Feature/GenerateFlashProductionsTest.php`, `tests/Feature/FlashProductionSimulatorTest.php`, `tests/Feature/ProductionBenchFlashPlannerTest.php`, and `tests/Feature/ProductionRunBatchNumberingTest.php`.

- [ ] **Step 1: Write failing creation tests**

Prove a direct run receives `T00001`, an idempotent retry returns the same run and leaves the next serial at `2`, a failed creation rolls back the counter, and Flash creates distinct planning references while repeated Flash submission remains idempotent.

- [ ] **Step 2: Integrate allocation after idempotency lookup**

Inject `ProductionRunNumberService` into `CreateProductionDraft`. Inside its existing transaction, after the idempotency lookup returns no existing run and after recipe/task/date validation, allocate a planning reference from the locked workspace and include it in the create payload. The required order is: lock workspace, check idempotency, validate/build requirements, allocate reference, create run and requirements.

Flash inherits this behavior through `PlanProduction`; do not add a second Flash-specific allocator.

- [ ] **Step 3: Run focused tests, format, and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/ProductionRunBatchNumberingTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Production/CreateProductionDraft.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/ProductionRunBatchNumberingTest.php
git commit -m "feat: assign planning references to production runs"
```

---

## Task 4: Add Production setup → Numbering

**Files:** `NumberingSettings.php`, its Blade view and wrapper, `routes/web.php`, production-settings navigation, translations, and `ProductionRunNumberSettingsPageTest.php`.

- [ ] **Step 1: Write failing page/permission tests**

Test route rendering, persistent submenu visibility on the settings page, Owner/Admin save access, Editor read-only access, Viewer denial, inactive/cancelled states, live example output, and field-level validation messages.

- [ ] **Step 2: Add route, wrapper, and navigation**

Add named route `production-bench.production.settings.numbering` at `/production/settings/numbering`, a route wrapper view, and a Numbering link to the persistent production-settings submenu. Keep existing active styling and form-page navigation behavior.

- [ ] **Step 3: Implement the focused Livewire page**

Load the workspace's settings row on mount, creating the default row inside a workspace-locked transaction when this is the first visit, expose prefix/next number/digit/suffix fields, compute the rendered example, call `SaveProductionRunNumberSettings`, show the existing app-notification toast, and reload after success. Do not enlarge `SettingsIndex`.

The view must provide accessible labels and described-by errors, a short “future assignments only” explanation, clear read-only states, and responsive full-width layout. Editors can see values but cannot edit them; only Owner/Admin sees enabled save controls.

- [ ] **Step 4: Add translations and verify**

Add English labels/errors/success/confirmation keys and seeded German, Spanish, French, Italian, and Dutch values. Validate the JSON and run the page tests.

```bash
php -r 'json_decode(file_get_contents("database/seeders/data/interface-translations.json")); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);'
php artisan test --compact tests/Feature/ProductionRunNumberSettingsPageTest.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/ProductionBench/Production/NumberingSettings.php resources/views/livewire/production-bench/production/numbering-settings.blade.php resources/views/production-bench/production/numbering.blade.php routes/web.php resources/views/components/production-bench/production-settings-navigation.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionRunNumberSettingsPageTest.php
git commit -m "feat: add production run numbering settings"
```

---

## Task 5: Add individual and bulk assignment to Production views

**Files:** `ProductionIndex.php`, production index Blade, `ProductionDetail.php`, production detail Blade, translations, and `ProductionBenchProductionsTest.php`.

- [ ] **Step 1: Write failing detail tests**

Test the planning reference before assignment, the individual action only for Scheduled/Reserved active runs, successful assignment metadata and toast, idempotent repeat, and hidden/disabled mutation controls for viewers/read-only workspaces.

- [ ] **Step 2: Write failing list tests**

Test date-ordered bulk assignment, already-numbered skips, empty selection validation, stale ineligible selection rollback, search by planning/permanent identifier, and reuse of the existing selected IDs for Prepare stock.

- [ ] **Step 3: Implement individual assignment**

Inject `AssignProductionBatchNumbers` into a new detail method, pass the current workspace and one production ID, surface validation errors with the existing detail pattern, show the app toast on success, and dispatch a refresh event. Render confirmation-protected controls only for an unnumbered Scheduled/Reserved run in a writable active workspace. Keep server authorization independent of browser visibility.

- [ ] **Step 4: Implement bulk assignment**

Add `assignSelectedBatchNumbers()` to the list. Normalize positive IDs, reject empty selections, call the action, clear successfully processed IDs, and show assigned/already-assigned counts. Keep the existing checkboxes and add an irreversible-action confirmation next to Prepare stock; do not create another selection model.

Extend list search to include `planning_batch_number` and `batch_number` in addition to public ID and product name.

- [ ] **Step 5: Update presentation**

Use one display-identity helper. Show permanent number first, planning reference as a labelled fallback/secondary value, and assignment metadata on detail. Avoid raw nine-decimal output. Stack identity and controls on narrow screens without horizontal overflow.

- [ ] **Step 6: Run, format, and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionRunBatchNumberingTest.php
vendor/bin/pint --dirty --format agent
git add app/Livewire/ProductionBench/Production/ProductionIndex.php resources/views/livewire/production-bench/production/production-index.blade.php app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchProductionsTest.php
git commit -m "feat: assign production run batch numbers from production views"
```

---

## Task 6: Show batch identities in the calendar

**Files:** `app/Livewire/ProductionBench/Production/ProductionCalendar.php`, `tests/Feature/ProductionBenchProductionCalendarTest.php`, `tests/Feature/ProductionBenchProductionCreateTest.php`, `tests/Feature/GenerateFlashProductionsTest.php`, `tests/Feature/FlashProductionSimulatorTest.php`, and the two authoritative planning documents where wording must be corrected.

- [ ] **Step 1: Write failing calendar identity tests**

Assert a production event title is `T00001 · Olive soap` before assignment and `B-00001-FR · Olive soap` afterward. Assert task titles and task-to-production URLs remain unchanged.

- [ ] **Step 2: Implement the shared identity display**

Use `ProductionRun::displayIdentifier()` in list, detail, and calendar. Change only the production event title to:

```php
trim($production->displayIdentifier().' · '.($production->recipe?->name ?? __('production_bench.production.unknown_product')))
```

Keep event IDs, URL payloads, filters, date ranges, and the explicit exclusive end date unchanged. Calendar drag-and-drop remains a later plan.

- [ ] **Step 3: Correct documentation contracts**

Add the numbering prerequisite to the production planning design and remove any later wording that says Checkpoint 4 creates a linked Basic `ProductionBatch`. Keep the independent Basic/Production Bench boundary authoritative.

- [ ] **Step 4: Run focused tests and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/FlashProductionSimulatorTest.php
git add app/Livewire/ProductionBench/Production/ProductionCalendar.php tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/FlashProductionSimulatorTest.php docs/superpowers/specs/2026-08-04-production-planning-execution-design.md docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md
git commit -m "feat: show production run batch identities in calendar"
```

---

## Task 7: Full verification and handoff

- [ ] **Step 1: Run the focused production suite**

```bash
php artisan test --compact tests/Feature/ProductionRunBatchNumberingTest.php tests/Feature/ProductionRunNumberSettingsPageTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/GenerateFlashProductionsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionTaskSchedulingTest.php
```

Expected: all focused tests pass.

- [ ] **Step 2: Build and manually verify**

```bash
npm run build
```

Verify direct and Flash references, settings permissions, live preview, chronological bulk assignment, idempotent retry, cancellation retention, list/detail/search/calendar labels, responsive layout, focus/error/confirmation/toast behavior, and no Basic snapshot linkage.

- [ ] **Step 3: Format, graph, and inspect the worktree**

```bash
vendor/bin/pint --dirty --format agent
graphify update .
git status --short
```

Only intended numbering files may be staged. Do not stage unrelated user changes.

- [ ] **Step 4: Commit only feature verification fixes**

If verification exposes a numbering defect, fix it with a focused test, format the changed PHP files, and commit only the verified numbering files with message `test: verify production run batch numbering`. Do not stage unrelated user changes.

---

## Future Start Production handoff

The future Start Production transaction must reuse the same allocator. If `batch_number` is null, it assigns while the production, reservations, and lots are locked; if already assigned, it preserves the existing value. The Checkpoint 4 plan must add this handoff test and must not create a second numbering implementation.

## Completion criteria

- Every successful Production Run has an immutable planning reference immediately after creation.
- Direct and Flash idempotency never consumes duplicate planning references.
- Permanent numbers are configurable per workspace and safe under concurrency.
- Owner/Admin controls the format; Editors assign; Viewers cannot mutate.
- Individual and chronological bulk assignment work for Scheduled and Reserved runs.
- Issued numbers survive reschedule, cancellation, and abortion.
- Basic `production_batches` remains independent.
- Production list, detail, search, and calendar display the correct identity.
- No calendar drag-and-drop or execution behavior is implemented here.
