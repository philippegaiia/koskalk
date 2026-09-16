# Production Locations and Flash Scheduling Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Follow the shared foundation in [optional locations](2026-09-16-optional-locations.md) first. Steps use checkbox syntax for tracking.

**Goal:** Suggest realistic production dates using the existing overall daily limit, existing scheduled work, and optional production-location limits, without scheduling tasks by capacity.

**Architecture:** Extend FlashDateProposalService with workspace-scoped daily occupancy and nullable location assignments. Keep simulation quantities and material requirements independent of scheduling. Revalidate accepted previews inside the existing generation transaction and preserve idempotent retries.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL, Pest 4.

---

## Scheduling contract

- The existing `batchesPerDay` control becomes the overall **Maximum productions per day**. Initialize it from `Workspace.production_daily_limit` (default 1). Persist a changed value after successful Flash creation, never during rendering or failed previews. Settings can also update it.
- Count one ProductionRun per planned production date. A batch number, task, finished unit, or stock-lot count is not a capacity unit.
- Count dated **Scheduled, Reserved, InProduction, Completed, and Aborted** runs. Completed/aborted work used a production slot; it must not free the day for another Flash run. Exclude Cancelled, undated records, and Draft (not yet committed to the calendar). This is a planned-date limit, not inferred actual-start scheduling.
- All qualifying runs count toward the overall limit, including unassigned runs, runs created manually, and assignments to archived locations.
- When production locations are enabled, a proposed assigned run also needs space under that location's limit. Unassigned new runs use only the overall limit. When disabled, ignore all location limits and create new runs unassigned.
- Use existing working days and holidays. No hourly scheduling, task-load calculation, or automatic location substitution.
- Each proposed batch searches from the selected first date for its earliest available working day. Preserve input row/batch priority, but allow a later row assigned to another location to use an earlier free day. Do not carry a cursor blocked on one location across all rows.
- Increment in-memory occupancy immediately after each proposed batch. Never move already scheduled productions.
- Proposed task and ready dates derive from the chosen production date through the existing services. Busy finishing days never move production.
- Put a finite search boundary of five calendar years after firstDate on automatic proposals. Report a localized "No available production date in the next five years. Reduce the quantity or change the daily limit." error and create nothing when exceeded. Retain the existing 1000-batch submission cap.
- Manual creation, reassignment, and date changes may exceed the daily limit. Show a non-blocking warning with the resulting count; do not add an override approval dialog or shift the user's chosen date. Existing working-day/status restrictions still apply.

## Task P1: Account for existing work before adding locations

**Create:**
- `app/Services/Production/ProductionDailyOccupancy.php`
- `tests/Feature/ProductionDailyOccupancyTest.php`

**Modify:**
- `app/Services/Production/FlashDateProposalService.php`
- `app/Livewire/ProductionBench/Production/FlashPlanner.php`
- `tests/Feature/FlashProductionSimulatorTest.php`
- `tests/Feature/ProductionBenchFlashPlannerTest.php`
- `lang/en/production_bench.php`

- [ ] Add the following behavioral test to the new file, then add status and foreign-workspace datasets. Use per-file RefreshDatabase, not a global test reset.

```php
<?php

use App\Enums\ProductionRunStatus;
use App\Models\ProductionRun;
use App\Models\Workspace;
use App\Services\Production\ProductionDailyOccupancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('counts scheduled production runs on their planned day', function (): void {
    $workspace = Workspace::factory()->create();
    ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Scheduled,
        'planned_for' => '2026-09-21',
    ]);
    ProductionRun::factory()->for($workspace)->create([
        'status' => ProductionRunStatus::Cancelled,
        'planned_for' => '2026-09-21',
    ]);

    $occupancy = app(ProductionDailyOccupancy::class)->between(
        $workspace, '2026-09-21', '2026-09-22',
    );

    expect($occupancy['overall']['2026-09-21'])->toBe(1);
    expect($occupancy['overall']['2026-09-22'] ?? 0)->toBe(0);
});
```

