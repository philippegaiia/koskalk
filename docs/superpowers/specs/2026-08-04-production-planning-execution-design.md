# Production Planning and Execution Design

## Goal

Deliver the next two Production Bench checkpoints as a product-first workflow that a small maker can understand without production-management training:

1. plan one or several production batches, generate useful follow-up tasks, see material demand, and deliberately reserve stock;
2. execute a batch, record the lots and quantities actually used, create the output, and preserve its actual historical cost.

The routine path should remain:

`Choose product → choose or enter batch size → choose date → schedule`

Formula lines, packaging requirements, task suggestions, stock forecasts, and lot proposals are derived from existing records. Automation prepares proposals; it does not commit inventory changes without confirmation.

## Relationship to the Production Bench Design

This specification extends the approved [Production Bench design](2026-07-28-production-bench-design.md) for Checkpoints 3 and 4.

It deliberately changes two earlier decisions:

- The earlier **No Mold Model** exclusion is replaced by optional product-level batch-size presets. Koskalk does not model physical moulds or equipment, but a maker may save a practical pairing such as `12 kg oils → 100 units` and choose one preset as the default.
- The earlier Flash Planner remains non-persistent while simulating, but gains an explicit **Generate productions** action. Generation occurs only after a dated batch preview is reviewed and confirmed. Flash planning therefore moves into Checkpoint 3 instead of waiting for Checkpoint 5.

The feature still excludes production waves, production lines, equipment calendars, capacity resources, and ERP scheduling.

## Product Language and Principle

The internal operational model remains `ProductionRun`. The customer-facing term is **Production** or **Production batch**.

Customer-facing statuses are:

`Draft → Planned → Stock prepared → In production → Completed`

These correspond to the durable internal lifecycle:

`Draft → Scheduled → Reserved → In production → Completed`

Cancelled and Aborted remain explicit terminal alternatives. Output quarantine and release remain stock-lot states rather than production statuses.

The product should optimize the common case without hiding control:

- one familiar production can be planned in well under one minute;
- derived formula and packaging data are visible but not editable in the planning form;
- every inventory mutation has a preview and explicit confirmation;
- advanced setup is kept in reusable presets and task sets, outside the routine production form.

### Production run identities

Every saved `ProductionRun` receives a workspace-unique, immutable planning reference immediately (for example,
`T00001`). A workspace may later assign an immutable permanent batch number (for example, `B-00001-FR`) individually
or in bulk before production starts. The permanent number is shown in place of the planning reference once assigned;
otherwise the planning reference remains the production's visible identity. List, detail, and calendar views use this
same fallback rule. These identifiers belong to Production Bench runs and are independent of the Basic
`production_batches` feature and its retention rules.

## Checkpoint 3: Planning, Tasks, Calendar, Flash, and Reservations

### Outcome

A maker can plan a production from a published product, optionally generate several productions through Flash, see all productions and tasks in list or calendar form, review combined material requirements in Inventory, and reserve one or several productions against specific stock items.

Planning creates forecast demand only. It never changes physical or available stock.

### Optional Setup

Checkpoint 3 adds four small reusable setup areas.

#### Batch-size presets

A product may have zero or more presets. Each preset contains:

- name;
- oil mass for soap or total formula mass for cosmetics;
- expected finished units;
- optional default marker;
- active/inactive state.

Examples are `12 kg / 100 units` and `26 kg / 288 units`.

A preset is a convenience, not a constraint. Selecting it prefills the production basis and expected units, and the maker may change either value for that production. A product without presets remains fully usable through manual entry.

Koskalk does not store mould identities, mould counts, or equipment capacity.

#### Employees

Employees are lightweight workspace records, not login accounts. An employee contains:

- first name;
- last name;
- active/inactive state.

A production task may have one optional employee assignment. Deactivation preserves historical assignments. Permissions, payroll, attendance, shifts, and employee accounts are outside these checkpoints.

