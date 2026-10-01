# Production editing protection — phase one

**Date:** 2026-10-01
**Status:** Phase-one scope and principles approved in conversation; detailed specification awaiting written review.
**Scope:** Existing production records, their stock preparation, and other entry points that change those same records.

## Goal

Prevent one operator from overwriting another operator's production work, while keeping production pages available for monitoring. Carry forward the formula reservation behaviour validated in production: explicit takeover permissions, quiet recovery when safe, preserved unsaved input, and release on confirmed departure.

The customer-facing term is **Production**. Internal code continues to use `ProductionRun`. An editing reservation is separate from the physical stock reservations made during stock preparation.

## Approved direction and alternatives

Use one editing reservation per production, acquired when editing starts. Different productions remain independently available, including in the same workspace. This deliberately provides one writer for the whole production, including its tasks and journal, rather than independent simultaneous writers for different sections of that production.

Alternatives considered:

| Approach | Benefit | Cost | Decision |
| --- | --- | --- | --- |
| Reservation on explicit editing, plus revision checks | Prevents conflicting work without reserving records just for reading | Needs an editing entry point and protection on alternate write paths | Selected |
| Reservation whenever a detail page opens | Closest mechanical copy of the formula bench | Monitoring tabs would block operators unnecessarily | Rejected |
| Revision checks without an editing reservation | Allows simultaneous editing until save | Operators can spend time on changes that later cannot be saved | Rejected for this phase |

No whole-workspace reservation, realtime broadcasting service, automatic takeover, collaborative merging, or task-level reservation system is introduced.

## Current code and schema

The inspected production Actions already use transactions, role checks and lifecycle validation. These protect a write while it executes; they do not preserve an operator's editing ownership between requests or reject an old page's changes using an expected production revision.

The live schema was inspected using Boost and a focused Truss export. `production_runs` currently has no `edit_revision`. There is no production editing lease or takeover table. Production requirements, actual consumption, tasks and stock reservations refer to the production record. Each output lot has a unique nullable `production_run_id`.

Important implementation constraints found during inspection:

- `ProductionDetail` exposes mutable form state and many separate operational actions. Its production identity needs `#[Locked]` protection.
- `ProductionIndex` can schedule, delete and number existing productions; `TaskIndex` can complete, reopen and reassign their tasks.
- `StockPreparation` can prepare multiple productions in one transaction.
- The calendar currently displays records and navigates to their pages; it has no independent write operation to add a reservation to.
- Some task Actions currently lock task → production → workspace. They must align with workspace → production → child rows when brought under the new guard.
- Production journal document Actions are shared with purchasing documents. Only the production-record branch receives this phase's guard.
- Formula reservation behaviour is established by commit `7fe3530d`; normal saves stay on the same page. Do not change that behaviour as part of this work.

### Formula-sharing integration baseline

This specification was rechecked after formula sharing was merged into local `main` at `4e34e899`. The original design commit `22f94bbc` and the tested formula editing behaviour are preserved. Start implementation from the merged baseline; do not restore the earlier checkout or reset the working database.

The merged `WorkspaceWriteLock` acquires the workspace row lock and, on PostgreSQL, performs a no-op physical update of `updated_at`. This advances the row's MVCC version without changing its business timestamp or emitting model events. A row lock alone cannot provide the same stale-snapshot protection for a competing REPEATABLE READ writer. Production editing and guarded production mutations must reuse this service at their workspace locking boundary; do not replace its update with only `lockForUpdate()`.

Formula sharing reads the latest Saved source without taking or changing its formula editing reservation. Accepted copies are independent destination Products. Neither flow transfers a production reservation or adds Production Bench implementation. Keep these boundaries and fresh `WorkspaceAuthorization`/`ProductionBenchAccess` checks intact.

Use the installed PHP 8.5, Laravel 13, Livewire 4 and Filament 5 APIs. Do not infer Filament APIs from older inline examples.

## User experience

### Reading and starting editing

Opening a production detail page is a read operation. It neither acquires nor renews a reservation. All saved settings, materials, tasks, output information and journal entries remain readable.