- [ ] Run `php artisan test --compact tests/Feature/ProductionDailyOccupancyTest.php` and confirm the missing-service failure.
- [ ] Implement `between(Workspace $workspace, string $from, string $through): array` with return shape `array{overall: array<string, int>, locations: array<int, array<string, int>>}`. Query production_runs scoped by workspace/date/status. Initially locations is empty; P3 adds grouping. Use aggregate queries, not one query per proposed day. Fresh results per call; no service-instance cache of mutable occupancy.
- [ ] Replace the proposal's `$dayBatchCount` with the returned daily map. For each batch, search working dates from firstDate, compare existing plus proposed count to batchesPerDay, then increment the map. Leave material simulation, task offsets, and ready-delay calculations unchanged.
- [ ] Cover: limit 2 with one existing Monday run produces Monday/Tuesday for two new runs; a second Flash proposal sees the first; cancelled records free space; completed/aborted records retain space; workspace isolation; weekends and recurring holidays; 1000-batch guard; exhausted search horizon. Extend existing fixtures rather than recreating formula setup.
- [ ] Initialize FlashPlanner.batchesPerDay from the saved workspace limit. Add updatedFirstDate and updatedBatchesPerDay hooks that invalidate the date preview, including after malformed input; changing lines must keep invalidating simulation and preview.
- [ ] Run the three named test files, expect PASS, format and commit.

## Task P2: Production-location records and product defaults

**Create:**
- `database/migrations/2026_09_16_101000_create_production_locations_and_assignments.php`
- `app/Models/ProductionLocation.php`
- `database/factories/ProductionLocationFactory.php`
- `app/Policies/ProductionLocationPolicy.php`
- `app/Actions/Production/SaveProductionLocation.php`
- `app/Actions/Production/SaveProductProductionLocation.php`
- `app/Services/Production/ProductionLocationSelection.php`
- `app/Livewire/ProductionBench/Production/ProductionLocations.php`
- `resources/views/livewire/production-bench/production/production-locations.blade.php`
- `tests/Feature/ProductionLocationSettingsTest.php`

**Modify:**
- `app/Models/Recipe.php`
- `app/Models/ProductionRun.php`
- `app/Models/Workspace.php`
- `resources/views/livewire/production-bench/production/planning-preferences.blade.php`
- `lang/en/production_bench.php`

- [ ] Write failing tests for create/edit/archive/reactivate, whitespace/case duplicate names within one workspace, identical names across workspaces, positive integer limits, unauthorized changes, and independence from the storage flag.
- [ ] Run `php artisan test --compact tests/Feature/ProductionLocationSettingsTest.php`.
- [ ] Create the production_locations table with id, unique public_id UUID, workspace FK cascadeOnDelete, name/normalized_name (120), daily_production_limit (positive integer, default 1), is_active (default true), timestamps, and unique(workspace_id, normalized_name). Add nullable `production_location_id` to production_runs and `default_production_location_id` to recipes, both indexed with nullOnDelete. Add `(workspace_id, planned_for, status)` and `(workspace_id, production_location_id, planned_for)` indexes if an equivalent index is not present in the inspected schema.
- [ ] Make down() remove FKs/indexes/columns before dropping the table. Models follow Fillable attributes, HasPublicId, casts(), and explicit workspace relations. No seed data and no automatic assignment.
- [ ] Implement SaveProductionLocation with contract `handle(User $actor, Workspace $workspace, string $name, int|string $dailyProductionLimit, bool $isActive = true, ?ProductionLocation $location = null): ProductionLocation`. Follow SaveDepartment's normalization and duplicate handling. Validate limits 1..1000 and use workspace-first transactions with reasserted access. Invoke the auto-discovered policy; no hard-delete UI.
- [ ] Implement SaveProductProductionLocation with contract `handle(User $actor, Workspace $workspace, Recipe $recipe, ?int $locationId): Recipe`. Require the enabled flag, writable workspace and update permission for the product; require same-workspace active location or null. Clearing a default does not change production runs.
- [ ] Implement ProductionLocationSelection for workspace-scoped active option queries, nullable selected-id validation, and enabled-only product default resolution. Explicit null means no assignment. A missing/inactive default resolves to null. Do not fallback silently from an explicitly invalid selection.
- [ ] Nest ProductionLocations only when the persisted production flag is true. Use Filament actions/forms for name, daily limit, archive/reactivate. Below the list, provide a searchable product selector and optional default-location selector; saving that pair invokes SaveProductProductionLocation. This keeps defaults in Production Bench and avoids changing the formula workbench.
- [ ] Archiving removes a location from new choices without clearing runs or product defaults. Existing assigned runs still show the archived name and count overall. Preserve an unchanged archived assignment when editing an unrelated field; require active status only for a new assignment.
- [ ] Run the test file and page visibility cases; format and commit.

