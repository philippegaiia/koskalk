# Production Task Set Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the inline Production Setup task-set editor with a dedicated index and create/edit workflow that matches the completed batch-size workflow while keeping task sets reusable across many products.

**Architecture:** Add dedicated Livewire `TaskSetIndex` and `TaskSetForm` components, with Blade route wrappers matching the batch-size pages. The form owns ordered task rows and the product applicability/default selector; existing `SaveProductionTaskSet` and `SyncProductionTaskSetProducts` actions remain the domain boundary, with the form saving the task-set template first and synchronizing product links explicitly. The old Settings page becomes a compact entry point, so there is one authoritative task-set editor.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Tailwind CSS 4, Pest 4, existing Production Bench access and task-set actions.

---

## Product decisions locked by this plan

- A task set belongs to the workspace and can apply to zero, one, or many products. No product is required to save it.
- Each product can have many applicable task sets but at most one active default task set. The existing synchronization action remains responsible for enforcing that invariant.
- The default checkbox is always available. Selecting a default product automatically selects it as applicable; removing applicability removes it from defaults. This is the same interaction as batch sizes.
- Tasks are edited before product applicability in the form because the task sequence is the primary content of the record.
- Each task row contains task type, signed calendar-day offset, and optional duration override in minutes. Negative means preparation/before production, `0` means the production-day anchor, and positive means after production. At least one row with offset `0` is required; preparation rows may precede it.
- A blank duration means “use the task type default duration.” Existing inactive task types remain visible when editing an old set, but new rows offer active task types only.
- Task-set deletion is available from the index. Because generated production tasks retain snapshots and nullable template references, deleting a template must not delete historical production tasks; the existing foreign keys should continue to null/cascade as designed.
- Product lists are searchable and paginated (25/50/100) so a workspace with hundreds of formulas remains usable.

## File map

**Create:**

- `app/Livewire/ProductionBench/Production/TaskSetForm.php`
- `app/Livewire/ProductionBench/Production/TaskSetIndex.php`
- `resources/views/livewire/production-bench/production/task-set-form.blade.php`
- `resources/views/livewire/production-bench/production/task-set-index.blade.php`
- `resources/views/production-bench/production/task-set-create.blade.php`
- `resources/views/production-bench/production/task-set-edit.blade.php`
- `resources/views/production-bench/production/task-set-index.blade.php`
- `tests/Feature/ProductionTaskSetPagesTest.php`

**Modify:**

- `routes/web.php`
- `resources/views/livewire/production-bench/production/settings-index.blade.php`
- `app/Livewire/ProductionBench/Production/SettingsIndex.php` (remove only state/render paths made obsolete by the dedicated page; keep compatibility aliases until all existing action tests are migrated)
- `lang/en/production_bench.php`
- `database/seeders/data/interface-translations.json`
- Existing task-set feature tests when their calls need to assert the new explicit product-sync workflow.

**Reuse without rebuilding:**

- `app/Actions/Production/SaveProductionTaskSet.php`
- `app/Actions/Production/SyncProductionTaskSetProducts.php`
- `app/Services/ProductionBenchAccess.php`
- `app/Models/ProductionTaskSet.php`
- Existing task type, recipe, pagination, checkbox, status-pill, and page-shell conventions from the batch-size pages.

## Task 1: Establish the dedicated routes and page shells

**Files:** `routes/web.php`, the three new route-wrapper Blade files.

- [ ] Add named routes in this order so static paths resolve before the edit parameter:
  - `production-bench.production.settings.task-sets` → `/production/settings/task-sets`
  - `production-bench.production.settings.task-sets.create` → `/production/settings/task-sets/new`
  - `production-bench.production.settings.task-sets.edit` → `/production/settings/task-sets/{taskSet}/edit`
- [ ] Use the same `Route::view` wrappers and `wire:navigate` conventions as batch sizes. The edit route passes the public task-set identifier to `TaskSetForm`; it must be scoped to the current workspace in `mount()` rather than trusting route model binding.
- [ ] Add a dedicated Production Setup submenu/link for Task sets that is visible whether the current page is the index, create, or edit page. Keep the existing Employees, Tasks, Calendar, and Batch sizes entries unchanged.
- [ ] Add route smoke assertions to `ProductionTaskSetPagesTest` before implementing the components; the tests should initially fail because the new component views do not exist.

## Task 2: Build `TaskSetForm` with the batch-size interaction pattern

**File:** `app/Livewire/ProductionBench/Production/TaskSetForm.php`.

- [ ] Define Livewire state for `name`, `isActive`, ordered `taskSetItems`, `productSearch`, `perPage`, `selectedRecipeIds`, and `defaultRecipeIds`, plus the optional public task-set record.
- [ ] In `mount()`, load an edit record by workspace and `public_id`, including `items.taskType`, `recipes`, and `defaultRecipes`. Convert item IDs and numeric inputs to form-safe strings so editing does not expose database formatting artifacts.
- [ ] For a new form, initialize one empty task row and the workspace defaults for page size. For an edit form, preserve item order, signed offsets, duration overrides, active state, applicability, and defaults.
- [ ] Add the same live checkbox synchronization used by batch sizes:
  - `updatedSelectedRecipeIds()` normalizes IDs and removes defaults that are no longer applicable.
  - `updatedDefaultRecipeIds()` normalizes IDs and merges defaults into applicability.
  - Use `wire:model.live` so the dependency is visible immediately, not only after save.