An authorized operator chooses **Edit production** in the header. The server attempts to reserve that production against the page's mounted revision. Editing controls become available only after a successful reservation. Existing per-action lifecycle and permission restrictions still apply.

The same header control becomes **Finish editing** while this page owns the reservation. Finishing with unsaved input requires an explicit discard confirmation; cancellation retains the reservation and draft. Finishing a clean editing session releases the reservation and returns to viewing. Save actuals stays in the same editing session and does not navigate or silently finish editing.

A new-production form has no existing record to reserve. Creation retains its current idempotency rules. Creating a new production does not reserve unrelated records or turn the reusable creation form into an editor for the previously created record.

### Someone else is editing

When an editing attempt is blocked, show one compact status message naming the holder. Keep saved information readable and disable writes for that production.

- Editors cannot force takeover.
- Owners and Admins may explicitly take over, with a required reason and an audit record.
- The same user in another genuine tab is treated as another editing session because tokens are distinct.
- Other productions remain writable.
- When the holder leaves or expires, the waiting page offers **Resume editing** after its next status poll. It does not acquire automatically.

An editing reservation never grants authority beyond `ProductionBenchAccess`. Viewer access, cancelled Production Bench access, revoked membership, selected-workspace changes and manager-only operations continue to be checked using fresh authority.

### Idle sessions and messages

Preserve the 90-second lease duration and 15-second polling interval. The 90 seconds run from the last successful acquisition or renewal, not from the last Save click.

Renew an active editing session while its document is visible and focused. A viewing page does not heartbeat. Switching tabs or minimizing a browser does not proactively release the lease, but renewal stops while inactive.

When a former holder returns after expiry, quietly reacquire only if the production is available and its revision still matches the mounted baseline. If another holder or a newer revision exists, preserve the draft and require the appropriate explicit recovery action.

Do not display recurring availability banners to someone who is merely viewing. Do not show a claim about unsaved changes when the page has no pending input. Repeated polls update one status area; they do not generate repeated notifications. A successful save and its saved/dirty indicator must agree: newer input during a save remains unsaved, while the submitted unchanged input becomes saved.

### Changed or deleted production

If another successful mutation changes the production revision, a page with pending input becomes stale. It cannot save or automatically adopt the new revision. Offer a deliberate reload, confirming before discarding input. Never merge or replace local actuals, notes, dates, output quantities or manual allocations as a side effect of polling.

For a clean viewing page, refresh saved information without a dirty-state warning. Refresh the whole coherent view, including form defaults and its mounted revision; do not advance the revision alone while leaving old form values behind.

If the production is deleted, show that it is unavailable, stop editing attempts and retain any local unsaved input for inspection. Do not represent deletion as temporary availability.

## Stock preparation and short actions

### Stock preparation

Opening the stock-preparation page is a preview. **Edit allocations** acquires the selected productions as one group. One page token owns a separate lease for each selected production; there is no workspace-wide or stock-lot-wide editing reservation.

The selected production identities and their mounted revisions are server-owned, Locked state. Normalize, deduplicate and bound a group to 100 productions, matching the largest existing production-list page size. Reject a mixed-workspace selection.

Acquisition is all-or-nothing: lock the workspace, then the selected productions in ascending primary-key order; verify every record before writing any lease. If one production is held by another session, deleted or stale, acquire none. Identify the affected productions and let the operator explicitly change the selection rather than silently skipping them.

Confirmation validates every matching token and expected revision before writing any physical stock reservation. Any failure rolls back the entire group, including numbering or other domain side effects in that command. Preserve the submitted manual allocation draft.

Editing leases do not freeze physical stock. Other productions and inventory operations may consume or adjust available lots. Confirmation must retain the existing locked stock-availability, lot-eligibility and idempotency checks; stock proposals are previews rather than promises of availability.

The existing successful confirmation redirect is retained. Its confirmed departure releases every lease still owned by this page. Failed confirmation stays on the page with the draft and valid leases intact.

### Production-list and task-list actions

Reading a register, filtering it or selecting rows never reserves all displayed productions.

For a short action such as assigning a task, scheduling a production, assigning batch numbers or deleting a draft, acquire a temporary reservation only for the affected production or group, validate the displayed expected revision, execute the command atomically, then release the temporary reservation. These steps happen server-side in one transaction; they do not require a user to enter a persistent editing mode for the whole register.

