# Production Task Organization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Add optional departments and employee organization, make generated production tasks independently assignable and manageable, provide a focused cross-production task list, and standardize Production Bench success feedback on the existing shared toast system without introducing time tracking or ERP-style staffing complexity.

**Architecture:** Extend the existing `ProductionTaskType`, `ProductionTask`, `Employee`, and `ProductionTaskSet` flow instead of replacing it. Add workspace-scoped departments and an employee-to-department pivot. A task type may suggest one default department; generated tasks copy that suggestion, while each generated task remains independently editable. Keep production date scheduling and task organization as separate actions. Use the existing production detail page as the authoritative task workspace and add a lightweight task index for cross-production work. Use the existing app-shell notification component and Livewire notification concern for transient success/error feedback.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4, Blade, Tailwind CSS v4, Pest 4, SQLite/MySQL-compatible migrations, existing `InteractsWithAppNotifications` infrastructure.

---

## Scope and implementation rules

- Work directly on `main`; do not create a worktree or a feature branch.
- Preserve unrelated dirty-worktree changes. Stage only files belonging to the task being committed.
- Extend `docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md` and `docs/superpowers/specs/2026-08-04-production-planning-execution-design.md`; do not create a second production-planning model.
- Do not add timesheets, timers, actual labor duration, attendance, payroll, shifts, capacity planning, staffing restrictions, or a production-line scheduler.
- Departments are optional user-created records. Do not seed departments automatically. Empty-state examples may offer one-click creation for Production, Finishing / Filling, Packing / Labelling, and Quality.
- Cleaning/sanitation and maintenance remain outside production task generation because they can cover several batches. Users may still create their own department or task type if their operation needs it.
- Department assignment and employee assignment are independent. An employee can belong to many departments, but the UI must not force an employee to belong to the task’s department before assignment.
- Keep task date semantics from the approved design: the production anchor is always the production date; unfinished automatic tasks move with a production date; completed and manually dated tasks do not move; once production starts, the production date is locked.
- All mutations must remain workspace-scoped and reject terminal production/task states where the existing design requires read-only behavior.
- Use shared app toasts for transient success. Keep inline messages only for validation, warnings, shortages/conflicts, read-only reasons, non-working-date explanations, missing data/prices, and durable statuses.

## Task 1: Add departments and employee organization to the domain

### Files

- Create `app/Models/Department.php`.
- Create `database/factories/DepartmentFactory.php`.
- Create a migration for `departments` with `workspace_id`, trimmed `name`, normalized unique key, `is_active`, timestamps, and the workspace foreign key.
- Create a migration for `department_employee` with `department_id`, `employee_id`, both foreign keys, timestamps, and a unique pair.
- Create a migration adding nullable `title` to `employees`.
- Create a migration adding nullable `department_id` to `production_task_types`.
- Create a migration adding nullable `department_id` to `production_tasks`.
- Modify `app/Models/Employee.php` with `title`, `departments()`, and the inverse relationships needed for workspace-safe queries.
- Modify `app/Models/Workspace.php` with `departments()`.
- Modify `app/Models/ProductionTaskType.php` with the optional default department relationship.
- Modify `app/Models/ProductionTask.php` with the generated-task department relationship and fillable field.
- Create `app/Policies/DepartmentPolicy.php` and register it using the application’s existing policy discovery/convention.
- Create `app/Actions/Production/SaveDepartment.php`.
- Create `app/Actions/Production/SyncEmployeeDepartments.php`.
- Update the existing employee save/delete path in `app/Livewire/ProductionBench/Production/SettingsIndex.php` or its extracted action so employee deletion is guarded by production-task usage.
- Add the domain tests to `tests/Feature/ProductionDepartmentSchemaTest.php`.

### TDD steps

1. Add tests that create two workspaces and assert:
   - a department stores only its own workspace ID;
   - `name` is trimmed and its normalized key is lower-case/whitespace-normalized;
   - two departments with the same normalized name cannot exist in one workspace;
   - the same normalized name is valid in another workspace;
   - inactive departments remain queryable but are excluded from active assignment options;
   - an employee can belong to zero, one, or several departments;
   - duplicate pivot rows are impossible;
   - task types and production tasks may have no department;
   - a task type and task cannot point at a department from another workspace.
