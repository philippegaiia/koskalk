# Production Run Batch Numbering Design

**Date:** 2026-08-05

**Status:** Approved design

## Purpose

Production Runs need a stable identity from the moment they are planned and a permanent batch number before production paperwork or execution begins. Makers must also be able to assign permanent numbers to several upcoming productions at once, such as when preparing the week's production sheets on Monday morning.

This numbering belongs only to the professional Production Bench. The Basic `production_batches` snapshot feature remains a separate Free/Light product and is not linked, migrated, copied, or synchronized with Production Runs.

## Product boundary

The two production products retain distinct records and lifecycles:

- A Basic `ProductionBatch` is a manually recorded formula and cost snapshot for hobbyists and small makers.
- A professional `ProductionRun` supports planning, tasks, reservations, execution, inventory movements, actual cost, and output traceability.
- Upgrading to Production Bench starts a new operational workflow. Existing Basic snapshots remain available under their own entitlement and retention rules.
- Cancelling Production Bench leaves its professional records read-only and makes them archive-eligible after 48 months. Resuming restores the same Production Bench records.
- Downgrading does not convert Production Runs into Basic snapshots.
- Completing a Production Run does not create or link a Basic `production_batches` record.

This decision supersedes the later execution-specification wording that proposed creating a linked Basic snapshot at Production Run completion.

## User language

The interface uses two terms:

- **Planning reference** for the automatically generated temporary identity, such as `T00001`.
- **Batch number** for the permanent regulatory and traceability identity, such as `B-00125-FR`.

The planning reference is not presented as a permanent lot or regulatory number.

## Number lifecycle

### Planning reference

Every Production Run receives a workspace-unique planning reference immediately when it is created. Direct creation and Flash generation use the same allocator.

The initial format is fixed as `T` followed by a five-digit, zero-padded workspace sequence. Users do not configure this internal format. The value is immutable and remains stored after a permanent batch number is assigned so support staff and users can trace the run from planning through execution.

Idempotent retries return the existing Production Run and do not consume another planning reference. A failed or rolled-back creation does not advance the committed sequence. Flash generation allocates references inside its existing all-or-nothing transaction.

### Permanent batch number

A permanent batch number may be assigned in three ways:

1. individually from Production detail;
2. in bulk from the Production list;
3. automatically by the future **Start production** action when the run has no permanent number.

Scheduled and Reserved runs may receive a permanent number before their production date. Draft runs cannot consume a permanent number because their operational plan is incomplete. In-production runs receive a number through the Start production transaction if one was not assigned earlier.

Once issued, a permanent number:

- cannot be edited, cleared, regenerated, or transferred;
- remains attached if the production date changes;
- remains attached if the run is cancelled or aborted;
- is never recycled;
- prevents destructive deletion of the numbered Production Run.

Cancellation and abortion remain visible lifecycle outcomes, preserving the audit trail for every issued number.

## Workspace numbering configuration

Production Bench adds a persistent **Numbering** entry under **Production setup**. A focused page contains:

- **Prefix**;
- **Next number**;
- **Number of digits**;
- **Suffix**;
- a live rendered example.

Recommended defaults are prefix `B-`, next number `1`, five digits, and an empty suffix, producing `B-00001`.

Prefix and suffix accept letters, numbers, hyphens, underscores, dots, and slashes. They do not accept control characters or whitespace. Each is bounded in length, and the full rendered number must fit the Production Run batch-number column. The next number must be a positive whole number. The digit count is a minimum padding width and never truncates a longer serial.

Changing the settings affects future assignments only. Previously issued numbers are stored snapshots and are never recomputed. An administrator may intentionally move the next number forward or start a new prefix or suffix at a lower positive serial. Saving rejects a next rendered number that is already issued. Before individual or bulk assignment, the allocator renders every number that transaction would issue and rejects the whole transaction if any candidate already exists. Issued numbers are never overwritten, skipped silently, or reused.

The configuration and both counters live in one workspace-scoped numbering record. The temporary counter is operational state and is not editable in the form.

## Permissions

Permissions distinguish configuration governance from routine production work:

- Workspace owners and administrators may view and change numbering settings.
- Editors may view numbering settings and assign permanent batch numbers individually or in bulk.
- Viewers may only view existing numbers.
- Inactive or cancelled Production Bench workspaces cannot change settings or assign numbers.

Every action revalidates workspace membership, entitlement state, lifecycle status, and record ownership on the server. Disabled controls are not treated as authorization.

## Individual assignment

Production detail displays the permanent batch number as the primary identity when present. Before assignment, it displays the planning reference and an **Assign batch number** action for an eligible user and lifecycle status.

The confirmation states that the next configured number will be consumed permanently and cannot be changed, including if the production is later cancelled. After success, the detail displays:

- permanent batch number;
- planning reference;
- assignment date and time;
- assigning user.

Repeated submission is idempotent and returns the already assigned number without consuming another serial.

## Bulk assignment

The Production list reuses its existing row selection rather than adding another selection system. The bulk action is labelled **Assign batch numbers**.

The confirmation summarizes how many selected runs:

- require a number;
- already have a number;
- are no longer eligible.

Already numbered runs are skipped idempotently. Eligible unnumbered runs are ordered by production date and then stable creation identity before serials are assigned. This makes the first production in the maker's schedule receive the first number, regardless of checkbox order.

