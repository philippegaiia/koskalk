# Production Run Lifecycle and Page Redesign

**Date:** 2026-08-10
**Status:** Approved design
**Design authority:** This document amends the Production Run Model, Production Detail, Output Quarantine and Release, and deletion behavior in [Production Bench Design](2026-07-28-production-bench-design.md).

## Goal

Make the production lifecycle understandable without training, remove duplicated controls from the production detail page, present one coherent batch-material view, track calculated lye as inventory, and harden deletion and release behavior at the application and database boundaries.

## Terminology

Customer-facing and internal statuses intentionally use different labels:

| Internal status | Customer label |
| --- | --- |
| `draft` | Draft |
| `scheduled` | Planned |
| `reserved` | Stock prepared |
| `in_production` | In production |
| `completed` | Completed |
| `cancelled` | Cancelled |
| `aborted` | Aborted |

There is no separate **Allocated** state. A lot-specific hard reservation already makes stock unavailable to every other production. Starting a production does not change that stock fact.

**Completed** describes the production run. **Released** describes the output lot. A production may be Completed while its output remains quarantined.

## Production Lifecycle

The primary lifecycle remains:

`Draft → Planned → Stock prepared → In production → Completed`

Terminal alternatives remain:

- `Draft / Planned / Stock prepared → Cancelled`
- `In production → Aborted`

Output availability follows a separate lifecycle:

`Quarantined → Released`

### Draft and planning

**Save as draft** creates a Draft run without a production date. It creates the immutable planning/formula snapshot and material requirements, but it generates no scheduled tasks and has no stock effect.

**Plan production** creates a Planned run and requires a production date. The date requirement is enforced in the Livewire form and the application action so no alternate caller can create a Planned run without a date.

When a run is created directly as Planned, the lifecycle landmark renders Draft as a completed green step and Planned as the current step.

### Stock preparation

Preparing stock creates lot-specific hard reservations:

- partial coverage leaves the run Planned;
- complete coverage moves the run to Stock prepared;
- releasing active reservations returns a Stock prepared run to Planned;
- releasing reservations from a partially covered Planned run leaves it Planned.

Reserved stock reduces available stock but not physical stock.

### Starting production

Starting remains a deliberate operator action. It requires:

- Stock prepared status;
- full active reservation coverage;
- an assigned permanent batch number.

Starting before the planned date is allowed, but the UI requires explicit confirmation. Starting on or after the planned date proceeds normally. A late production can always be started manually; the planned date never automatically starts or cancels a run.

Starting changes only the production status and audit fields. Reservations remain active until actual consumption is posted, the run is aborted, or stock is explicitly released.

### Actual usage, completion, and abort

When production starts, actual-use inputs default to the reserved lots and reserved quantities. The operator edits only exceptions. Water defaults to its planned calculated quantity because it is not stock-tracked.

Completion posts actual consumption movements, releases unused active reservations, creates the output lot, snapshots cost, and moves the run to Completed atomically.

Planned output and actual output remain distinct. For finished products, the operator enters the actual integer units produced at completion; producing 283 units from a plan of 288 is valid. The production page displays planned output, actual output, and their signed quantity/percentage variance. Manufactured intermediates use the equivalent planned-versus-actual mass comparison. Output variance is a factual yield result and is not automatically classified as waste.

Abort posts any recorded actual consumption, releases the remainder, records the reason, and moves the run to Aborted atomically.

### Cancellation and deletion

Cancel is available for Draft, Planned, and Stock prepared runs. It deactivates active reservations, restores their quantities to available stock, records the cancellation reason, and moves the run to the permanent Cancelled status. Cancelled runs remain read-only history and cannot be deleted.

Delete is available only for Draft or Planned runs without active reservations. A Stock prepared run must release stock and return to Planned before deletion. If a permanent batch number was assigned, deletion burns it: the number counter never moves backwards and the number is never reused.

The intended delete sequence is **Release stock → Delete**, never **Cancel → Delete**.

## Output Quarantine and Batch Release

Completing production creates one physically present but quarantined output lot. Production remains Completed while that output is unavailable.

Batch release requires both:

1. the output lot's `available_from` date has arrived;
2. every task belonging to the production is completed.

Production may be completed while tasks remain pending. This supports curing, checks, and follow-up work after manufacturing is closed. Runs without tasks satisfy the task condition automatically.

The ready date remains automatic:

- when tasks exist, use the last scheduled task date;
- without tasks, use the family delay: 21 days for soap or 3 days for cosmetics.

Releasing the output changes the lot from Quarantined to Released. It does not change the production's Completed status.

## Planned Material Model

### One manufactured formula

The complete manufactured formula remains the primary display source. It includes explicit ingredients plus calculated NaOH, KOH, and water lines. Material requirements, reservations, actual usage, and costing attach to those displayed formula materials rather than being presented as a competing second list.

Packaging remains part of the same batch-material workspace in a separate group.

### NaOH and KOH

Calculated NaOH and KOH become real production requirements for newly created soap runs. They participate in:

- stock availability and shortage calculations;
- lot selection and hard reservations;
- actual consumption;
- historical costing and traceability.

The resolver uses stable platform catalog identities rather than translated names:

- `CH1` for NaOH/Lye;
- `CH3` for KOH/Caustic potash.

Calculated lye formula lines store the resolved ingredient identity, and matching requirements use the calculated mass.

### Water

Water remains a calculated, non-stock-tracked formula line. It appears in the batch-material table with its percentage, planned mass, and editable/defaulted actual usage, but it has no lot reservation or inventory movement.

Because water has no stock lot, its actual quantity cannot use the lot-backed `production_consumption` records. Add a nullable `actual_mass_grams` field to `production_formula_lines` for non-stock calculated material actuals. It defaults from `planned_mass_grams` when production starts, remains editable while In production, and becomes immutable when the run is Completed or Aborted. Planned formula fields remain immutable. Completion requires a positive water actual when a water line exists.

### Existing runs

Existing runs are not backfilled with lye requirements. This deployment contains only test runs, which may be removed by the owner. New runs use the revised material model. In-production and terminal history is never rewritten.

### Material substitution

Replacing one planned ingredient with another is explicitly deferred. A future **Substitute material** workflow must preserve the original material, replacement material and lot, quantity, reason, operator, costing, and permanent batch deviation history. This redesign leaves room for a future row action but adds no inactive or misleading control.

## Production Detail Page

The selected layout is a workflow-first single page.

### Header and lifecycle landmark

The top of the page contains one compact identity block:

- permanent batch number when assigned, otherwise planning reference;
- product name;
- planned date;
- batch basis/size;
- one horizontal lifecycle landmark;
- exactly one primary next action.

The page removes repeated status badges, duplicate identity cards, and duplicate Prepare, Assign number, Start, and Complete actions. The lifecycle landmark uses completed, current, and upcoming visual states. Cancelled and Aborted appear as terminal outcomes without pretending they are forward progress steps.

The horizontal landmark is optimized for desktop/tablet and collapses into a compact vertical or wrapped presentation on narrow screens without reducing the material table's usable width.

### Primary and secondary actions

The primary action is determined by status:

| Current condition | Primary action |
| --- | --- |
| Draft | Schedule |
| Planned | Prepare stock |
| Stock prepared without permanent number | Assign batch number |
| Stock prepared with permanent number | Start production |
| In production | Complete production |
| Completed with quarantined output | Release batch when eligible |

Release stock is a contextual secondary action. Cancel and Abort remain visually separated danger actions. Delete remains on the production index for eligible Draft/Planned runs.

### Unified batch-material table

Formula and requirements are rendered as one table, grouped by formula phase with packaging as an additional group.

| Column | Content |
| --- | --- |
| Material | Name, formula percentage inline, and optional note |
| Planned | Planned mass or packaging units |
| Reserved lot(s) | Reserved total plus compact lot-code/quantity entries; non-stock materials say `Not stock tracked` |
| Actual used | Hidden/read-only before start, prefilled and editable in production, immutable after posting |

Each material occupies one primary row. Multiple reserved lots remain compact entries inside the Reserved and Actual cells instead of duplicating the material heading. Mobile presentation may stack the same row fields, but it uses the same prepared row data.

