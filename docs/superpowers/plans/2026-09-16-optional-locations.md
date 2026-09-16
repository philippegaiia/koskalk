# Optional Production and Storage Locations Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Use subagents only if explicitly authorized. Steps use checkbox syntax for tracking. Complete the shared foundation here, then execute the two linked implementation plans.

**Goal:** Let workshops opt into production locations and storage locations independently, while users who leave them disabled encounter no location-related workflow.

**Architecture:** Keep two workspace-owned location models with separate responsibilities. Store two explicit feature flags on Workspace, production assignments on ProductionRun, product defaults on Recipe, storage assignments on StockLot, and material defaults on WorkspaceMaterialSetting. Extend the existing Flash date proposal and stock receipt paths; do not introduce an ERP resource hierarchy or location-specific stock ledger.

**Tech Stack:** PHP 8.5, Laravel 13.30.1, Livewire 4.4.3, Filament 5.7.8 forms/actions, PostgreSQL, Pest 4.7.8, Tailwind 4. Versions were confirmed against the connected application's Boost information and this checkout's composer.lock. The inline project instructions contain older Filament examples; use installed v5 documentation.

---

## Implementation verification notes

- Shared preferences and flat location managers, production assignments/defaults, occupancy-aware Flash dates and retry protection, and storage defaults/receipts/reassignment are implemented. The original task checklists below remain the design reference; per-task commits were not made.
- Final integrated production, calendar, storage UI, inventory, receipts, material settings, and navigation verification: 951 passed, 5 skipped, 4,866 assertions. Pint, frontend build, migration checks, diff whitespace checks, and the AST-only graph refresh passed.
- The calendar retains its existing read-only interaction, with optional location labels and filters. Manual date changes and nonblocking warnings are available in production screens independently of the location switch.
- Prepared for the user-authorized local merge from `gulf-of-burgas` into `main`. Before migration, backed up the populated local PostgreSQL database and successfully rehearsed all three migrations against a restored copy. No remote deployment is included.
- The full suite passed 3,428 tests with 25 skipped before the final scheduling-dialog adjustment; focused tests cover that adjustment, date normalization and validation, capacity warnings, and tenant isolation. Planning date fields use Filament non-native date pickers; Flash line options span the full row.
- Local verification uses a dedicated SQLite database and in-memory Pest databases. SQLite table rebuilds require preserving existing triggers and partial indexes; the migration includes a round-trip regression.
- Flash retry metadata uses a durable submission table instead of columns on individual runs, so deleting all generated runs cannot make an old submission key reusable.
- Livewire/HTTP checks and the frontend build are available. Browser automation reported no available browser. A real two-connection PostgreSQL concurrency run remains unverified in this environment.
- Luna Max implementation and final independent Sol High review completed. The reviewer reported no remaining actionable findings after fixes; its focused verification passed 37 tests and 151 assertions.

## Approved behavior

The conversation is the approved design. The subsequent instruction authorizes implementation with Luna Max and independent review with Sol High.

- Two settings: **Use production locations** and **Use storage locations**, both off by default.
- Settings are independently selectable. Enabling one never enables the other.
- Off means absent from operational forms, list columns, filters, badges, previews, notifications, and empty states. No onboarding prompt or reminder.
- The two switches remain discoverable in Settings. Management controls appear there only for the enabled option.
- Enabling an option creates no locations and backfills no assignments. Users can enable it without completing setup.
- Turning an option off preserves definitions, defaults, and existing assignments. Turning it on restores their visibility.
- Backend logic reads the current persisted flag. A hidden field is not an authorization boundary.
- Disabled production locations have no effect on Flash scheduling. The overall daily production limit still applies.
- Disabled storage locations do not resolve defaults for new stock, change existing assignments, or filter stock availability.
- Assignments remain nullable when enabled. Clearing an assignment is intentional and must not reapply its default.
- Location lists are flat: name plus active/inactive state; production locations additionally have a positive daily limit. No site, warehouse, aisle, bin, machine, shift, or task-capacity hierarchy.
- Production locations and storage locations are separate lists. The same real room may be named in both without linking the records.
- One stock lot has at most one current storage location. Reassignment changes only that reference; it does not transfer quantities or alter cost, reservations, or handling status.
- Material defaults apply only to new lots. Product defaults apply only to new productions.
- Overall and production-location daily limits count production starts by planned production date, never task counts or task durations. Keep existing task date rules and manual task adjustment.

## Delivery order

1. Shared foundation in this file: explicit settings and an unobtrusive configuration surface.
2. [Production locations and Flash scheduling](2026-09-16-production-locations-and-flash.md): calendar-aware daily limit first, then optional production locations.
3. [Storage locations](2026-09-16-storage-locations.md): optional material defaults, receipt assignment, and lot reassignment.