The nullable assignment is intentionally present now so task history does not require a later structural rewrite.

#### Task types and task sets

A **task type** is a reusable kind of work. It contains:

- name;
- optional default duration in minutes;
- optional display colour selected from a small accessible palette;
- active/inactive state.

A **task set** is a named, ordered collection of task types. Each task-set item contains:

- task type;
- sort order;
- signed calendar-day offset relative to production;
- optional duration override.

The relative day belongs to the task-set item, not the task type, because the same kind of work may occur at a different delay in another process. The offset is calendar time: `-1` means the previous calendar day, `0` means production day, and `+28` means twenty-eight calendar days later. When the resulting task date is not a working day, preparation tasks move backward to the previous working day and follow-up tasks move forward to the next working day.

At least one task-set item must have offset `0`; this is the production-day anchor. The first chronological item may instead be a preparation task at `-1` or earlier. Its generated date is still derived from the production date, so adding preparation does not redefine the date on which the batch is made.

Task sets are reusable across products. A product may be linked to many applicable task sets, with at most one marked as its default. When the product is selected, the applicable task sets are offered and the default is preselected; the maker may choose another applicable set or no task set for the individual production.

Checkpoint 3 uses one task-set representation only. It does not reproduce Cosmood's legacy dual template structures, task dependencies, bypass reasons, capacity flags, or production-line associations.

#### Working calendar

Workspace settings define whether weekends are treated as non-working days. Holidays contain:

- name;
- date;
- recurring yes/no.

A recurring holiday matches the same month and day each year.

The production date is the date on which the batch is made. When weekends or holidays are configured as non-working, the production date must be a working day; Koskalk does not silently move an explicit production date. If weekends are configured as working days, no weekend adjustment is applied.

Automatically generated task dates use calendar-day arithmetic first. A negative offset that lands on a non-working day moves backward to the nearest working day; a positive offset moves forward to the nearest working day. Offset `0` always equals the production date.

### Planning One Production

The creation form is one clear page, not a wizard or a multi-tab ERP form.

The maker enters:

- product;
- optional batch-size preset;
- initial oil quantity for soap or total quantity for cosmetics;
- expected finished units;
- production date;
- optional task set;
- optional notes.

Selecting the product loads its latest accessible published formula version, formula lines, packaging, default preset, and applicable task sets with the product default preselected. The chosen published version is read once to build the production's owned formula snapshot; the production does not permanently depend on the version afterwards. See the [Production Run Formula Snapshot Independence Design](2026-08-06-production-run-formula-snapshot-independence-design.md), which supersedes the earlier permanent-version-pin wording in this section.

The form shows a compact read-only preview of:

- scaled ingredients and percentages;
- packaging calculated from expected units;
- required quantities in canonical stock units;
- current available and incoming stock;
- clear shortages;
- generated task dates.

The maker may plan despite shortages. Saving creates the production, its snapshotted requirements, and its generated tasks atomically. It does not create reservations.

### Generated Production Tasks

Generated tasks snapshot the task name, display colour, signed calendar-day offset, duration, and scheduled date used by that production. Later edits to a reusable task set do not silently rewrite existing productions.

The production-day anchor task and the production date are synchronized before production starts:

- changing the production date recalculates every unfinished automatically scheduled task from its stored offset;
- the anchor task remains exactly on the production date;
- the anchor task cannot hold an independent custom date;
- preparation tasks may therefore appear before the production date without changing the production date itself.

When the production date changes:

- unfinished automatically scheduled tasks are recalculated from their stored calendar offsets and the working-calendar snap rule;
- completed tasks are never moved;
- tasks with a custom date are never moved.

Once production has started, changing the production-date anchor is no longer a planning reschedule and is handled through the execution record rather than silently moving tasks.

A maker may change the date of any later task. The task becomes visibly marked **Custom date**. A **Reset to automatic date** action reconnects it to the production date and task-set offset.