A dedicated production-detail presenter/view-data builder joins formula lines, requirements, active reservations, stock lots, and actual consumption. Blade renders prepared rows and does not own coverage arithmetic or cross-record matching.

Saving actuals writes lot-backed material quantities to `production_consumption` and writes the non-stock water quantity to its formula line's `actual_mass_grams`. Both writes occur in the same transaction.

## Application and Database Boundaries

### Date invariant

The Plan production form validates `plannedFor` as required. The planning application action accepts a non-null date and rejects invalid direct calls. Save as draft remains the only date-free path.

### Release invariant

`ReleaseOutputLot` resolves the originating production through `stock_lots.production_run_id`, locks the relevant records in the canonical order, and rejects release when the ready date is in the future or any production task is incomplete.

### Delete invariant

A new forward migration replaces the production-run integrity function; already-run migrations remain unchanged.

PostgreSQL and SQLite reject deletion whenever an active stock reservation references the run, regardless of whether `batch_number` is null. PostgreSQL SQL uses schema-qualified table names. Numbered but unreserved Draft/Planned runs remain deletable.

The application action continues enforcing the lifecycle restriction to Draft/Planned and returns customer-facing validation errors. The database trigger remains the last defense for direct model or SQL deletion.

### Concurrency

Production mutations use the canonical lock order:

`workspace → run → requirements → lots → reservations`

`PrepareProductionStock` is brought into that order; it currently locks runs before the workspace. Transactions retain bounded deadlock retries.

## Error Handling

- Planning without a date identifies the production-date field.
- Starting before the planned date presents a confirmation, not an error.
- Preparing stock reports exact lye and other material shortages.
- Deletion with active reservations instructs the user to release stock first.
- Batch release before the ready date reports that date.
- Batch release with pending tasks reports the incomplete task names or count.
- All mutation failures leave status, reservations, movements, tasks, actuals, and output unchanged.

## Localization

Every new customer-facing term uses translation keys rather than hardcoded Blade or PHP text. Lifecycle labels, action labels, confirmations, validation messages, unified-table headings, stock/release explanations, output variance labels, and empty states are added to every supported locale. English remains in `lang/en/production_bench.php`; reviewed German, Spanish, French, Italian, and Dutch values remain in the interface-translation catalogue, in line with the existing localization architecture. Catalogue-completeness tests prevent a new English key from shipping without every supported translation.

## Verification

Automated tests cover:

- Plan production rejects a missing date while Save as draft remains date-free;
- every direct or Flash-created Planned run has a production date;
- NaOH/KOH produce stock requirements and water does not;
- partial lye coverage remains Planned and complete coverage becomes Stock prepared;
- actual usage defaults from reserved lots, while water defaults from planned mass;
- water actual usage persists without creating a stock lot or stock movement;
- early Start requires confirmation, while normal and late starts do not;
- production can complete with pending tasks;
- release fails before the ready date;
- release fails while any task is incomplete;
- release succeeds only after both gates pass;
- the unified table includes lye, water, inline percentages, planned quantities, reserved lots, and actual usage;
- PostgreSQL and SQLite block deletion of numbered and unnumbered runs with active reservations;
- numbered unreserved Draft/Planned runs remain deletable and their number remains burned;
- stale PostgreSQL deletion expectations are corrected;
- production actions preserve deterministic lock ordering.

Focused tests run first during test-driven implementation, followed by the related Production Bench feature suites. PHP formatting, Filament checks when applicable, and the local graph update run before completion.

## Out of Scope

- material substitution during production;
- automatic production start on the planned date;
- a separate Allocated reservation state;
- configurable water inventory tracking;
- configurable QC templates or laboratory workflows;
- rewriting existing production history.

### Future production reconciliation

A later design will introduce explicit production wastage and recoverable by-products, following the operational concepts proven in Cosmood without coupling them to this page redesign. It must distinguish at least:

- permanently lost material/output;
- reusable recovered material;
- sellable secondary output.

That future workflow must record quantity, unit, reason, operator, production stage, cost treatment, and—when material remains usable—a destination stock lot and auditable stock movement. Planned-versus-actual output variance alone never creates a wastage record.