2. Add tests for lifecycle rules:
   - an unused department can be deleted;
   - a department referenced by a task type or production task cannot be deleted and returns the application validation error;
   - an employee with no production-task reference can be deleted;
   - an employee referenced by a production task cannot be deleted and must instead be deactivated;
   - deleting/deactivating one workspace’s record cannot affect another workspace.
3. Run `php artisan test --compact tests/Feature/ProductionDepartmentSchemaTest.php` and verify the new tests fail because the tables, relationships, and guarded actions do not yet exist.
4. Implement the migrations, model relationships, normalization, policy checks, save action, department sync action, and guarded delete behavior.
5. Re-run the same test command and verify all assertions pass.
6. Run `vendor/bin/pint --dirty --format agent`, then rerun the focused test file.
7. Commit only Task 1 files as `feat: add optional production departments`.

### Implementation details

- Keep department names user-facing and preserve the trimmed display value; use a separate normalized key for the database uniqueness constraint.
- Make every foreign key nullable with `nullOnDelete` only where historical records must survive; deletion actions must still refuse deletion when the record is used. Do not rely on a nullable foreign key alone to implement the product rule.
- The task type’s `department_id` is a default suggestion, not a required relationship. A generated task’s `department_id` is the copied snapshot for that production task and is editable independently thereafter.
- Employee title is optional plain text. Employees remain non-login records.

## Task 2: Extend Production Setup for departments, employees, and task-type defaults

### Files

- Modify `app/Livewire/ProductionBench/Production/SettingsIndex.php`.
- Modify `resources/views/livewire/production-bench/production/settings-index.blade.php`.
- Modify `resources/views/components/production-bench/production-settings-navigation.blade.php`.
- Modify `resources/views/components/production-bench/page.blade.php` to include the departments section in the active-navigation mapping.
- Modify `routes/web.php` to add the static `production.settings.departments` route before any parameterized settings route.
- Modify `lang/en/production_bench.php`.
- Modify `database/seeders/data/interface-translations.json`.
- Extend `tests/Feature/ProductionBenchProductionSettingsTest.php` and add `tests/Feature/ProductionDepartmentPagesTest.php` if the existing settings test becomes too broad.

### TDD steps

1. Add Livewire/feature tests that visit each settings route and assert the persistent Production Setup submenu contains Batch sizes, Departments, Employees, Task types, Task sets, and Working calendar, including on create/edit pages.
2. Add department-page tests for:
   - creating a trimmed active department;
   - editing its name and active state;
   - deactivating it without deleting it;
   - deleting an unused department;
   - receiving a visible validation error and no deletion when it is referenced;
   - workspace isolation.
3. Add employee-page tests for:
   - creating/editing first name, last name, optional title, active state, and multiple departments;
   - searching/filtering the department selector by name;
   - showing a compact department summary in the index table;
   - deleting an unused employee;
   - showing “deactivate instead” when a production task references the employee;
   - excluding inactive employees and departments from new assignment options.
4. Add task-type tests for an optional default department and for clearing that default without affecting existing generated tasks.
5. Run the focused settings tests and verify the new tests fail.
6. Implement the section state, form state, save/edit/delete handlers, department sync, active/inactive toggles, and task-type default selector. Keep the existing compact one-column form treatment for simple setup records and use the full inner container width for employee/department index tables.
7. Implement an accessible multi-select with search, selected-item summary, keyboard focus states, and a clear “No departments selected” empty state. Do not render hundreds of product/department options as an unbounded vertical block.
8. Add one-click example department creation only in the empty state; never create the examples automatically.
9. Remove any duplicate permanent success banner from settings mutations and use the shared notification concern as described in Task 5.
10. Re-run the focused tests, then run `vendor/bin/pint --dirty --format agent` and rerun them.
11. Commit only Task 2 files as `feat: organize production setup records`.

### Implementation details

- Preserve the current settings route names for existing links/tests. Add the departments route alongside the existing settings routes rather than replacing them.
- Keep active/inactive visible in every index row. Put the toggle and destructive action in the row, not in an unrelated card below the table.
- The task-type form exposes one optional “Default department” select. It is a suggestion for future generated tasks, never a requirement for saving the task type or task set.
- All labels, empty states, validation errors, delete confirmations, and toast messages must be added to both the English PHP catalogue and the translation seed JSON.