## Task P3: Carry optional assignments through production and Flash

**Create:**
- `app/Actions/Production/AssignProductionLocation.php`
- `tests/Feature/ProductionLocationAssignmentTest.php`
- `tests/Feature/FlashProductionCapacityTest.php`

**Modify:**
- `app/Actions/Production/CreateProductionDraft.php`
- `app/Actions/Production/PlanProduction.php`
- `app/Services/Production/FlashProductionSimulator.php`
- `app/Services/Production/FlashDateProposalService.php`
- `app/Services/Production/ProductionDailyOccupancy.php`
- `app/Services/Production/ProductionDetailPresenter.php`
- `app/Livewire/ProductionBench/Production/FlashPlanner.php`
- `app/Livewire/ProductionBench/Production/ProductionCreate.php`
- `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- `app/Livewire/ProductionBench/Production/ProductionIndex.php`
- `app/Livewire/ProductionBench/Production/ProductionCalendar.php`
- Corresponding existing views in `resources/views/livewire/production-bench/production/`: `flash-planner.blade.php`, `production-create.blade.php`, `production-detail.blade.php`, `production-index.blade.php`, `production-calendar.blade.php`
- `lang/en/production_bench.php`

- [ ] Write failing tests for default prefill, deliberate clearing, override, same-workspace validation, stale disabled payload, preserved assignments after off/on, and all scheduling examples below.
- [ ] Run `php artisan test --compact tests/Feature/ProductionLocationAssignmentTest.php tests/Feature/FlashProductionCapacityTest.php`.
- [ ] Add optional location input to CreateProductionDraft and PlanProduction at the end of their signatures to preserve existing callers. Default resolution happens at form initialization; the persistence actions accept the resolved nullable selection and never reapply a product default to explicit null. Missing location input from existing callers remains valid and unassigned. Validate under the workspace lock; with the feature off ignore location input for new runs.
- [ ] Flash line arrays carry `production_location_id` through blankLine, applyRecipeDefaults, normalized simulation output, simulationSnapshot, datePreview, and generation. When enabled, choosing a product prefills its active default; switching products replaces that default. No inferred location from task type, department, or product family.
- [ ] Add location aggregates to ProductionDailyOccupancy. Fetch location limits once per proposal. Preserve the overall limit as the upper bound even when the sum of location limits is greater. Sort displayed preview rows by date then input priority, while keeping line_index/batch_number stable for generation keys.
- [ ] Implement `AssignProductionLocation::handle(User $actor, ProductionRun $production, ?int $locationId): ProductionRun` for Draft/Scheduled/Reserved runs. Require enabled flag and writable access, lock workspace then run, reassert ownership, and validate an active same-workspace target. No assignment changes for started or terminal runs. Assignment alone must not reschedule tasks or release stock reservations.
- [ ] Show location selection only when enabled on creation and Flash. Add an optional location action on production detail; show location text/filters on index and calendar only when enabled. Ignore/clear stale location filters while disabled and reset pagination when filters change. Do not alter calendar drag behavior.
- [ ] Add non-blocking daily count warnings to manual create, location reassignment, detail reschedule, and calendar reschedule. Exclude the current run before evaluating its proposed date/location. Use the same occupancy service, with the saved overall preference. Do not block saving or change a date automatically.
- [ ] Preview dates grouped by day with concise existing/new totals and existing run names or links loaded in one workspace-scoped query. Render location labels only when enabled. Existing rows are read-only context and never included in the create payload.

Scheduling examples with working Monday 2026-09-21:

| Overall / locations | Existing Monday | New request | Expected |
| --- | --- | --- | --- |
| 2 / disabled | 1 run | 2 batches | Monday, Tuesday |
| 3 / Soap 2, Lab 2 | none | 2 Soap + 2 Lab | 2 Soap + 1 Lab Monday; 1 Lab Tuesday |
| 3 / Soap 1, Lab 2 | 1 Soap | Soap row then Lab row | Soap Tuesday; Lab Monday |
| 2 / enabled | 1 unassigned | 2 assigned | One Monday, one Tuesday |
| 2 / disabled, saved Soap limit 1 | none | 2 batches | Both Monday, location unset |

- [ ] Run both new files plus `ProductionBenchProductionCreateTest.php`, `ProductionBenchProductionsTest.php`, `ProductionBenchProductionCalendarTest.php`, `ProductionTaskSchedulingTest.php`, and `ProductionBenchFlashPlannerTest.php`. Expect tasks to keep current date behavior. Format and commit.

## Task P4: Preserve accepted previews and idempotent generation

**Create:**
- `database/migrations/2026_09_16_165447_create_production_flash_submissions_table.php`
- `app/Models/ProductionFlashSubmission.php`
- `app/Services/Production/FlashPlanFingerprint.php`

**Modify:**
- `app/Models/ProductionRun.php`
- `app/Actions/Production/GenerateFlashProductions.php`
- `app/Actions/Production/CreateProductionDraft.php`
- `app/Actions/Production/ScheduleProduction.php`
- `app/Actions/Production/RescheduleProduction.php`
- `app/Actions/Production/UpdateProductionPlan.php`
- `app/Actions/Production/CancelProduction.php`
- `app/Actions/Production/DeleteProductionRun.php`
- `app/Actions/Production/UpdateProductionWorkingCalendar.php`
- `app/Actions/Production/SaveProductionHoliday.php`
- `app/Actions/Production/AssignProductionLocation.php`
- `app/Actions/Production/SaveProductionLocation.php`
- `app/Livewire/ProductionBench/Production/SettingsIndex.php`
- `app/Livewire/ProductionBench/Production/FlashPlanner.php`
- `resources/views/livewire/production-bench/production/flash-planner.blade.php`
- `tests/Feature/GenerateFlashProductionsTest.php`
- `tests/Feature/ProductionBenchFlashPlannerTest.php`
- `lang/en/production_bench.php`

This task is required before calendar-aware Flash is released. A retry must not count its own previously generated runs and create a shifted duplicate proposal.

- [ ] Add regression tests in the existing generation test file using generateFlashFixture(): identical retry returns the same IDs even after another proposal fills dates; changed request with the same key fails; removed/partial prior set fails; stale preview creates nothing; concurrent occupancy change causes a refreshed preview; failure rolls back all runs and numbering; old pre-feature idempotency keys retain their current validation path.
- [ ] Run `php artisan test --compact tests/Feature/GenerateFlashProductionsTest.php tests/Feature/ProductionBenchFlashPlannerTest.php`.
- [ ] Persist new submissions in `production_flash_submissions` with workspace, hashed submission key, request hash, and generated production IDs. This survives deletion of every generated run; hashes stored only on the runs cannot provide that guarantee. No historical backfill. Fingerprint service provides `request(array $lines, string $firstDate, int $batchesPerDay): string` and `proposal(array $simulation, array $proposals, Workspace $workspace, int $batchesPerDay): string`. Canonicalize only allow-listed inputs in a fixed key order; normalize numbers with the same rules as the simulator. Include row ordering, quantities, units, task set, and selected locations. Do not fingerprint incidental timestamps or translated names. Use SHA-256 over JSON_THROW_ON_ERROR serialization.
- [ ] During preview, generate the proposal fingerprint server-side and retain it in a Locked Livewire property. Lock the submission idempotency key too. Treat simulationSnapshot as display-only; re-simulate authoritative data on preview/generate. Invalidate accepted fingerprint on changes to any line, first date, or overall limit.
- [ ] Add optional final parameter `?string $expectedProposalFingerprint = null` to GenerateFlashProductions.handle. Flash UI must always provide an accepted fingerprint; missing preview cannot invoke generation through its component. Existing trusted action callers may omit it and accept immediate planning under the lock.
- [ ] In the generation transaction, acquire the workspace lock, assert access, then look up the submission's existing run keys **before computing occupancy**. For recorded submissions compare the canonical request hash and the complete set of stored production IDs without re-reading mutable products or defaults. Reject altered or partial submissions. Return the same records on exact replay without reapplying dates, locations, or defaults; this also works after manual edits to the created runs. For historical submissions without a submission record, retain the old same-request checks and the original batches-per-day proposal algorithm (no external occupancy), so adding this feature does not invalidate pre-feature retries. Never use this legacy path for new submissions.
- [ ] For a new submission, re-simulate, resolve persisted flags, and recompute dates under the lock. Compare the proposal fingerprint including effective location limits, firstDate, overall limit, product/batch inputs, task schedule, and ready dates. On mismatch throw a localized validation error without writes. The component clears acceptance, refreshes the visible proposal, and requires another explicit Create click. Never silently commit new dates.
- [ ] Store request hash on every run from the new submission. Persist the successful daily limit in the same transaction. Respect the stable line_index/batch_number idempotency key structure. Failed or duplicate submissions must not advance counters or duplicate tasks.
- [ ] Make every scheduling-affecting write serialize on the same workspace lock: audit CreateProductionDraft, ScheduleProduction, RescheduleProduction, UpdateProductionPlan, CancelProduction, DeleteProductionRun, AssignProductionLocation, SaveProductionLocation, and UpdateProductionWorkingCalendar/SaveProductionHoliday plus holiday removal in SettingsIndex. Use workspace-first then run/location locks consistently; do not lock every workspace run to generate a proposal. Do not broaden this into a repository-wide lock-order refactor; ensure nested calls on these paths follow the same order. Preserve retries and ownership assertions. Counted-to-counted status changes need no scheduler change; do not refactor unrelated production execution.
- [ ] Add a two-connection PostgreSQL integration case for overlapping new Flash submissions: first submission occupies the date; the second blocks then fails its stale fingerprint or schedules the next day if called without a preview. Keep this isolated from SQLite/in-memory test runs and never point it at live data.
- [ ] Run both test files and `ProductionPlanningTest.php`, `ProductionExecutionTest.php`, `ProductionTaskSchedulingTest.php`, and `ProductionRunBatchNumberingTest.php`. Format and commit.

## Completion criteria

- [ ] Every scheduled new Flash run fits overall and enabled location limits, including work from previous sessions.
- [ ] Busy locations do not unnecessarily block other locations' earlier dates.
- [ ] Disabled feature remains invisible with existing locations, defaults, assignments, and stale browser state present.
- [ ] Repeated clicks, stale previews, failed writes, and cross-workspace IDs produce no duplicate/partial production set.
- [ ] Manual overrides remain possible with concise feedback; existing automatic/manual task-date rules are unchanged.
- [ ] Follow the shared plan's formatting, build, browser review, Truss, graph refresh, and final verification instructions.