- [ ] Add `addTaskSetItem()` and `removeTaskSetItem()` with stable `wire:key` values and a minimum of one row in the UI.
- [ ] Validate `name`, task rows, and product IDs. Task rows must require a task type, accept signed whole-number offsets, accept blank or non-negative whole-number duration overrides, and require at least one offset `0`. Empty product applicability is valid.
- [ ] Call `ProductionBenchAccess::assertWritable()` before mutation. Save the template through `SaveProductionTaskSet`, then call `SyncProductionTaskSetProducts` with explicit assignments. Map validation errors to the visible task-row/product fields and redirect to the task-set index with a translated success message.
- [ ] Query products exactly as batch sizes do: workspace-owned recipes with published versions, case-insensitive search, and pagination. Query active task types for new rows and include selected inactive types on edit so an existing template cannot silently lose a task.
- [ ] Add tests first for mount formatting, signed offsets, zero-day-anchor validation, active toggle persistence, product/default synchronization, empty applicability, cross-workspace rejection, and read-only blocking. Run the focused test before implementation to confirm the new assertions fail.

## Task 3: Implement the readable two-part form UI

**File:** `resources/views/livewire/production-bench/production/task-set-form.blade.php`.

- [ ] Use the same page shell, card contrast, status notice, disabled/read-only treatment, and action bar as the batch-size form.
- [ ] Put the task-set name and active toggle at the top.
- [ ] Put the ordered “Tasks in this set” editor next: task type select, signed offset input with clear helper text (`before`, `production day`, `after`), optional duration override, remove row action, and “Add task.” Display row-level and summary validation errors.
- [ ] Put “Applicable products” below the task editor: search field, page-size selector, selected/default checkboxes, selected count, and compact pagination. Keep default checkboxes enabled and apply the automatic synchronization behavior from Task 2. Use the accent color for checkboxes and accessible labels containing the product name.
- [ ] Show inactive task types retained by an existing set with an inactive label, while preventing selection of inactive types for newly added rows.
- [ ] Keep the form readable on tablet/mobile: stacked task rows below the desktop breakpoint, no horizontal page overflow, and product results inside a bounded scroll region.
- [ ] Add visible-label and keyboard/focus regression assertions for the task editor, search, active toggle, applicability, and default controls.

## Task 4: Build the compact task-set index

**File:** `app/Livewire/ProductionBench/Production/TaskSetIndex.php` and its Blade view.

- [ ] Mirror `BatchSizeIndex`: active/all/inactive status filter, debounced search, page-size selector, workspace scoping, and Production Bench read-only/disabled states.
- [ ] Search task-set name, task type name, and applicable product name. Eager-load ordered items/task types, recipes, and counts to avoid per-row queries.
- [ ] Render a readable table with task-set name, task summary, applicable-product disclosure, status, and edit/delete actions. Use `<details>` for long product lists and show default markers without expanding the table vertically for hundreds of products.
- [ ] Link create/edit actions to the dedicated routes. Delete with the existing confirmation pattern and a translated success/error message. Reset pagination after deletion.
- [ ] Add index tests for search, status filters, product disclosure, active/inactive rendering, edit/create links, workspace isolation, delete behavior, and read-only behavior.

## Task 5: Remove the duplicate inline editor and finish translations

**Files:** `resources/views/livewire/production-bench/production/settings-index.blade.php`, `app/Livewire/ProductionBench/Production/SettingsIndex.php`, translation files.

- [ ] Replace the current inline task-set form/list in the all-settings view with a compact card linking to the authoritative task-set index and create page. Do not leave two editors with divergent behavior.
- [ ] Keep compatibility aliases only where existing Livewire/tests still depend on them; remove dead task-set form state and methods once the focused suite proves no rendered path uses them.
- [ ] Add English translations for task-set page headings, task-row labels, offset guidance, duration override, search, selected counts, inactive-task warning, validation messages, delete confirmation, empty states, and success feedback.
- [ ] Update `database/seeders/data/interface-translations.json` with the same keys and placeholder names, then validate the JSON and run translation tests.

## Task 6: Verify production integration and regression safety

**Files:** existing production planning/flash tests only where a regression assertion is missing.

- [ ] Confirm production creation still selects the product’s active default task set from the reusable product links, while an explicitly selected task set remains valid.
- [ ] Confirm generated tasks retain snapshots and the task-set link, and deleting/editing a template does not rewrite historical generated tasks.
- [ ] Confirm the first production-day task is anchored to the production date (`days_after_production = 0`); preparation tasks may remain before it and after-production tasks retain their signed offsets.
- [ ] Run the focused page/schema/scheduling suites, then Pint, `git diff --check`, `npm run build`, and `graphify update .`.
- [ ] Run the full suite before review and record only unrelated pre-existing failures.

## Suggested commit boundaries

1. `test: specify dedicated task set pages`
2. `feat: add task set form and product applicability workflow`
3. `feat: add task set index and routes`
4. `refactor: replace inline task set setup editor`
5. `test: cover task set production integration`

The implementation should stop for review after the dedicated index/form, checkbox synchronization, and production-task regression tests are green.