An active reservation from another page blocks the action even when it belongs to the same user. Takeover remains a separate deliberate operation on the production, never an automatic consequence of clicking a register action.

A scheduling form opened from a list retains the revision from when that form opened. A subsequent list refresh cannot silently rebase that form's unsaved date. For successful list actions, refresh the affected rows and their revision metadata together.

## Mutation coverage

Protect the Action/Service boundary, not just the visible buttons. Every browser caller of an existing-production mutation must supply the validated editing context. Omitting it must not bypass the guard. A trusted creation or maintenance path is an explicit internal capability, never a browser-supplied boolean or optional missing token.

| Existing operation | Boundary to cover | Caller(s) |
| --- | --- | --- |
| Save actual consumption and calculated water | `SaveProductionActuals` | Production detail |
| Schedule, reschedule and assign location | `ScheduleProduction`, `RescheduleProduction`, `AssignProductionLocation` | Detail and production register |
| Start, cancel, complete or abort | `StartProduction`, `CancelProduction`, `CompleteProduction`, `AbortProduction`; `ProductionCompletionService` | Production detail |
| Prepare or release physical stock | `PrepareProductionStock`, `ReleaseProductionStock` | Preparation and detail |
| Assign permanent numbers or delete a draft | `AssignProductionBatchNumbers`, `DeleteProductionRun` | Detail and production register |
| Assign, complete, reopen, reschedule or reset a task | `AssignProductionTask`, `CompleteProductionTask`, `ReopenProductionTask`, `RescheduleProductionTask`, `ResetProductionTaskDate` | Detail and task register |
| Append journal entries | `SaveProductionJournalEntry` | Production detail |
| Attach or detach production journal documents | Production branch of `AttachProductionDocument`, `DetachProductionDocument` | Production detail |
| Release an output lot or issue finished goods | `ReleaseOutputLot`, `IssueFinishedGoods` | Production detail; any other caller of those same commands |
| Change an existing production plan | `UpdateProductionPlan` | Existing application capability, even though no current detail-page form invokes it |
| Backfill an existing formula snapshot | `BackfillProductionFormulaSnapshot` | Explicit maintenance command |

Task operations derive the protected production from the task and verify workspace ownership; a task ID supplied to a detail component must belong to that mounted production. Output operations derive it from the lot's durable `production_run_id`. Document operations resolve and verify their production parent inside the guarded write.

Completed productions remain closed to plan/actuals changes. Existing valid post-completion task operations, output release and finished-goods issuance remain possible with the required editing protection and their existing domain validations. Do not equate Completed with a blanket ban on every operational action.

Shared receipt document attachment/detachment behaviour remains unchanged. Unrelated inventory adjustment, receipt posting and purchasing flows remain outside this phase. Their physical-stock changes continue to be validated at production confirmation rather than being blocked by a production editing reservation.

Maintenance backfill acquires workspace → production locks, skips an actively edited record with an explicit command report, and increments its revision only when it writes a previously missing snapshot. An idempotent no-op does not invalidate open pages. Internal task generation for a newly created or newly scheduled production runs under its enclosing authorized command, rather than creating a separate browser lease or incrementing revisions twice.

## Server architecture

Use production-specific services; do not route productions through the formula-specific service or introduce a generic polymorphic reservation framework in this phase.