Each location feature is independently usable. Both flags remain false during deployment, including for existing workspaces. Do not auto-enable based on whether a location exists.

## Repository findings and constraints

- No local `graphify-out/` exists. The Cosmood reference graph exists at `/Users/philippe/Herd/cosmood/graphify-out/`; it indexes app/Filament, not the complete service layer.
- Cosmood's ProductionLine/Flash planning provides a useful reference for daily counts, not code to transplant: Koskalk has workspace ownership, different production states, optionality requirements, and its own actions.
- Boost schema inspection found no location tables or location columns. Ingredient and packaging stock already share `stock_lots`.
- `WorkspaceMaterialSetting.buffer_quantity` is currently **NOT NULL**. `WorkspaceMaterialSettings::synchronize()` deletes the entire row when clearing a buffer. Storage defaults require making the buffer nullable and preserving unrelated fields.
- Flash already has `batchesPerDay`, default 1. `FlashDateProposalService` currently counts only the new proposal. `GenerateFlashProductions` recalculates dates while holding a workspace lock, and uses per-run idempotency keys.
- `SettingsIndex` is already large. Add a focused component rather than appending two CRUD implementations to it.
- Production Bench settings navigation is defined in `app/Support/ProductionBenchNavigation.php`. The stable page key must survive Livewire update requests.
- The 2026-07-28 production-bench design excluded production-line scheduling and multiple warehouses/sites from V1. This approved extension supersedes only the production-date scheduling exclusion. Storage labels remain short of warehouse management; other non-goals stand.
- Local `vendor/autoload.php` is missing. No application tests or Artisan commands have run in this checkout. Boost refers to a connected installed application: never assume its database is a disposable test database or run this worktree's migrations against it implicitly.

## Preparation before implementation

- [ ] Read `.ai/rules/index.md`, every rule matching the paths in each task, and run `grep -rinE 'location|stock|receipt|flash|capacity|settings' .ai/rules`. Relevant files include app, actions, models, models-services, services, livewire, livewire-production-bench, migrations, policies, routes, forms, views, production, production-bench, purchasing, production-bench-purchasing, views-livewire-production-bench, lang, and tests.
- [ ] Read `.claude/skills/testing-best-practices/SKILL.md` and its applicable endpoint, security, isolation, assertions, test-data, and review rules. Activate truss-schema for the schema work.
- [ ] Restore this checkout's dependencies with `composer install --no-interaction` using the existing lockfile; do not change package constraints or run composer update. Configure a dedicated local test database following the existing test setup, without copying credentials into logs or documentation.
- [ ] Run `composer show --direct`, inspect package.json, and use Boost search-docs for the installed Laravel/Livewire/Filament APIs. During planning, documentation was searched for transactions/pessimistic locking, locked properties/authorization, and hidden form fields.
- [ ] Inspect `php artisan list --no-interaction` and command help before scaffolding. Use make:model, make:class, make:migration, make:policy, and `make:test --pest Name --no-interaction`. Do not include `Feature/` in test names. Factories are test support; no demo or default locations should be seeded.
- [ ] Run Truss exports focused on `workspaces`, `production_runs`, `recipes`, `stock_lots`, and `workspace_material_settings`, plus `php artisan truss:doctor --no-interaction`, against the intended local development database. Use Boost database-schema for additional structure inspection. Do not infer deployed structure from old migration files.

## Task 1: Persist optional feature settings and the daily planning preference

**Create:**
- `database/migrations/2026_09_16_100000_add_optional_location_settings_to_workspaces_table.php`
- `app/Actions/Production/SaveProductionBenchPreferences.php`
- `tests/Feature/ProductionBenchPreferencesTest.php`

**Modify:**
- `app/Models/Workspace.php`
- `database/factories/WorkspaceFactory.php`

Scaffold the migration with Artisan; use its generated timestamp if the suggested name is occupied, and preserve ordering before the dependent plans.

- [ ] Write failing behavior tests for independent switches, false defaults, a remembered positive daily limit, disabled/read-only entitlement, foreign workspace access, and invalid zero/fractional/negative limits.
- [ ] Run `php artisan test --compact tests/Feature/ProductionBenchPreferencesTest.php`; expect failures for missing behavior, not broken test setup.
- [ ] Add this schema to the migration's `up()` and drop these three columns in `down()`:

```php
Schema::table('workspaces', function (Blueprint $table): void {
    $table->boolean('uses_production_locations')->default(false);
    $table->boolean('uses_storage_locations')->default(false);
    $table->unsignedInteger('production_daily_limit')->default(1);
});
```

- [ ] Add these fields to Workspace's Fillable attribute and boolean/integer casts. The migration supplies existing and new workspaces with false/false/1; no provisioning job is needed.
- [ ] Implement the following action contract using ProductionBenchAccess, validation before mutation, workspace `lockForUpdate()` as the first transactional lock, reasserted access, and `DB::transaction(..., attempts: 5)`:

