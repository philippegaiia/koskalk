# Production Planning and Execution Contextual Help Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Explain Production Bench planning and execution states, next actions, consequences, and reversibility without weakening existing confirmations or operational warnings.

**Architecture:** The shared Production Bench page component receives an explicit ordered topic list, renders the localized registry, and shows one page-level Help action. Planning and execution views add selective direct triggers. Production-detail chooses a static status topic from the current `ProductionRunStatus`, so help remains state-aware without putting business calculations in translation files.

**Tech Stack:** Laravel language files, Blade components, Livewire 4, Production Bench enums and presenters, shared contextual-help foundation, Pest 4, WordPress.

---

## File map

**Create:**

- `lang/en/help_production_planning.php`
- `lang/en/help_production_execution.php`
- `tests/Feature/ContextualHelpProductionPlanningExecutionTest.php`

**Modify:**

- `resources/views/components/production-bench/page.blade.php`
- `resources/views/livewire/production-bench/production/production-create.blade.php`
- `resources/views/livewire/production-bench/production/production-index.blade.php`
- `resources/views/livewire/production-bench/production/production-detail.blade.php`
- `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- `resources/views/livewire/production-bench/production/flash-planner.blade.php`
- `resources/views/livewire/production-bench/production/production-calendar.blade.php`
- `resources/views/livewire/production-bench/production/task-index.blade.php`
- `database/seeders/data/interface-translations.json`
- `tests/Feature/ProductionBenchLayoutTest.php`
- `tests/Feature/ProductionBenchProductionCreateTest.php`
- `tests/Feature/ProductionBenchProductionsTest.php`
- `tests/Feature/ProductionBenchStockPreparationTest.php`
- `tests/Feature/ProductionBenchFlashPlannerTest.php`
- `tests/Feature/ProductionBenchProductionCalendarTest.php`
- `tests/Feature/ProductionTaskIndexTest.php`

### Task 1: Define planning and execution topic contracts

- [ ] **Step 1: Generate the failing test**

Run: `php artisan make:test --pest ContextualHelpProductionPlanningExecutionTest --no-interaction`

- [ ] **Step 2: Assert the exact planning inventory**

```text
help_production_planning.status.draft
help_production_planning.status.scheduled
help_production_planning.status.reserved
help_production_planning.schedule.production_date
help_production_planning.schedule.non_working_date
help_production_planning.batch.basis_and_expected_units
help_production_planning.batch.preset
help_production_planning.flash.desired_units
help_production_planning.flash.batch_split
help_production_planning.calendar.capacity
help_production_planning.identity.planning_reference
help_production_planning.identity.batch_number
help_production_planning.stock.reservation_readiness
help_production_planning.stock.requirement_shortage
```

- [ ] **Step 3: Assert the exact execution inventory**

```text
help_production_execution.status.in_production
help_production_execution.status.completed
help_production_execution.status.cancelled
help_production_execution.status.aborted
help_production_execution.stock.preparation
help_production_execution.stock.automatic_allocation
help_production_execution.stock.manual_allocation
help_production_execution.stock.shortage
help_production_execution.actions.start
help_production_execution.actuals.consumption
help_production_execution.tasks.execution
help_production_execution.journal.entries
help_production_execution.actions.complete
help_production_execution.actions.abort
help_production_execution.output.readiness_delay
help_production_execution.output.release
help_production_execution.output.early_release
```

Every action and status topic must contain title, summary, `what_to_do`, and `why`. Tests reject missing consequence or reversibility language for Start, Complete, Abort, Release, and Early release.

- [ ] **Step 4: Verify red and author English content**

Run: `php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php`

Author English topics with these rules:

- State what the current status means and which next action is available.
- State whether the action changes reservations, stock movements, tasks, output-lot state, or the production status.
- State what is safely reversible and what requires a corrective workflow.
- Distinguish planning reference from assigned batch number.
- Distinguish requirements from actual lot allocations.
- Explain that completing production records output but does not necessarily release it for use.
- Keep irreversible-action confirmations and warnings inline.

Run Humanizer only after operational review; preserve state names, consequences, negations, and warning severity.

- [ ] **Step 5: Synchronize and review all text translations**

Run `php artisan translations:sync`, draft every blank text field for `de`, `es`, `fr`, `it`, `nl`, and `pt_BR`, review each topic in the relevant production state, then run `php artisan translations:catalogue:export`. Leave `article_url` absent until a WordPress page is published.

- [ ] **Step 6: Verify green and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git add lang/en/help_production_planning.php lang/en/help_production_execution.php database/seeders/data/interface-translations.json tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git commit -m "feat: add production operation help content"
```

### Task 2: Extend the Production Bench page component

**Files:** Production Bench page component, layout tests, contextual-help production test.

- [ ] **Step 1: Add failing component-contract tests**

Render:

```blade
<x-production-bench.page
    active="production"
    :help-topics="['help_production_planning.status.draft']"
>
    <span>Content</span>
</x-production-bench.page>
```

Assert one topic registry, one visible text Help trigger, the original navigation state, and slot content. Render without `helpTopics` and assert no empty trigger or registry appears.

- [ ] **Step 2: Verify red and implement the prop**

Run: `php artisan test --compact tests/Feature/ProductionBenchLayoutTest.php --filter="contextual help"`

Add `helpTopics` as a default empty array. When non-empty, render the registry and a right-aligned Help action after subnavigation and before the slot. Keep navigation outside scroll containers and preserve durable `active` and `subnavigation` state.