## Task 3: Separate task organization from date scheduling and propagate defaults

### Files

- Modify `app/Actions/Production/GenerateProductionTasks.php`.
- Create `app/Actions/Production/AssignProductionTask.php`.
- Modify `app/Actions/Production/RescheduleProductionTask.php` so it is date-only and no longer performs employee assignment.
- Modify `app/Actions/Production/ResetProductionTaskDate.php` if its authorization/status checks need to match the date-only boundary.
- Modify `app/Livewire/ProductionBench/Production/ProductionDetail.php`.
- Modify `resources/views/livewire/production-bench/production/production-detail.blade.php`.
- Modify `app/Models/ProductionTask.php` and related casts/accessors only where needed for department/assignment display.
- Extend `tests/Feature/ProductionTaskSchemaTest.php`.
- Extend `tests/Feature/ProductionTaskSchedulingTest.php`.
- Create `tests/Feature/ProductionTaskOrganizationTest.php`.

### TDD steps

1. Add generation tests that create a task type with a default department, generate a production, and assert the task copies the department. Change the task type default afterward and assert the existing task remains unchanged while a later production receives the new default.
2. Add assignment tests that assert:
   - a task can receive a department, an employee, both, or neither;
   - department and employee can be changed or cleared independently;
   - an active employee from the same workspace can be assigned even when they are not a member of the task department;
   - inactive employees/departments and cross-workspace IDs are rejected;
   - assignment works in Draft, Scheduled, Reserved, and In production;
   - assignment is rejected in Completed, Cancelled, and Aborted;
   - no task duration or time-tracking fields are created or updated.
3. Add date-invariant tests that assert:
   - changing a production date moves unfinished automatic tasks only;
   - completed and custom-date tasks do not move;
   - the production task remains on the production date;
   - date changes are rejected after production starts;
   - assignment remains available in production even though date mutation is locked.
4. Run `php artisan test --compact tests/Feature/ProductionTaskSchemaTest.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionTaskOrganizationTest.php` and verify the new tests fail.
5. Implement `AssignProductionTask` with a transaction, workspace ownership checks, active-record checks, terminal-status checks, and nullable department/employee inputs. Keep the action separate from date rescheduling so a user can assign a person without unintentionally moving a task.
6. Update task generation to copy the task type’s current default department at generation time.
7. Update `ProductionDetail` to load active departments and employees, expose separate assignment and date operations, show estimated duration as read-only, and render clear task-level validation/action errors. Keep completion/reopen actions separate.
8. Add production-detail controls for department, employee, completion/reopen, automatic/custom date state, reset-to-automatic, and unfinished-task date editing. Disable all mutations for terminal productions/tasks according to the approved rules.
9. Re-run the focused tests and verify they pass.
10. Run `vendor/bin/pint --dirty --format agent` and rerun the focused tests.
11. Commit only Task 3 files as `feat: organize generated production tasks`.

### Implementation details

- The first generated task remains the production anchor and must always use the production date; it must not inherit a negative/positive offset.
- Keep the existing calendar-day offset plus working-day snap semantics: preparation can be scheduled before production, curing/follow-up tasks use literal calendar offsets, and the working calendar snaps dates away from weekends/holidays in the direction already defined by the action.
- Task employee assignment is optional and never blocks generation or completion.
- When an employee or department becomes inactive after assignment, preserve the historical assignment on existing tasks but exclude the record from new assignment choices.

## Task 4: Add the cross-production Tasks view

### Files

- Create `app/Livewire/ProductionBench/Production/TaskIndex.php`.
- Create `resources/views/livewire/production-bench/production/task-index.blade.php`.
- Create `resources/views/production-bench/production/task-index.blade.php`.
- Modify `routes/web.php` with `/production/tasks` before `/production/{productionRun}`.
- Modify `resources/views/components/production-bench/navigation.blade.php` and any production submenu component needed to expose Tasks without hiding the existing Production page.
- Modify `lang/en/production_bench.php` and `database/seeders/data/interface-translations.json`.
- Create `tests/Feature/ProductionTaskIndexTest.php`.

### TDD steps