```php
public function handle(
    User $actor,
    Workspace $workspace,
    bool $usesProductionLocations,
    bool $usesStorageLocations,
    int|string $productionDailyLimit,
): Workspace
```

Validate the limit as an integer from 1 through `FlashProductionLimits::MAX_BATCHES_PER_SUBMISSION` (1000). Reject invalid input rather than rounding. Save only the three allow-listed fields. Do not update, clear, or backfill child records when flags change. Use localized errors in `lang/en/production_bench.php`.

- [ ] Run the affected test file again; expect all cases to pass. Commit only this task's files after formatting PHP.

## Task 2: Put both switches in one Settings page

**Create:**
- `app/Livewire/ProductionBench/Production/PlanningPreferences.php`
- `resources/views/livewire/production-bench/production/planning-preferences.blade.php`
- `resources/views/production-bench/production/planning-preferences.blade.php`
- `tests/Feature/ProductionBenchPlanningPreferencesPageTest.php`

**Modify:**
- `routes/web.php`
- `app/Support/ProductionBenchNavigation.php`
- `lang/en/production_bench.php`
- `tests/Unit/ProductionBenchNavigationTest.php`

- [ ] Write failing authenticated page tests covering save/reload, independent flags, read-only refusal, and navigation active state after a Livewire update. Assert persisted state, not only notifications.
- [ ] Run `php artisan test --compact tests/Feature/ProductionBenchPlanningPreferencesPageTest.php tests/Unit/ProductionBenchNavigationTest.php`.
- [ ] Register `production-bench.production.settings.planning` at `/production/settings/planning` inside the existing Production Bench middleware group, using Route::view and the Blade wrapper. Add one Settings leaf named **Planning & storage**. Do not add operational navigation tabs for either location feature.
- [ ] Build the class-based Livewire component with HasForms/HasActions and the existing Filament schema conventions. Hydrate state from the workspace. The form contains the daily limit and the two switches, with these exact label keys:

```php
'planning_and_storage' => 'Planning & storage',
'daily_production_limit' => 'Maximum productions per day',
'uses_production_locations' => 'Use production locations',
'uses_storage_locations' => 'Use storage locations',
```

Use the existing page layout, notification concern, Save action and access handling. New location managers from the dependent plans are nested below the form only when their persisted flag is true. Until their implementation lands, the switches may save without showing managers; ship both dependent plans before presenting the feature to users.

- [ ] On save, invoke SaveProductionBenchPreferences and replace state with the returned workspace. Changing a switch in unsaved form state must not enable backend behavior.
- [ ] Run both test files again. Verify that the ordinary production and inventory navigation did not grow. Commit this task's files.

## Global acceptance matrix

| Persisted production flag | Persisted storage flag | Operational behavior |
| --- | --- | --- |
| false | false | No location UI anywhere; overall Flash daily limit still works |
| true | false | Production location controls only |
| false | true | Storage controls only |
| true | true | Both sets of controls, independently optional |

For each row, test with location records and saved assignments already present. Testing only empty databases would miss the most important off-state regression.

When a flag turns off in another tab, ordinary saves must not fail because their old payload contains a hidden location. Ignore that stale location field and preserve existing assignments. Dedicated location-changing actions must refuse while disabled. Stale URL filters must be discarded, not silently continue narrowing results. Reenablement must show preserved values.

## Verification and handoff

- [ ] Complete the focused tests in each dependent plan, including the full four-state visibility matrix and cross-workspace cases.
- [ ] Run `vendor/bin/pint --dirty --format agent` for PHP changes. Run `vendor/bin/filacheck --fix` only if files in app/Filament were changed; none are planned here.
- [ ] Build frontend assets with `npm run build` after Blade/UI changes, using existing locked dependencies. Do not start an HTTP server: Herd serves the app.
- [ ] Use Boost get-absolute-url for review URLs, inspect desktop and tablet flows, and use recent Boost browser logs. No new browser-test dependency is authorized.
- [ ] Run `php artisan truss:diff --no-interaction` after local migrations and inspect the actual schema change.
- [ ] Run `graphify update .` after implementation code changes. No graph refresh is needed for writing these plan documents alone.
- [ ] Record the approved durable optionality and one-location-per-lot rules through Boost record-rule once implementation establishes the paths. Do not use personal memory or handwrite rule files.
- [ ] Report focused test results and ask the user to run the complete suite with `php artisan test --compact`, per project guidance. Do not represent unrun tests as passing.

No dependency additions, task-capacity scheduler, automatic line balancing, inventory transfers, split-lot quantities, storage capacity, mandatory locations, or automatic enablement are included.