Tasks may be assigned to an active employee, completed, reopened when permitted, and viewed from their production. Employee assignment does not change scheduling.

### Production List and Calendar

The Productions area has two first-class views:

- **List**: the default operational view with search, status, date, product, stock-preparation state, and bulk actions;
- **Calendar**: visual planning with Month, Week, and Agenda views.

The calendar uses `@event-calendar/core` directly through the existing Vite, Blade, and Livewire stack. Koskalk does not install the Filament-specific Guava wrapper.

Calendar rules are:

- production events are visually stronger than task events;
- Month and date-grid Week are available on desktop and tablet;
- Agenda uses the list view and is preferred on narrow screens;
- filters are limited to Productions, Tasks, and Completed;
- clicking an event opens its production;
- dates are edited through explicit forms;
- drag-and-drop is excluded initially to prevent accidental rescheduling.

An hourly time grid is excluded because these checkpoints schedule dates, not working hours.

### Flash Simulator and Production Generation

Flash is a prominent production-planning entry point, not a hidden report.

Each simulation line contains:

- product;
- desired finished units;
- optional batch-size preset;
- when no preset is used, the production basis and expected units per batch.

The live, non-persistent simulation calculates:

- whole batches required;
- units requested, expected, and extra;
- total oil mass or total formula mass;
- aggregated ingredient requirements;
- aggregated packaging requirements;
- current material prices and an estimated material budget;
- missing-price or mixed-currency notices when a complete combined budget cannot be shown;
- no available, incoming, forecast, shortage, reservation, or stock calculation;
- optional task-duration totals when durations are configured.

The estimated budget uses the workspace's current material-price projections and their recorded currencies. It does not use or modify immutable received-lot costs; a combined total is shown only when all selected prices use the same currency.

The simulation has no stock effect.

**Generate productions** opens a second, explicit preview. The maker supplies:

- first proposed production date;
- a temporary number of batches per working day;
- optional notes.

Koskalk proposes one dated production row per whole batch, moves automatic proposed dates around configured weekends and holidays, and shows all generated dates before persistence. Every proposed production date remains editable. The temporary batches-per-day value is a generation aid and does not create a production line or persistent capacity model.

Confirmation atomically creates independent Planned productions with their published formula snapshots, requirements, and generated tasks. It creates neither a wave nor reservations. If confirmation fails, no production is created.

### Forecast and Stock Preparation

Planned production requirements contribute to forecast demand but do not reduce available stock.

Stock preparation is explicit and works for one or several selected productions:

1. select productions;
2. choose **Prepare stock**;
3. review proposed lots, quantities, shortages, and conflicts;
4. change proposed lots or quantities when needed;
5. confirm.

Nothing is reserved before confirmation.

For each ingredient or packaging requirement, Koskalk proposes released lots by earliest usable expiry, then receipt age and internal lot code. A proposal may consume the remainder of one lot and continue from one or more later lots until the requirement is covered.

The production requirement remains one line. It is never duplicated to represent a second lot. Separate stock-reservation records connect that requirement to as many lots as necessary and hold the quantity and reservation state for each connection.

Example:

`Olive oil required: 12 kg`

- `SK-001: 2.4 kg`
- `SK-014: 9.6 kg`

The bulk confirmation is accepted only when every selected production is fully covered. The preview lets the maker remove an ineligible production and clearly identifies the remaining shortage.

Confirmation is transactional, locks the affected lots, rechecks current availability, rejects cross-workspace references and concurrent conflicts, and then creates the lot-specific reservations. Reservations reduce available stock but not physical stock.

Before production starts, a maker may release prepared stock or recalculate the proposal. Both actions are explicit and auditable.

## Checkpoint 4: Production Execution, Output, and Actual Cost

### Outcome

A maker can start a prepared production, record what was actually consumed from each lot, complete or abort it safely, create its output lot, and preserve the real material cost and traceability.

### Starting Production

