# Calendar Drag & Drop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users drag a reschedulable production to a new date on the production calendar; its automatic follow-up tasks shift accordingly via the working calendar. Tasks themselves are not draggable. Clicking any event (production or task) opens the related production page. An explicit "Change production date" form on the production detail page remains as the accessible, keyboard/touch-friendly fallback.

**Architecture:** Reuse the existing, already-tested `App\Actions\Production\RescheduleProduction` action (validates writable access, status, working days, completed-anchor, and cascades automatic tasks with working-day snapping). Add `ProductionBenchAccess::canWrite()` as the single capability check shared by the UI and the action. Add `moveProduction(string $publicId, string $plannedFor): array` to the calendar Livewire component; it wraps the action, returns `['ok' => bool, 'message' => ?string]`, and maps known validation failures to translated messages. Enable dragging with the `Interaction` plugin — imported from `@event-calendar/core` itself (it already exports `Interaction`; no extra package). The JS `eventDrop` handler formats the drop date from **local** date components (never `toISOString()` — it shifts a day in UTC+ timezones), calls `moveProduction`, reverts + toasts on failure, and shows a success toast on completion.

**Tech Stack:** `@event-calendar/core` 5.12.0 (exports `DayGrid`, `TimeGrid`, `List`, `Interaction`), Livewire 4, Laravel 13, PHP 8.5, Pest 4, Blade, Vite.

---

## Supersedes

The existing production design explicitly excluded drag-and-drop. This plan supersedes those exclusions:

- `docs/superpowers/specs/2026-08-04-production-planning-execution-design.md` (~line 192): "dates are edited through explicit forms; drag-and-drop is excluded initially to prevent accidental rescheduling."
- `docs/superpowers/specs/2026-08-04-production-planning-execution-design.md` (~line 372): "drag-and-drop calendar mutation" listed under excluded features.
- `docs/superpowers/plans/2026-07-28-production-bench-phase-3-planning-reservations.md` (~line 733): "Dates are changed only through explicit forms; no drag/drop."

Per the review decision: drag-and-drop is added **alongside** the explicit date form, not instead of it. The form is preserved (Task 5) so keyboard and screen-reader users and touch devices keep an accessible path. No other exclusion in those documents changes.

## Scope and implementation rules

- Work directly on `main`; do not create a worktree or a feature branch.
- Preserve unrelated dirty-worktree changes. Stage only files belonging to the task being committed.
- Do not change `RescheduleProduction`, `RescheduleProductionTask`, `ProductionWorkingCalendar`, or task-generation logic — they already implement the required semantics and are covered by `tests/Feature/ProductionTaskSchedulingTest.php`.
- Do not make tasks draggable. A task date follows its production only through the existing action cascade.
- Do not add client-side working-day snapping. The server is the source of truth; a failed drop reverts and shows the translated server message.
- The UI draggability gate must be identical to what the action enforces: `ProductionBenchAccess::canWrite()` (role owner/admin/editor **and** active entitlement). Viewers and inactive/cancelled workspaces never see draggable events.
- Date conversion in JS uses local date components (`getFullYear()/getMonth()/getDate()`), never `toISOString().slice(0, 10)` — the event in `eventDrop` is a local-timezone Date, and UTC serialization shifts the day in UTC+ timezones.
- Click-to-open already works (every event carries `extendedProps.url` pointing at the production show page) — Task 6 only verifies it.
- No new runtime dependencies. The optional Task 7 (vitest) adds a dev dependency and requires explicit approval before running `npm install`.

## File map

- `app/Services/ProductionBenchAccess.php` — new `canWrite(User, Workspace): bool`.
- `app/Livewire/ProductionBench/Production/ProductionCalendar.php` — per-event `editable` flags; new `moveProduction()` method with translated error mapping.
- `resources/js/production-calendar.js` — import `Interaction` from `@event-calendar/core`; local-date formatting helper `toInputDate`; `eventDrop` handler with in-flight guard, revert + error toast, success toast.
- `resources/views/livewire/production-bench/production/production-calendar.blade.php` — pass `editable`, `moveError`, `moved` calendar options.
- `app/Livewire/ProductionBench/Production/ProductionDetail.php` + `resources/views/livewire/production-bench/production/production-detail.blade.php` — explicit "Change production date" form (accessible fallback).
- `lang/en/production_bench.php` — `calendar.drag_error`, `calendar.move_working_day`, `calendar.move_locked`, `calendar.move_anchor_completed`, `calendar.moved`.
- `database/seeders/data/interface-translations.json` — same five keys (de/es/fr/it/nl), keep JSON valid.
- `tests/Feature/ProductionBenchProductionCalendarTest.php` — access-gate, draggable-flag, and `moveProduction` tests.
- `tests/Feature/ProductionBenchProductionDetailTest.php` (or nearest existing detail-page test file) — fallback form tests.