1. Add route tests asserting `/production/tasks` resolves to the task index and is not captured by the production-detail wildcard route.
2. Add data tests for the default Today view and the explicit Upcoming, Overdue, Completed, and All scopes. Assert a task appears in exactly the expected scope based on its scheduled date and completion state.
3. Add filter tests for task name, product/formula name, production public ID, department, employee, status, and a custom date range. Assert filters combine rather than replace each other.
4. Add workspace-isolation tests that ensure no task, production, product, employee, or department from another workspace appears in search results or option lists.
5. Add action tests that complete/reopen a task and assign/clear department or employee from the list, while date changes link to production detail instead of being performed in the list.
6. Run `php artisan test --compact tests/Feature/ProductionTaskIndexTest.php` and verify the tests fail before the component/routes exist.
7. Implement the Livewire query with eager-loaded production/product/department/employee relations, deterministic date/name ordering, pagination, and query-string filters.
8. Implement a responsive table/card layout with columns Date, Task, Production, Product, Department, Employee, and Completion. Do not show time-tracking columns. Provide keyboard-accessible filters and a clear empty state.
9. Add quick complete/reopen and assignment controls that call the same actions from Task 3. Link each production ID and task row to the existing production detail page.
10. Re-run the focused test file, then format and rerun it.
11. Commit only Task 4 files as `feat: add production task list`.

### Implementation details

- Keep the existing Production calendar for visual date planning; the Tasks view is the operational list for “what must be done today/next/late.” Do not introduce drag-and-drop or time-of-day scheduling.
- The default scope is Today, with one-click scope changes visible above the list. Completed tasks remain discoverable through Completed/All and search.
- Preserve the production detail page as the only place where production-date changes and task-date explanations are managed.

## Task 5: Replace production success banners with the shared toast system

### Files

- Modify `app/Livewire/ProductionBench/Production/SettingsIndex.php`.
- Modify `app/Livewire/ProductionBench/Production/ProductionCreate.php`.
- Modify `app/Livewire/ProductionBench/Production/FlashPlanner.php`.
- Modify `app/Livewire/ProductionBench/Production/ProductionDetail.php`.
- Modify `app/Livewire/ProductionBench/InventoryIndex.php`.
- Modify `app/Livewire/ProductionBench/Production/BatchSizeIndex.php` and `app/Livewire/ProductionBench/Production/TaskSetIndex.php`.
- Modify `resources/views/livewire/production-bench/production/production-create.blade.php`.
- Modify `resources/views/livewire/production-bench/production/flash-planner.blade.php`.
- Modify `resources/views/livewire/production-bench/production/production-detail.blade.php`.
- Modify `resources/views/livewire/production-bench/inventory-index.blade.php`.
- Modify `resources/views/livewire/production-bench/production/batch-size-index.blade.php` and `task-set-index.blade.php`.
- Reuse `app/Livewire/Concerns/InteractsWithAppNotifications.php`, `resources/views/components/app-notification.blade.php`, `resources/views/layouts/app-shell.blade.php`, and `resources/js/app-notification.js`; only change them if a test exposes a genuine shared-system defect.
- Modify `lang/en/production_bench.php` and `database/seeders/data/interface-translations.json` for every new message.
- Create `tests/Feature/ProductionBenchNotificationTest.php` and extend existing production/flash/settings tests.

### TDD steps

1. Add tests asserting successful production creation dispatches `app-notification` (or renders the app-shell session toast after redirect) and no permanent success banner remains in the component view.
2. Add tests asserting Flash generation keeps the existing celebration feedback but does not render a second persistent “generated IDs” banner.
3. Add tests for settings, batch-size, task-set, inventory, stock-preparation, task assignment, complete/reopen, cancellation, and stock release success paths. Each must dispatch one shared success notification and no duplicate inline success block.
4. Add tests asserting validation/action errors remain visible and are not converted into disappearing success feedback. Assert the shared notification uses `alert` semantics for errors and `status` semantics for successes through the existing component contract.
5. Run the focused notification and production test files and verify the new assertions fail.
6. Add `use InteractsWithAppNotifications` to the affected Livewire components, define `statusMessage`/`statusType` properties only where the existing trait/view contract needs them, and call `showAppNotification()` after successful same-page mutations.
7. Remove `$savedPublicId`, `$generatedPublicIds`, and other permanent success-only view blocks where they are no longer needed. Keep redirect/session status for flows that naturally redirect; let the app shell render it once.
8. Keep the Flash celebration as the sole special feedback for Flash generation, and ensure it is still dismissible/accessible.
9. Re-run focused tests, then run `vendor/bin/pint --dirty --format agent` and rerun them.
10. Commit only Task 5 files as `refactor: standardize production notifications`.