**Start production** is explicit.

If the production is Planned rather than Stock prepared, the same stock-preparation preview opens first. Starting cannot silently allocate stock.

Starting freezes the operational plan used at the bench. The published formula version and planned requirements cannot be replaced after this point.

### Execution Workspace

The execution page is organized around the maker's work rather than database entities:

1. production identity and date;
2. formula and instructions;
3. ingredients to weigh;
4. packaging to use;
5. generated tasks;
6. journal, observations, and private attachments;
7. completion summary.

Each material requirement remains one visible row and expands to show its lots. For each lot the maker records the actual quantity consumed. The maker may:

- use less or more than reserved;
- empty the remainder of one lot and continue with another;
- replace a proposed lot when allowed;
- add another valid lot;
- leave unused reserved quantity to be released at completion.

Actual consumption may exceed the reservation and may expose a negative stock balance, as already allowed by the durable Production Bench design. The interface must make this exceptional outcome explicit before confirmation.

### Completion

Completion requires:

- actual ingredient quantities by lot;
- actual packaging quantities by lot when applicable;
- actual finished integer units or actual intermediate output mass;
- manufacture/completion date;
- output lot information;
- any output-specific required information.

Completion atomically:

1. validates and locks the production, reservations, and affected lots;
2. posts immutable consumption movements per actual lot;
3. releases unused reservations;
4. snapshots each consumed lot's historical acquisition cost and line cost;
5. stores an immutable Production Bench completion snapshot for the consumed lots and costs;
6. creates one internal output lot;
7. posts the production-output movement;
8. stores the actual output and material-cost totals;
9. marks the production Completed.

Either every step succeeds or none succeeds. Later supplier-listing, exchange-rate, current ingredient-price, or formula changes cannot alter the completed cost.

The Production Bench completion snapshot keeps one ingredient or packaging line for each formula requirement. When that
requirement consumed several internal lots, child lot-cost snapshots preserve each lot, actual quantity, historical unit
cost, and line cost. It retains complete lot traceability without duplicating the visible formula line. It is not a link
to Basic `production_batches`.

The output lot follows the existing quarantine, curing/availability-date, and release design. A manufactured intermediate carries its actual cost into downstream production.

### Abort and Corrections

A Draft, Planned, or Stock-prepared production may be cancelled. Cancelling a Stock-prepared production releases its active reservations.

An In-production batch cannot be deleted or casually cancelled. **Abort production** requires the maker to record what was consumed, returned, or lost. The action posts the corresponding movements and releases unused reservations atomically.

Completed production and its historical cost are immutable. Later physical corrections use compensating stock adjustments rather than rewriting the completed record.

## Data Shape

The detailed implementation plan may adjust names to existing conventions, but the domain relationships are fixed:

- a ProductionRun belongs to one workspace, product/recipe, and published recipe version;
- a ProductionRun has many snapshotted material requirements;
- one requirement belongs to one ingredient or packaging item;
- one requirement has many stock reservations;
- each stock reservation belongs to one internal stock lot;
- one requirement has many actual lot consumptions after execution;
- one Production Bench completion line may have many immutable lot-cost snapshots;
- a ProductionRun has many generated production tasks;
- a production task may belong to one employee;
- a product may have many optional batch-size presets and many applicable task sets, with at most one default;
- a task set may apply to many products through a workspace-safe applicability relation;
- a task set has many ordered task-set items;
- a task-set item belongs to one task type;
- a workspace has many holidays and employees.

Employee, task, preset, production, requirement, reservation, and holiday references must be workspace-safe. Records used by history are deactivated or retained rather than destructively removed.

## Simplicity Boundaries

These checkpoints do not add:

- production waves;
- production lines;
- equipment or mould records;
- persistent daily-capacity resources;
- hour-by-hour employee scheduling;
- task dependencies or dependency bypasses;
- payroll, shifts, attendance, or user accounts for employees;
- automatic reservation on scheduling;
- automatic purchase-order creation;
- drag-and-drop calendar mutation;
- labor, energy, overhead, tax, freight, or duty allocation into production cost;
- configurable laboratory or QC workflows.