- [ ] **Step 3: Verify and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchLayoutTest.php tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git add resources/views/components/production-bench/page.blade.php tests/Feature/ProductionBenchLayoutTest.php tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git commit -m "feat: register Production Bench help topics"
```

### Task 3: Add planning page help and state triggers

**Files:** create, index, flash planner, calendar views and their tests.

- [ ] **Step 1: Assert page subsets and trigger locations**

Require these mappings:

- Production create: Draft, Production date, Non-working date, Basis and expected units, Preset, Requirement shortage.
- Production index: Draft, Scheduled, Reserved, Planning reference, Batch number, Reservation readiness.
- Flash planner: Desired units, Batch split, Preset, Production date, Calendar capacity.
- Production calendar: Calendar capacity plus Scheduled and In production status explanations.

Add field or section triggers beside the production date, batch basis, preset, requirements preview, and flash scheduling headings. Add visible `Why?` beside non-working-date and shortage states.

- [ ] **Step 2: Verify the red tests**

Run:

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/ProductionBenchProductionCalendarTest.php
```

- [ ] **Step 3: Implement without mutating planning state**

Pass explicit `:help-topics` arrays into `<x-production-bench.page>`. Add direct triggers only. Keep all existing `wire:model`, `wire:click`, preview calculation, planning, and flash-generation behavior unchanged.

- [ ] **Step 4: Verify and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ProductionBenchProductionCreateTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionBenchFlashPlannerTest.php tests/Feature/ProductionBenchProductionCalendarTest.php
git add resources/views/livewire/production-bench/production/production-create.blade.php resources/views/livewire/production-bench/production/production-index.blade.php resources/views/livewire/production-bench/production/flash-planner.blade.php resources/views/livewire/production-bench/production/production-calendar.blade.php tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git commit -m "feat: add production planning help"
```

### Task 4: Select precise status help on Production Detail

**Files:** production-detail view and tests.

- [ ] **Step 1: Add a status dataset**

For every `ProductionRunStatus` case, render Production Detail and assert the status trigger maps exactly:

```php
ProductionRunStatus::Draft => 'help_production_planning.status.draft'
ProductionRunStatus::Scheduled => 'help_production_planning.status.scheduled'
ProductionRunStatus::Reserved => 'help_production_planning.status.reserved'
ProductionRunStatus::InProduction => 'help_production_execution.status.in_production'
ProductionRunStatus::Completed => 'help_production_execution.status.completed'
ProductionRunStatus::Cancelled => 'help_production_execution.status.cancelled'
ProductionRunStatus::Aborted => 'help_production_execution.status.aborted'
```

Assert only the current status topic enters the serialized subset.

- [ ] **Step 2: Verify red and implement the static mapping**

Run: `php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php --filter="status topic"`

Use a Blade `match` on the enum to choose a static key. Merge it with only the topics visible in the current lifecycle stage. Translations never calculate status.

- [ ] **Step 3: Add consequential action triggers**

Add Help or `Why?` beside Assign batch number, Prepare stock, Start, Save actuals, Complete, Abort, readiness delay, Release batch, and Early release. Keep every existing `wire:confirm`, disabled condition, and visible warning.

- [ ] **Step 4: Run lifecycle regression and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ProductionBenchProductionsTest.php tests/Feature/ProductionExecutionTest.php tests/Feature/ProductionOutputReconciliationTest.php
git add resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git commit -m "feat: add state-aware production execution help"
```

### Task 5: Add stock-preparation and task help

**Files:** stock preparation, task index, and tests.

- [ ] **Step 1: Test exact topic placement**

Stock Preparation registers Preparation, Automatic allocation, Manual allocation, and Shortage. The Tasks page registers Task execution and Journal entries. Shortages use visible `Why?`; allocation section headings use Help.

- [ ] **Step 2: Verify red, add triggers, and preserve mutations**

Run: `php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionTaskIndexTest.php`

Add registry arrays and triggers without changing allocation proposals, manual selection, task assignment, task completion, or Livewire methods.

- [ ] **Step 3: Verify and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionStockPreparationTest.php tests/Feature/ProductionTaskIndexTest.php tests/Feature/ProductionTaskSchedulingTest.php
git add resources/views/livewire/production-bench/production/stock-preparation.blade.php resources/views/livewire/production-bench/production/task-index.blade.php tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git commit -m "feat: add stock and task contextual help"
```

### Task 6: Publish operation guides and complete translations

- [ ] **Step 1: Publish the English WordPress article set**

```text
/docs/production/planning-a-production/
/docs/production/flash-planning-and-calendar/
/docs/production/references-and-batch-numbers/
/docs/production/reservations-and-stock-preparation/
/docs/production/running-a-production/
/docs/production/consumption-tasks-and-journal/
/docs/production/completing-aborting-and-releasing-output/
```

Use stable anchors for each mapped topic. Add English URLs only after publication.

- [ ] **Step 2: Review and refine six locales**

Review the existing `de`, `es`, `fr`, `it`, `nl`, and `pt_BR` text in its actual production state. Preserve reviewed values unless correction is required. Publish localized WordPress articles independently and leave their URL absent until live.

- [ ] **Step 3: Export and verify the slice**

```bash
php artisan translations:catalogue:export
php artisan test --compact tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

- [ ] **Step 4: Commit translated operation help**

```bash
git add database/seeders/data/interface-translations.json tests/Feature/ContextualHelpProductionPlanningExecutionTest.php
git commit -m "feat: translate production operation help"
```