1. **`ProductionEditingService`** manages status, acquisition, group acquisition, heartbeat, matching release and audited takeover. It checks fresh workspace roles and Production Bench entitlement. A secure tab UUID is stored only as a SHA-256 hash in the lease table; holder name is a presentation snapshot. Store one lease per production.
2. **`ProductionMutationGuard`** validates a required editing context under workspace → production locks, invokes the existing domain operation, and advances the production revision exactly once for a successful changed command. Failure rolls back the command and the revision. Bulk commands lock productions in ascending ID order and validate the complete group before mutation. No-op commands do not increment revisions. Successful deletion returns a deleted-record acknowledgement and cascades lease cleanup; it cannot increment a row that no longer exists.
3. **A production Livewire concern** keeps the mounted production IDs, token and expected revisions Locked. It provides reservation/status/recovery methods and returns successful revision acknowledgements. It uses `InteractsWithProductionWorkspace` for original-workspace binding.
4. **A production client coordinator** stores its queue and lifecycle runtime in one per-component closure, shared across nested Alpine scopes. Queue revision-bearing writes and reads together. Successful own-write acknowledgements may advance the baseline; polls cannot advance it over a pending draft.
5. **`ProductionEditingController` and a Form Request** expose one authenticated, CSRF-protected, rate-limited release POST accepting the page token and up to 100 distinct production public IDs. The controller derives authorized records from those public IDs; it does not trust a submitted workspace or user ID. Release affects only leases matching both the authenticated user and the supplied token, including on repeated or late requests. Release is housekeeping: fresh read access and the matching ownership/token suffice even if the Bench has become read-only or the former editor was downgraded. Acquisition, renewal and domain writes still require current writable access. Revoked workspace access prevents release too; expiry remains the cleanup fallback.

Other Action/Service code keeps its existing boundaries and calculations. Bring affected writers into a consistent lock order rather than wrapping reverse-order locks and hoping transaction retries will conceal deadlocks. Workspace-wide serialization exists only for the brief transaction, not for the lease duration.

Use `WorkspaceWriteLock` before production rows or write-authority checks that rely on the protected workspace state. The production portion remains workspace → productions in ascending ID order → child rows. If an operation locks its actor user, acquire that lock before the workspace; if membership rows need locking for reauthorization, lock them after the workspace and before production rows. Never acquire a user lock after holding a workspace lock, or acquire another workspace after locking a production. This is compatible with sharing's actor → sorted workspaces → membership → share → recipe → ingredients order; production operations do not call formula-sharing transactions or import their record locks.

Keep production transactions at their established isolation level unless implementation analysis proves a stronger level necessary. Do not copy sharing's outermost REPEATABLE READ setup or change an existing parent transaction's isolation. The shared workspace fence must remain effective for concurrent sharing transactions even when the production writer uses READ COMMITTED. Retry eligible concurrency failures at the outer transaction boundary, reload authoritative records on every attempt, and keep uploads and other nontransactional side effects outside retrying callbacks. Verify PostgreSQL behavior with real concurrent connections rather than relying on SQLite.

Document upload processing remains outside a long database transaction. Check editing eligibility before starting expensive processing, recheck the lease and expected revision inside the attachment transaction, and retain the existing unreferenced-upload rollback when attachment fails. A successful uploaded asset is not proof that the journal document was attached.

## Storage

Add an unsigned bigint `production_runs.edit_revision`, default 0, without changing existing production data or lifecycle values.

Add `production_edit_leases`, mirroring the established formula lease structure:

- primary key;
- unique indexed `production_run_id` foreign key, cascading on production deletion;
- indexed `user_id` foreign key, cascading on user deletion;
- 64-character token hash, holder name, expiry and timestamps.

Add `production_edit_takeovers` with an indexed production reference, nullable actor/previous-user references, name snapshots, required reason and creation timestamp. Mirror the existing formula audit's foreign-key deletion policies. Acquisition, renewal, release and takeover do not change the production revision.

The migration must reverse cleanly and preserve production numbering triggers, unique/partial indexes, quantities, actuals, snapshots and stock history on both PostgreSQL and the test database. Inspect with Truss before migration, check relevant doctor findings, and verify the resulting structure after implementation. No dependency changes are needed.

## Departure and recovery

Follow the tested departure behaviour from the formula bench:

- Release on confirmed `livewire:navigating`, native `pagehide`, component teardown and explicit Finish editing.
- Do not release on cancellable `livewire:navigate`, `beforeunload`, blur or visibility changes alone.
- Use a CSRF-authenticated `fetch` with `keepalive: true`, independent of a component that may already have been destroyed.
- Deduplicate normal lifecycle release calls. If an in-flight response establishes ownership after departure, release that matching reservation again.
- Stop queued writes and heartbeats after departure starts. A late request cannot resurrect ownership in a discarded page.
- A late release from an old token must not clear a newer token, even for the same user.
- Retain expiry as the fallback for crashes, lost connections and departure requests the browser cannot deliver.
- On browser-cache restoration, check fresh authority and revision before restoring a former holder's editing access. Preserve its input. Restored waiting observers still choose Resume editing.