Shortages may link the maker to purchasing, but they do not create orders automatically.

## Integrity Rules

- Only accessible published formula versions can be planned.
- Formula and packaging requirements are scaled from canonical quantities and the expected-units value.
- Planning and Flash simulation never mutate stock.
- Flash generation is idempotent against duplicate submission.
- Generated productions are independent records; there is no hidden wave aggregate.
- Task-date recalculation never moves completed or custom-dated tasks.
- The production-day anchor remains synchronized with the production date.
- Each applicable task set contains at least one anchor item at offset `0`; the earliest chronological task may be preparation at a negative offset.
- Task dates use calendar offsets and snap backward for negative offsets or forward for positive offsets when configured non-working days intervene.
- Explicit production dates must be working days when the calendar says weekends/holidays are non-working.
- Reservation confirmation is explicit, lot-specific, transactionally locked, and rejects over-reservation.
- Multiple reservations may satisfy one requirement; duplicate requirement lines are not used for lot splitting.
- Starting an unprepared production requires the same visible stock-preparation confirmation.
- Completion and abort are atomic.
- Completed quantities, lot costs, movements, and historical production costs are immutable.
- Cross-workspace references are rejected.
- A cancelled or read-only Production Bench blocks every mutation while preserving history.

## Review Scenarios

### Checkpoint 3 review

The review demonstrates:

- a soap product with `12 kg / 100 units` and `26 kg / 288 units` presets;
- a product with no preset and manual quantity entry;
- a reusable task set linked to several products, with one product default and another applicable alternative;
- preparation at `-1`, production at `0`, and curing at `+28`, with dates around a weekend and recurring holiday;
- the production-day anchor remaining exactly synchronized with the production date while preparation appears earlier;
- moving the production date and automatically rescheduling unfinished tasks;
- manually changing one task date and assigning an employee;
- List, Month, Week, and Agenda production views;
- Flash simulation of several products with whole-batch rounding, extra units, aggregate material requirements, packaging, and task time; stock coverage is reviewed separately in Inventory → Requirements;
- review and confirmation of Flash-generated production dates;
- planning with shortages without reserving stock;
- bulk stock preparation;
- one ingredient requirement split across the remainder of one lot and a second lot;
- correct Physical, Available, Incoming, and Forecast stock.

### Checkpoint 4 review

The review continues with one prepared production and demonstrates:

- explicit start;
- actual consumption from two lots for the same ingredient;
- changed actual quantities and unused reservation release;
- packaging consumption;
- private production notes and documents;
- atomic completion;
- one output lot and immutable Production Bench completion snapshot;
- actual historical material cost from the consumed receipt lots;
- output quarantine and release;
- an abort with consumed, returned, and lost material reconciliation;
- immutable completed history followed by a compensating stock correction.

## Delivery Order

Checkpoint 3 should be implemented in coherent slices:

1. schema and lifecycle for productions and requirements;
2. batch-size presets and the simple production form;
3. employees, task types, task sets, holidays, and task scheduling;
4. production List, Month, Week, and Agenda views using EventCalendar;
5. forecast integration;
6. lot-proposal and individual reservation workflow;
7. bulk stock preparation;
8. Flash simulation and reviewed production generation;
9. responsive, translation, accessibility, concurrency, and duplicate-submission verification.

Checkpoint 4 should follow with:

1. start lifecycle and frozen operational plan;
2. actual lot-consumption workspace;
3. packaging actuals, journal, and documents;
4. atomic completion and Production Bench completion snapshot integration;
5. output lot, quarantine, availability, and release;
6. actual costing and intermediate cost propagation;
7. abort and compensating correction workflows;
8. responsive, translation, accessibility, production build, and complete regression verification.