If a selected unnumbered run changes to an ineligible lifecycle status between selection and confirmation, the transaction rejects the assignment rather than silently creating a partial result. Missing and cross-workspace references are rejected. A successful toast reports the number of newly assigned runs and the number already assigned.

## Flash generation

Flash remains a planning tool:

- every generated Production Run receives a planning reference;
- Flash does not consume permanent batch numbers;
- generated runs appear in the Production list and may be selected for the normal bulk assignment action;
- automatic permanent assignment remains part of the future Start production transaction when no early number exists.

This preserves the value of generating many proposed runs without accidentally issuing regulated identifiers before the user is ready.

## Concurrency and integrity

A dedicated workspace numbering row stores:

- the next temporary serial;
- permanent prefix;
- permanent suffix;
- permanent padding width;
- the next permanent serial.

Allocation runs in a database transaction. It locks the workspace, numbering row, and affected Production Runs. Bulk assignment acquires Production Run locks in stable primary-key order to avoid deadlocks, then applies numbers in production-date order.

Production Runs store:

- immutable planning reference;
- nullable immutable permanent batch number;
- permanent serial snapshot;
- assignment timestamp;
- assigning user.

Database uniqueness is scoped to the workspace for both rendered identifiers. Database-level protection prevents a non-null permanent number from being changed or cleared, including through bulk query updates that bypass model events. Application-level validation provides understandable errors before a database constraint is reached.

Retries use the existing transaction retry convention. A collision or failure rolls back both the Production Run updates and the sequence change.

## Existing-run backfill

Existing Production Runs receive planning references per workspace in stable `id` order. Backfill does not assign permanent numbers. After the backfill succeeds, the planning-reference column becomes required.

Because the application has no production customer records requiring legacy interpretation, this backfill only establishes valid identities for current development and trial records; it does not infer regulatory batch numbers.

## User-facing surfaces

### Production list

The permanent batch number is the primary displayed identifier. Until it exists, the list displays the planning reference with a clear planning-state treatment. Search matches both identifiers and the product name.

### Production detail

The detail header shows the permanent batch number when assigned and retains the planning reference as secondary audit information. Assignment metadata is read-only.

### Calendar

Calendar production events use:

- `B-00125-FR · Product name` after permanent assignment;
- `T00001 · Product name` before permanent assignment.

Task event titles remain unchanged. Calendar drag-and-drop is implemented after numbering so moved productions retain the same visible identity.

### Production sheets

Printable Production Sheets and bulk printing remain part of the execution checkpoint because their formula, actual-lot, instruction, and traceability content is not yet implemented. When added, regulatory sheets use the permanent batch number and may show the planning reference secondarily.

## Error handling

Failures use the existing application toast and form-error patterns. User-facing messages cover:

- no eligible productions selected;
- incomplete Draft production;
- production status changed before confirmation;
- inactive or read-only Production Bench;
- insufficient workspace role;
- invalid prefix, suffix, padding, or next number;
- candidate number already issued;
- missing or cross-workspace Production Run;
- unexpected allocation failure without exposing internal exception details.

No failure leaves a partially assigned bulk selection or an advanced committed counter.

## Testing strategy

The implementation is test-driven and proves:

- schema, relationships, casts, uniqueness, and database immutability;
- default numbering configuration per workspace;
- direct and Flash planning-reference allocation;
- idempotent creation without additional serial consumption;
- rollback behavior;
- settings validation and live example formatting;
- configuration changes affecting future numbers only;
- individual assignment and idempotent retry;
- chronological bulk assignment and already-numbered skips;
- atomic rejection when a selected run becomes ineligible;
- workspace isolation and role enforcement;
- inactive and cancelled entitlement protection;
- permanent numbers surviving reschedule, cancellation, and abortion;
- numbered Production Runs resisting destructive deletion;
- list, detail, search, calendar, and responsive presentation;
- translations in every supported interface language;
- Basic `production_batches` remaining unrelated and unchanged.

A concurrency-focused test uses separate database transactions or connections where supported to prove that simultaneous allocation cannot issue duplicate numbers.

## Delivery boundary

This feature includes numbering persistence, settings, creation allocation, individual and bulk permanent assignment, permissions, translations, and display integration.

It does not include:

- Calendar drag-and-drop;
- Production Sheet printing;
- Start production or execution screens;
- reservations or inventory movements;
- Basic `production_batches` changes;
- automatic annual resets, date tokens, or multiple simultaneous numbering schemes.

The future Start production action must reuse the same permanent-number allocator inside its transaction. It must preserve an existing early-assigned number and assign the next number only when the field is empty.

## Acceptance scenario

The review demonstrates:

1. direct and Flash-created runs receiving distinct `Txxxxx` planning references;
2. an administrator configuring prefix, suffix, padding, and next number with a live preview;
3. an editor selecting several future productions and confirming bulk assignment;
4. permanent numbers assigned in production-date order;
5. list, detail, search, and calendar displaying the expected identifiers;
6. changing numbering settings without changing previously issued numbers;
7. cancelling a numbered production while retaining its consumed number;
8. a concurrent or duplicate submission producing no duplicate or additional assignment;
9. a viewer and a cancelled workspace being unable to assign numbers;
10. Basic production snapshots remaining independent.