### Implementation details

- Success toasts auto-dismiss through the existing JavaScript behavior; errors remain manually dismissible.
- Do not turn durable statuses, read-only reasons, material shortages, planning conflicts, missing prices, or non-working-date explanations into transient success toasts.
- Avoid dispatching both a toast and a permanent success banner for the same action. A component may still dispatch a refresh event such as `production-settings-saved` when another component needs it, but that event must not render a second success message.

## Task 6: Translation, responsive/accessibility polish, and regression verification

### Files

- Update `lang/en/production_bench.php`.
- Update `database/seeders/data/interface-translations.json`.
- Update the new/modified Production Setup, production detail, task index, calendar, and notification Blade views from Tasks 2–5.
- Update or add `tests/Feature/ProductionBenchTranslationCatalogueTest.php` only if the existing catalogue test does not cover the new keys.
- Keep the authoritative design and roadmap documents linked from this plan; do not create additional planning documents.

### TDD and verification steps

1. Add translation-catalogue assertions for department, title, assignment, task-list scopes/filters, deletion/deactivation, and notification messages in English.
2. Run the focused suite:

   ```text
   php artisan test --compact \
     tests/Feature/ProductionDepartmentSchemaTest.php \
     tests/Feature/ProductionDepartmentPagesTest.php \
     tests/Feature/ProductionTaskOrganizationTest.php \
     tests/Feature/ProductionTaskIndexTest.php \
     tests/Feature/ProductionTaskSchemaTest.php \
     tests/Feature/ProductionTaskSchedulingTest.php \
     tests/Feature/ProductionBenchProductionSettingsTest.php \
     tests/Feature/ProductionBenchProductionsTest.php \
     tests/Feature/ProductionBenchProductionCreateTest.php \
     tests/Feature/ProductionBenchProductionCalendarTest.php \
     tests/Feature/ProductionBenchFlashPlannerTest.php \
     tests/Feature/FlashProductionSimulatorTest.php \
     tests/Feature/ProductionBenchNotificationTest.php
   ```

3. Run `vendor/bin/pint --dirty --format agent`.
4. Because this feature does not touch `app/Filament`, do not run Filacheck unless a later implementation task changes that directory. If Filament files are touched, run `vendor/bin/filacheck --fix` and fix every reported issue before continuing.
5. Run `npm run build` and verify the production asset build completes without Vite errors.
6. Run `git diff --check`.
7. Run `graphify update .` after the implementation commits so the production/departments/task-list relationships are reflected in the local graph.
8. Run the full `php artisan test --compact` suite. If an unrelated pre-existing failure remains, record its exact test name and failure in the handoff; do not weaken or delete it.
9. Manually verify the review scenario from the approved design:
   - create two departments and an employee with a title and multiple department memberships;
   - assign one task type a default department and leave another without one;
   - generate a production and confirm the first task is on the production date, later tasks follow the working-calendar rules, and the default department is copied;
   - assign/clear a department and employee from production detail while the production is in progress;
   - find the task through Today/Upcoming/Overdue/Completed in the Tasks view;
   - change the task type default and confirm existing generated tasks do not change;
   - deactivate an employee/department and confirm historical assignments remain visible but new assignment options exclude them;
   - confirm every success uses the shared toast and no duplicate permanent success block remains.
10. If all focused and full tests pass, create the final implementation commit as `feat: organize production tasks and teams` containing only the remaining files from this feature. Do not stage unrelated user work.

## Commit sequence

The implementation should produce these reviewable boundaries:

1. `feat: add optional production departments`
2. `feat: organize production setup records`
3. `feat: organize generated production tasks`
4. `feat: add production task list`
5. `refactor: standardize production notifications`
6. `feat: organize production tasks and teams` for final polish/tests only when Task 6 leaves intentional changes.

Each commit must be independently formatted and tested with the commands listed in its task. Never include the existing unrelated receipt, currency, inventory, or review-fix modifications in these commits.