---

## Task 1: Centralize the write-capability check

**Files:**
- Modify: `app/Services/ProductionBenchAccess.php`
- Test: `tests/Feature/ProductionBenchProductionCalendarTest.php`

`assertWritable()` already bundles the two conditions the UI must mirror: the actor must hold an Owner/Admin/Editor role (`assertCanManage`, throws `AuthorizationException`) and the entitlement must be active (throws `ValidationException` for inactive/cancelled). `isReadOnly()` alone only means "entitlement cancelled" — a viewer in an active workspace would pass `!isReadOnly`. Expose a boolean form of `assertWritable`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ProductionBenchProductionCalendarTest.php` (before the `productionCalendarFixture()` helper):

```php
it('canWrite reflects the role and entitlement the action enforces', function (): void {
    $fixture = productionCalendarFixture();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($viewer)->create([
        'workspace_id' => $fixture['workspace']->id,
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $access = app(ProductionBenchAccess::class);

    expect($access->canWrite($fixture['owner'], $fixture['workspace']))->toBeTrue();

    $fixture['workspace']->productionEntitlement()->update(['status' => 'cancelled']);

    expect($access->canWrite($fixture['owner'], $fixture['workspace']))->toBeFalse()
        ->and($access->canWrite($viewer, $fixture['workspace']))->toBeFalse();
});

it('does not mark events draggable for viewers', function (): void {
    $fixture = productionCalendarFixture();
    $viewer = User::factory()->create();
    WorkspaceMember::factory()->for($viewer)->create([
        'workspace_id' => $fixture['workspace']->id,
        'role' => WorkspaceMemberRole::Viewer,
    ]);

    $component = Livewire::actingAs($viewer)
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01');

    expect(collect($component->instance()->events())->firstWhere('extendedProps.eventType', 'production')['editable'])->toBeFalse();
});
```

Add imports at the top of the test file (after the existing `use` statements):

```php
use App\Models\WorkspaceMember;
use App\Services\ProductionBenchAccess;
use App\WorkspaceMemberRole;
```

Note: `productionCalendarFixture()` already creates an active entitlement (`status => 'active'`), so the owner passes `canWrite`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php`
Expected: 2 failures — `Call to undefined method ProductionBenchAccess::canWrite()` and `editable` key missing.

- [ ] **Step 3: Implement `canWrite`**

In `app/Services/ProductionBenchAccess.php`, after `assertWritable()`:

```php
public function canWrite(User $actor, Workspace $workspace): bool
{
    try {
        $this->assertWritable($actor, $workspace);

        return true;
    } catch (ValidationException | AuthorizationException) {
        return false;
    }
}
```

(`ValidationException` and `AuthorizationException` are already imported in this file.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php`
Expected: the 2 new tests pass (the draggable-flag test from Task 2 will still fail until its step — see Task 2).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ProductionBenchAccess.php tests/Feature/ProductionBenchProductionCalendarTest.php
git commit -m "feat: add ProductionBenchAccess::canWrite for shared write gating"
```

---

## Task 2: Backend — draggable flags and `moveProduction`

**Files:**
- Modify: `app/Livewire/ProductionBench/Production/ProductionCalendar.php`
- Test: `tests/Feature/ProductionBenchProductionCalendarTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ProductionBenchProductionCalendarTest.php` (after the `does not mark events draggable for viewers` test):

```php
it('marks only reschedulable productions as draggable', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Cut and cure',
        'scheduled_for' => '2026-08-21',
    ]);

    $component = Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01');

    $events = collect($component->instance()->events());

    expect($events->firstWhere('extendedProps.eventType', 'production')['editable'])->toBeTrue()
        ->and($events->firstWhere('extendedProps.eventType', 'task')['editable'])->toBeFalse();

    $production->update(['status' => ProductionRunStatus::InProduction]);
    $component->call('setRange', '2026-08-01', '2026-09-01');

    expect(collect($component->instance()->events())->firstWhere('extendedProps.eventType', 'production')['editable'])->toBeFalse();
});

it('moves a production and its automatic tasks through moveProduction', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Anchor',
        'days_after_production' => 0,
        'scheduled_for' => '2026-08-20',
    ]);
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Cure',
        'days_after_production' => 2,
        'scheduled_for' => '2026-08-22',
    ]);
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Manual',
        'days_after_production' => 3,
        'scheduling_mode' => 'custom',
        'scheduled_for' => '2026-08-23',
    ]);
    $completed = ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Done',
        'days_after_production' => 4,
        'scheduled_for' => '2026-08-24',
        'completed_at' => now(),
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->call('moveProduction', $production->public_id, '2026-08-27')
        ->assertReturned(['ok' => true, 'message' => null])
        ->assertDispatched('production-calendar-updated');

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-27')
        ->and($production->fresh()->tasks()->where('name_snapshot', 'Anchor')->value('scheduled_for'))->toBe('2026-08-27')
        ->and($production->fresh()->tasks()->where('name_snapshot', 'Cure')->value('scheduled_for'))->toBe('2026-08-31')
        ->and($production->fresh()->tasks()->where('name_snapshot', 'Manual')->value('scheduled_for'))->toBe('2026-08-23')
        ->and($completed->fresh()->scheduled_for->toDateString())->toBe('2026-08-24');
});

it('rejects a move to a non-working day without changes or dispatch', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];

    $component = Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->call('moveProduction', $production->public_id, '2026-08-16')
        ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['message'] !== null)
        ->assertNotDispatched('production-calendar-updated');

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-20');
});

it('rejects a move for a production already in progress', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];
    $production->update(['status' => ProductionRunStatus::InProduction]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->call('moveProduction', $production->public_id, '2026-08-27')
        ->assertReturned(fn (array $result): bool => $result['ok'] === false && $result['message'] !== null)
        ->assertNotDispatched('production-calendar-updated');

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-20')
        ->and($production->fresh()->status)->toBe(ProductionRunStatus::InProduction);
});

it('rejects a move with a completed anchor task', function (): void {
    $fixture = productionCalendarFixture();
    $production = $fixture['production'];
    ProductionTask::factory()->for($fixture['workspace'])->for($production, 'productionRun')->create([
        'name_snapshot' => 'Anchor',
        'days_after_production' => 0,
        'scheduled_for' => '2026-08-20',
        'completed_at' => now(),
    ]);

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->call('moveProduction', $production->public_id, '2026-08-27')
        ->assertReturned(fn (array $result): bool => $result['ok'] === false)
        ->assertNotDispatched('production-calendar-updated');

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-20');
});

it('rejects a move for an unknown production id', function (): void {
    $fixture = productionCalendarFixture();

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionCalendar::class)
        ->call('setRange', '2026-08-01', '2026-09-01')
        ->call('moveProduction', (string) Str::uuid(), '2026-08-27')
        ->assertReturned(fn (array $result): bool => $result['ok'] === false)
        ->assertNotDispatched('production-calendar-updated');
});
```

Notes on the expectations:
- `productionCalendarFixture()` creates the production on `2026-08-20` (Thursday). Moving to `2026-08-27` (Thursday) shifts the `days_after_production = 2` task to `2026-08-29` (Saturday), which the default workspace (weekends off) snaps forward to `2026-08-31` (Monday). `2026-08-16` is a Sunday (non-working).
- The failure-path tests assert the **complete** contract: `ok === false`, non-empty message, no dispatch, and unchanged state — so a broken error path cannot pass.
- `assertReturned` compares the full returned array, so the success test asserts `['ok' => true, 'message' => null]`.

Add the `Str` import at the top of the test file if not already present:

```php
use Illuminate\Support\Str;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php`
Expected: the 6 new Task 2 tests fail — `moveProduction` does not exist / `editable` key missing. (The 2 Task 1 tests pass by now.)

- [ ] **Step 3: Implement the backend changes**

In `app/Livewire/ProductionBench/Production/ProductionCalendar.php`:

Add the imports:

```php
use App\Actions\Production\RescheduleProduction;
use Illuminate\Auth\Access\AuthorizationException;
```

(`ValidationException` is already imported.)

In `events()`, production loop — add the `editable` key; keep every existing key and the real title expression (which combines `displayIdentifier()` with the recipe name):

```php
$canMove = app(ProductionBenchAccess::class)->canWrite($this->user(), $workspace);

foreach ($productions as $production) {
    $plannedFor = $production->planned_for->toDateString();

    $events[] = [
        'id' => 'production-'.$production->id,
        'title' => trim($production->displayIdentifier().' · '.($production->recipe?->name ?? __('production_bench.production.unknown_product'))),
        'start' => $plannedFor,
        'end' => $production->planned_for->copy()->addDay()->toDateString(),
        'allDay' => true,
        'editable' => $canMove && in_array($production->status, [
            ProductionRunStatus::Scheduled,
            ProductionRunStatus::Reserved,
        ], true),
        'classNames' => ['production-calendar-production', $production->status === ProductionRunStatus::Completed ? 'production-calendar-completed' : ''],
        'extendedProps' => [
            'eventType' => 'production',
            'status' => $production->status->value,
            'publicId' => $production->public_id,
            'url' => route('production-bench.production.show', ['productionRun' => $production->public_id]),
        ],
    ];
}
```

In `events()`, task loop — add `'editable' => false,` after the `'allDay' => true,` line.

Add the new public method after `setRange()`:

```php
/**
 * @return array{ok: bool, message: string|null}
 */
public function moveProduction(string $publicId, string $plannedFor): array
{
    $production = ProductionRun::query()
        ->where('workspace_id', $this->workspace()->id)
        ->where('public_id', $publicId)
        ->first();

    if ($production === null) {
        return ['ok' => false, 'message' => __('production_bench.calendar.drag_error')];
    }

    try {
        app(RescheduleProduction::class)->handle($this->user(), $production, $plannedFor);
    } catch (ValidationException | AuthorizationException $exception) {
        $message = $exception instanceof ValidationException
            ? $this->translateMoveError($exception)
            : __('production_bench.calendar.drag_error');

        return ['ok' => false, 'message' => $message];
    }

    $this->dispatchCalendarUpdate();

    return ['ok' => true, 'message' => null];
}

private function translateMoveError(ValidationException $exception): string
{
    $messages = collect($exception->errors())->map(
        fn (array $messages): string => (string) $messages[0],
    );

    if ($messages->has('planned_for')) {
        return __('production_bench.calendar.move_working_day');
    }

    return match ($messages->first()) {
        'The production date cannot be changed after production starts.' => __('production_bench.calendar.move_locked'),
        'A production with a completed anchor task cannot be rescheduled.' => __('production_bench.calendar.move_anchor_completed'),
        default => $messages->first() ?? __('production_bench.calendar.drag_error'),
    };
}
```

Notes:
- `planned_for` errors from a calendar drag are always working-day rejections (the date format comes from the calendar itself).
- Access failures split into two paths: manager-role users in inactive/cancelled workspaces get a `ValidationException` with the `production_bench` key (message already translated by `ProductionBenchAccess`, passes through the `default` branch); viewers get an `AuthorizationException`, mapped to the generic `drag_error`. Both surface as a red toast with the event reverting.
- The match literals are byte-identical to the messages thrown by `app/Actions/Production/RescheduleProduction.php` (status lock, completed anchor).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php`
Expected: all tests pass (2 from Task 1 + 6 from Task 2 + 5 existing = 13).

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
```

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php`
Expected: still green.

```bash
git add app/Livewire/ProductionBench/Production/ProductionCalendar.php tests/Feature/ProductionBenchProductionCalendarTest.php
git commit -m "feat: allow rescheduling productions from the calendar via Livewire"
```

---

## Task 3: Frontend — drag and drop with the Interaction plugin

**Files:**
- Modify: `resources/js/production-calendar.js`
- Modify: `resources/views/livewire/production-bench/production/production-calendar.blade.php`
- Test: PHP contract covered by Task 2; JS behavior verified manually in Task 6 (and optionally by Task 7)

- [ ] **Step 1: Add the Interaction plugin and the eventDrop handler**

In `resources/js/production-calendar.js`:

Add `Interaction` to the core import (no new package — `@event-calendar/core` 5.12 exports it):

```js
import {
    createCalendar,
    DayGrid,
    Interaction,
    List,
    TimeGrid,
    destroyCalendar,
} from '@event-calendar/core';
```

Add a local date formatter (top level, next to the import):

```js
function toInputDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}
```

Replace the plugin array and editability block, and add `eventDrop` (keep `eventClick` and `datesSet` unchanged):

```js
export function createProductionCalendar(element, options = {}) {
    let moveInFlight = false;

    const calendar = createCalendar(element, [DayGrid, TimeGrid, List, Interaction], {
        ...options,
        editable: options.editable === true,
        eventDurationEditable: false,
        eventDrop: async ({ event, revert }) => {
            const component = element.closest('[wire\\:id]');
            const publicId = event.extendedProps?.publicId;

            if (! component || ! publicId || moveInFlight) {
                revert?.();

                return;
            }

            moveInFlight = true;

            let result;

            try {
                result = await window.Livewire?.find(component.getAttribute('wire:id'))
                    ?.call('moveProduction', publicId, toInputDate(event.start));
            } catch {
                result = null;
            } finally {
                moveInFlight = false;
            }

            if (result?.ok !== true) {
                revert?.();

                window.dispatchEvent(new CustomEvent('app-notification', {
                    detail: {
                        message: result?.message ?? options.moveError ?? 'This production could not be moved.',
                        type: 'error',
                    },
                }));

                return;
            }

            window.dispatchEvent(new CustomEvent('app-notification', {
                detail: {
                    message: options.moved ?? 'Production moved.',
                    type: 'success',
                },
            }));
        },
        eventClick: ({ event }) => {
```

Remove the old `eventStartEditable: false,` line. `info.event` in `eventDrop` is a local-timezone Date (`toEventWithLocalDates` in the library), so `toInputDate` uses local components — `toISOString().slice(0, 10)` would serialize local midnight to the previous day in UTC+ timezones.

- [ ] **Step 2: Pass the flags and strings from the blade**

In `resources/views/livewire/production-bench/production/production-calendar.blade.php`, inside the `data-calendar-options` array (after `'events' => $events,`):

```php
'editable' => ! $isReadOnly,
'moveError' => __('production_bench.calendar.drag_error'),
'moved' => __('production_bench.calendar.moved'),
```

Note: `! $isReadOnly` here only controls whether the master `editable` option is enabled; per-event `editable` flags (Task 2) gate which events are actually draggable, and those flags are computed with `canWrite` so viewers never see draggable events even in an active workspace.

- [ ] **Step 3: Build the assets**

Run: `npm run build`
Expected: Vite build succeeds.

(If the user runs `npm run dev`, HMR picks the change up automatically; `npm run build` keeps production assets in sync.)

- [ ] **Step 4: Commit**

```bash
git add resources/js/production-calendar.js resources/views/livewire/production-bench/production/production-calendar.blade.php
git commit -m "feat: add drag and drop for reschedulable productions on the calendar"
```

---

## Task 4: Translations

**Files:**
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`

- [ ] **Step 1: Add the English keys**

In `lang/en/production_bench.php`, in the `'calendar' => [...]` array (the existing array is not strictly alphabetical — insert the new keys grouped together, after `'completed'`):

```php
'drag_error' => 'This production could not be moved.',
'move_anchor_completed' => 'A production with a completed anchor task cannot be rescheduled.',
'move_locked' => 'The production date cannot be changed after production starts.',
'move_working_day' => 'The production date must be a working day.',
'moved' => 'Production moved; automatic tasks rescheduled.',
```

- [ ] **Step 2: Add the seeded translations**

In `database/seeders/data/interface-translations.json`, insert `calendar.drag_error` after the `calendar.day` block and the `move_*`/`moved` blocks between the `calendar.month` and `calendar.no_events` blocks (the seeder order is semantically irrelevant; what matters is valid JSON and no duplicate keys — same 5 languages as neighbouring keys):

```json
        {
            "group": "production_bench",
            "key": "calendar.drag_error",
            "text": {
                "de": "Diese Produktion konnte nicht verschoben werden.",
                "es": "No se pudo mover esta producción.",
                "fr": "Cette production n’a pas pu être déplacée.",
                "it": "Impossibile spostare questa produzione.",
                "nl": "Deze productie kon niet worden verplaatst."
            }
        },
        {
            "group": "production_bench",
            "key": "calendar.move_anchor_completed",
            "text": {
                "de": "Eine Produktion mit abgeschlossener Ankeraufgabe kann nicht verschoben werden.",
                "es": "No se puede mover una producción con una tarea ancla completada.",
                "fr": "Une production dont la tâche d’ancrage est terminée ne peut pas être déplacée.",
                "it": "Non è possibile spostare una produzione con l’attività di ancoraggio completata.",
                "nl": "Een productie met een voltooide ankerstaak kan niet worden verplaatst."
            }
        },
        {
            "group": "production_bench",
            "key": "calendar.move_locked",
            "text": {
                "de": "Das Produktionsdatum kann nach Produktionsbeginn nicht mehr geändert werden.",
                "es": "La fecha de producción no puede cambiarse una vez iniciada la producción.",
                "fr": "La date de production ne peut plus être modifiée une fois la production commencée.",
                "it": "La data di produzione non può essere modificata dopo l’inizio della produzione.",
                "nl": "De productiedatum kan niet worden gewijzigd nadat de productie is gestart."
            }
        },
        {
            "group": "production_bench",
            "key": "calendar.move_working_day",
            "text": {
                "de": "Das Produktionsdatum muss ein Arbeitstag sein.",
                "es": "La fecha de producción debe ser un día laborable.",
                "fr": "La date de production doit être un jour ouvré.",
                "it": "La data di produzione deve essere un giorno lavorativo.",
                "nl": "De productiedatum moet een werkdag zijn."
            }
        },
        {
            "group": "production_bench",
            "key": "calendar.moved",
            "text": {
                "de": "Produktion verschoben; automatische Aufgaben neu geplant.",
                "es": "Producción movida; tareas automáticas reprogramadas.",
                "fr": "Production déplacée ; tâches automatiques replanifiées.",
                "it": "Produzione spostata; attività automatiche riprogrammate.",
                "nl": "Productie verplaatst; automatische taken opnieuw gepland."
            }
        },
```

- [ ] **Step 3: Validate the JSON and run tests**

Run: `php -r 'json_decode(file_get_contents("database/seeders/data/interface-translations.json")); exit(json_last_error() === JSON_ERROR_NONE ? 0 : 1);'`
Expected: exit code 0.

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php`
Expected: green.

- [ ] **Step 4: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add lang/en/production_bench.php database/seeders/data/interface-translations.json
git commit -m "feat: add calendar drag and drop translations"
```

---

## Task 5: Accessible fallback — explicit "Change production date" on the detail page

**Files:**
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Test: `tests/Feature/ProductionBenchProductionsTest.php` (existing file; mounts `ProductionDetail` with `['productionId' => ...]` and provides the `productionListFixture()` helper)

Context: the detail page currently has `rescheduleTask()` but no way to change the production's own date. The calendar drag is the fast path; this form is the accessible fallback (keyboard, screen reader, touch). Reuse the same action and the same `InteractsWithAppNotifications` pattern already used by `ProductionDetail` (it already uses the trait, `$this->user()`, `$this->production()`, and the `addError` + `showAppNotification` error/success pattern — mirror `cancel()`).

Verified conventions in `ProductionDetail` (do not deviate): the class already imports `ValidationException` and `InteractsWithAppNotifications` and uses the trait; `mount(string|int|ProductionRun $productionId)` sets `public string $productionId`; `production()` is a **private method** (not a property); the Livewire test mounts with `['productionId' => $production->id]` and sets fields with `->set(...)` (plain Livewire — no `fillForm`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ProductionBenchProductionsTest.php` (it already has `productionListFixture()`, whose production is planned on `2026-08-10` and whose workspace entitlement is active — verify the helper's returned keys when implementing):

```php
it('changes the production date through the detail page form', function (): void {
    $fixture = productionListFixture();
    $production = $fixture['production'];

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $production->id])
        ->set('changeDate', '2026-08-27')
        ->call('changeProductionDate')
        ->assertDispatched('app-notification')
        ->assertHasNoErrors();

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-27');
});

it('rejects a non-working day on the detail page form', function (): void {
    $fixture = productionListFixture();
    $production = $fixture['production'];

    Livewire::actingAs($fixture['owner'])
        ->test(ProductionDetail::class, ['productionId' => $production->id])
        ->set('changeDate', '2026-08-16')
        ->call('changeProductionDate')
        ->assertHasErrors('changeDate')
        ->assertNotDispatched('app-notification');

    expect($production->fresh()->planned_for->toDateString())->toBe('2026-08-10');
});
```

Notes: `2026-08-27` is a Thursday (working day); `2026-08-16` is a Sunday (rejected); the fixture production stays on `2026-08-10` in the rejection test. If `productionListFixture()`'s workspace entitlement is not active in that helper, add the active entitlement creation to the tests (same pattern as `productionCalendarFixture()`).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php`
Expected: the 2 new tests fail — `changeProductionDate` does not exist.

- [ ] **Step 3: Implement the form**

In `app/Livewire/ProductionBench/Production/ProductionDetail.php`:

Add the single new import:

```php
use App\Actions\Production\RescheduleProduction;
```

(`InteractsWithAppNotifications` and `ValidationException` are already imported; the trait is already used.)

Add the form property next to the other public properties:

```php
public ?string $changeDate = null;
```

Add the method next to `cancel()` (mirror its error-mapping pattern, which maps the `production`/`planned_for`/`production_bench` keys onto the form field):

```php
public function changeProductionDate(RescheduleProduction $rescheduleProduction): void
{
    try {
        $rescheduleProduction->handle(
            actor: $this->user(),
            production: $this->production(),
            plannedFor: $this->changeDate,
        );
    } catch (ValidationException $exception) {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError(in_array($field, ['production', 'planned_for'], true) ? 'changeDate' : $field, $message);
            }
        }

        return;
    }

    $this->showAppNotification(__('production_bench.calendar.moved'));
}
```

In `resources/views/livewire/production-bench/production/production-detail.blade.php`, add a small form near the planned-date display, using the page's existing input/button classes:

```blade
@if (in_array($production->status->value, ['draft', 'scheduled', 'reserved'], true))
    <form wire:submit="changeProductionDate" class="flex items-end gap-3">
        <div>
            <label for="changeDate" class="mb-1 block text-sm font-medium text-[var(--color-ink-strong)]">
                {{ __('production_bench.calendar.day') }}
            </label>
            <input
                id="changeDate"
                type="date"
                wire:model="changeDate"
                @error('changeDate') aria-invalid="true" @enderror
                class="rounded-xl border border-[var(--color-line-strong)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)]"
            >
            @error('changeDate')
                <p class="mt-1 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="sk-btn sk-btn-secondary">{{ __('production_bench.calendar.moved') }}</button>
    </form>
@endif
```

Adjust the button label to a dedicated `production.change_date` string if the detail blade does not already have one — do not reuse `calendar.moved` (it is a status message, not a label). Add the label key to `lang/en/production_bench.php` (`'change_date' => 'Change production date'`) and to the JSON seeder (`calendar` group is wrong — use the `production` group where the detail page strings live; check the existing detail-page keys in the JSON seeder first and mirror them). The status values come from `App\ProductionRunStatus` (`draft`/`scheduled`/`reserved`).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionsTest.php`
Expected: both new tests pass and the existing detail tests stay green.

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/ProductionBench/Production/ProductionDetail.php resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ProductionBenchProductionsTest.php lang/en/production_bench.php database/seeders/data/interface-translations.json
git commit -m "feat: add explicit change-production-date form on the detail page"
```

---

## Task 6: End-to-end verification

**Files:**
- None (verification only)

- [ ] **Step 1: Run the full test suite for the touched areas**

Run: `php artisan test --compact tests/Feature/ProductionBenchProductionCalendarTest.php tests/Feature/ProductionTaskSchedulingTest.php tests/Feature/ProductionBenchProductionsTest.php`
Expected: all tests pass (the scheduling suite proves the action cascade stays intact).

- [ ] **Step 2: Manual browser verification**

Open `http://koskalk.test/dashboard/production-bench/production/calendar` (hard refresh, Vite dev running):

1. **Drag on a working day (timezone check)**: drag a `scheduled` production onto **Aug 27** and drop. The task events visibly shift (2 days later per `days_after_production`, skipping the weekend). Reload the page — the production is on **Aug 27** (not Aug 26; a one-day-earlier result means the local-date conversion regressed).
2. **Revert on non-working day**: drag a production onto a Sunday and drop. It snaps back and a red toast appears ("The production date must be a working day.").
3. **Success toast**: repeat step 1 — a green toast appears ("Production moved; automatic tasks rescheduled.").
4. **Tasks not draggable**: try dragging a task event — it does not move.
5. **No navigation after a drop**: after the drop in step 1, the URL stays on the calendar (a drop must not trigger `eventClick` navigation).
6. **Read-only and viewer**: log in as a viewer member (or use a cancelled-entitlement workspace) — no event is draggable at all.
7. **Click to open**: click a production event → production detail page; click a task event → the SAME production's detail page (task opens its production). If a click near the bottom-left does nothing, the PHP Debugbar badge is covering the event — close/dock Debugbar and retry (dev-only artifact, not an app bug).
8. **Month/Week/Day still work**: prev/next, Today, and the Month/Week/Day/Agenda buttons behave as before.
9. **Tablet/touch (if available)**: long-press a production event on a touch device — it lifts and can be dragged; the detail-page form in Task 5 is the reliable touch fallback.
10. **Detail-page fallback**: on the production detail page, change the date via the form and confirm the toast + task shift.

- [ ] **Step 3: Run graphify and finish**

Run: `graphify update .`
Expected: graph rebuilt with the modified files.

---

## Task 7 (optional, requires approval): JS contract test for the date conversion

**Files:**
- Modify: `package.json` (add vitest dev dependency)
- Create: `resources/js/production-calendar.test.js` (or `tests/js/` per project convention)
- Modify: `package.json` scripts (`"test:js": "vitest run"`)

This guards the timezone regression class from the review: the drop date must be derived from local components, never UTC serialization.

- [ ] **Step 1: Get approval for the dev dependency**

Per project rules, dependencies require approval. If approved:

```bash
npm install --save-dev vitest --no-audit --no-fund
```

- [ ] **Step 2: Export the helper and write the test**

Export `toInputDate` from `resources/js/production-calendar.js` (add `export` to the existing function), then create the test:

```js
import { describe, expect, it } from 'vitest';
import { toInputDate } from './production-calendar';

describe('toInputDate', () => {
    it('formats a local date from its local components', () => {
        expect(toInputDate(new Date(2026, 7, 27, 0, 0, 0))).toBe('2026-08-27');
        expect(toInputDate(new Date(2026, 0, 5, 12, 30, 0))).toBe('2026-01-05');
    });
});
```

Run: `npm run test:js`
Expected: passes — and would have failed with `toISOString().slice(0, 10)` on any UTC+ timezone machine.

- [ ] **Step 3: Commit**

```bash
git add package.json package-lock.json resources/js/production-calendar.js resources/js/production-calendar.test.js
git commit -m "test: add local-date conversion contract test for calendar drag"
```

---

## Self-review

- **Spec coverage:** drag productions (Task 3 + Task 2 backend); tasks follow via the existing action (Task 2 test asserts cascade + working-day snap); tasks not draggable (Task 2 test); click opens the related production (already shipped; verified in Task 6 step 7); accessible form fallback (Task 5); timezone-safe date conversion (Task 3 `toInputDate`, guarded by Task 7); shared write gating (Task 1); translated failures (Task 2 + Task 4); design-doc supersede note (header).
- **Reviewer items addressed:** 1 timezone → `toInputDate`; 2 Interaction from core, Task 1 removed; 3 `canWrite` shared gate; 4 `collect()` around `events()` (including the Task 1 viewer test); 5 full `assertReturned` value / callable form; 6 rejection tests assert `ok === false` + no dispatch + additional cases (viewer, unknown id, completed anchor); 7 detail-page fallback form; 8 supersede section + preserved form; 9 translated failure mapping. Smaller items: in-flight drag guard, success toast, drop-does-not-navigate check, touch verification.
- **Code-review pass:** the second review verified every snippet against the codebase — `canWrite` exception coverage, byte-identical match literals, date arithmetic, `Interaction` export, local-date conversion, Livewire `call()` promise contract, `assertReturned`/`assertNotDispatched`/`assertHasErrors` availability, notification contract, and the Task 5 conventions (mount key `productionId`, `production()` method accessor, `set()` instead of `fillForm`, existing imports/trait, `productionListFixture()`). Its three Important findings (Task 1 viewer test array call, Task 2 title expression, Task 5 shape) and the Minor notes (test counts, JSON placement) are fixed in this revision.
- **Placeholder scan:** no TBD/TODO; every code step shows the exact diff. The two places where an implementer must confirm reality first are explicit verification steps (Task 5 fixture helper keys/entitlement, and the label-key location in the JSON seeder) — not placeholders.
- **Type consistency:** `moveProduction(string $publicId, string $plannedFor): array` and return shape `{ok: bool, message: string|null}` are identical in PHP, Pest, and JS (`result?.ok !== true`, `result?.message`). `canWrite(User, Workspace): bool` used by `events()` and referenced in Task 5. Translation keys `calendar.drag_error`, `calendar.move_working_day`, `calendar.move_locked`, `calendar.move_anchor_completed`, `calendar.moved` match across PHP, blade options (`moveError`, `moved`), and the JSON seeder.