Detail-to-preparation navigation initially opens a preview, not an automatically acquired second session. This avoids treating an ordinary handoff as a competing editing tab on page load. Entry points must still handle a matching departing reservation that has not finished releasing; they may wait for availability, but cannot silently steal it.

## Messages and localization

Use short keys under `production_bench.editing`, register the catalogue pattern and supply the supported interface translations. Proposed English copy:

| Situation | Copy or control |
| --- | --- |
| Enter editing | Edit production |
| Leave editing | Finish editing |
| Begin preparation editing | Edit allocations |
| Another holder | :name is editing this production. You can still view it. |
| Waiting reservation becomes available | This production is available to edit. |
| Recover a waiting session | Resume editing |
| Deliberate manager takeover | Take over editing |
| Actual stale record | This production changed since you opened it. Reload before editing. |
| Unsaved input exists | Your unsaved changes remain on this page. |
| Stale/busy group | The selected productions could not all be reserved. Review the listed productions before continuing. |

Keep control and status copy together in one compact area. Detailed holder expiry or audit information belongs in secondary disclosure, not in a recurring interruption. Busy and stale errors identify the affected production names/identifiers, not just numeric database IDs.

## Verification

Add behavioral tests before implementation, using existing factories and per-file `RefreshDatabase`. Cover:

1. Viewing creates no lease; editing acquires only the chosen production; a second production remains available.
2. Editor/Viewer restrictions, explicit Owner/Admin takeover and audit, revoked membership, workspace switch and inactive/cancelled entitlement.
3. Wrong user/token release, repeated release, delayed release after takeover, and same-user genuine second tabs.
4. A normal save accepts its own new revision without remounting, without a phantom second session, and without clearing newer input.
5. Expiry recovery for a former holder only when available and unchanged; a waiting editor resumes explicitly.
6. Stale write rejection at every mutation family, including task-list and production-list callers and direct Action calls without valid context.
7. No partial bulk acquisition or confirmation when any production is busy, stale, missing or from another workspace. Preserve stock idempotency and competing-lot validation.
8. Cancelled navigation retains the lease; confirmed navigation/tab closure releases; late acquired responses are released; queued heartbeats/writes stop; browser restoration checks revision.
9. Normal lifecycle transitions, pending tasks after production completion, output quarantine/release, permitted finished-goods issuance and preserved stock/accounting effects.
10. Journal/document guards and upload rollback; receipt document paths remain unaffected.
11. Maintenance revision bump/skip rules and creation's existing idempotency behaviour.
12. Migration round trip retains production data, numbering integrity triggers and indexes; PostgreSQL concurrent-acquisition/competing-command tests verify behavior that SQLite cannot prove. Include production writes competing with sharing/entitlement writes through `WorkspaceWriteLock`: stale REPEATABLE READ transactions retry or fail safely, business workspace timestamps remain unchanged, authority is rechecked after retries, and mixed paths retain the documented lock order.

Use the existing formula editing tests as regression coverage. Do not install a browser-test dependency. Perform a manual two-profile walkthrough on Herd after narrow tests pass, then ask the user for the full suite. Run Pint after PHP changes and refresh Graphify after implementation. No local frontend build or deployment is part of this planning stage.

## Delivery outline

The detailed implementation plan should break this single subsystem into test-first steps:

1. Production revision/lease/audit storage and migration round-trip tests.
2. Production editing service, authorization and release endpoint.
3. Required mutation context and guard; migrate existing writer boundaries and lock order without allowing an unguarded browser fallback.
4. Explicit detail-page editing and coherent save/dirty/recovery state.
5. Group stock-preparation editing and atomic confirmation.
6. Short production-list/task-list commands, linked output/document operations and maintenance handling.
7. Localization, two-profile verification and regression suite.

The guard rollout and its callers form one coherent implementation: do not deploy a partial stage that disables old callers or allows old writers to bypass leases. Purchasing, general inventory editing and settings get separate subsequent specifications.
