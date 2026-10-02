# Production Editing Final Verification Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to execute this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Execute inline on main in the existing conversation, preserving the other agent's work.

**Goal:** Verify the latest production editing fixes in actual use and prepare the integrated change for release.

**Architecture:** Retain the whole-production reservation, server revision guard and serialized client coordinator already implemented. Verify browser behavior against the existing automated contracts; fix a reproduced discrepancy with a failing regression test before changing its implementation. Keep section-specific saving and explicit Finish editing.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Alpine, Tailwind 4, Pest and Node tests.

**Scope:** This is the final verification phase of `2026-10-01-production-editing-protection.md`. No build, dependency changes or UI redesign. Retain the shared 180 KB PDF limit and English plus six catalogue locales. Deployment remains Philippe's manual Forge workflow. Philippe authorized execution with “proceed”; commit and push await his separate instruction.

## Current evidence

- Latest affected run: 76 tests passed, 823 assertions, including the Node bridge; Pint and whitespace checks passed.
- A time-controlled date test covers renewing an uncontested reservation after 91 seconds. Client tests cover blocked/stale renewal, a competing acquisition and late responses after departure.
- A 1.6 MB PDF is explicitly rejected by the existing upload service without creating a media asset or document. Client feedback now persists beside Attach across polls.
- Earlier full suite: 4,602 passed, 65 environment-dependent skips. It predates the latest fixes and must be refreshed.
- Earlier PostgreSQL run: 11 production concurrency cases and 3 sharing isolation cases passed, zero skips. Rerun only if this phase changes backend ownership, revisions, transactions or locking.

## Task 1: Verify repeated saves and reservation recovery

**Inspect:** `resources/js/production-editing.js`, `app/Livewire/Concerns/InteractsWithProductionEditing.php`, `app/Livewire/ProductionBench/Production/ProductionDetail.php`.
**Existing tests:** `tests/Feature/ProductionEditingDetailTest.php`, `tests/Feature/ProductionEditingClientTest.php`, `tests/Unit/production-editing.test.mjs`.

- [ ] Use the logged-in local production test page and record its initial production date. Confirm the browser is on Herd rather than production before making test saves.
- [ ] Choose Edit production. Change the date to a valid working day and Save production date. Expect the new header date, Editing status and no unsaved badge for the saved date. Change and save it again without reopening the page.
- [ ] Keep the focused page open for more than 90 seconds. Its 15-second heartbeat should retain editing access. Save again; expect ownership retained.
- [ ] Background the page for more than 90 seconds, return and save a changed date. Expect quiet renewal only when the production is free and its revision unchanged. Enter more input during a save; expect that newer input to remain unsaved.
- [ ] If a discrepancy appears, capture the recent Boost browser/backend logs and read-only lease/revision state. Add a focused regression in the existing test files, demonstrate failure, apply the smallest correction and rerun the affected tests. Do not renew a stale or competing reservation or weaken the server guard.
- [ ] Restore the original test date after verification and confirm it saved.

## Task 2: Verify attachments and control sizing

**Inspect:** `resources/views/livewire/production-bench/production/production-detail.blade.php`, `resources/js/production-editing.js`, `app/Services/MediaAssetUploadService.php`.
**Existing tests:** `tests/Feature/ProductionEditingDocumentsTest.php`, `tests/Feature/ProductionEditingDetailTest.php`, `tests/Feature/ProductionBenchLocalizationTest.php`.

- [ ] Verify the production-location select has the same compact control height in read-only and editing modes at desktop and mobile widths. Save an assignment and verify the displayed location.
- [ ] With Philippe's chosen test PDF at or below 180 KB, verify Uploading, Ready to attach, Attaching and Journal document attached. Expect the document link to appear and the completed upload to clear.
- [ ] With the already reported oversized PDF, verify the rejection beside Attach, no success confirmation and no new document. Keep the selected file and note for correction; a later heartbeat must not erase the rejection.
- [ ] Replace the rejected file with an acceptable PDF and retry. Verify a successful attachment clears the previous rejection. Simulate an interrupted upload and verify visible failure with retry possible.
- [ ] Verify Finish editing with a pending file or note opens the discard confirmation. Cancel must retain the draft and ownership; confirmed discard must clear the temporary upload and release access.
- [ ] Check the PDF limit text and attachment feedback in English and French. Verify all new keys remain present in every catalogue locale. No translation import overwrites administrator wording during this phase.

## Task 3: Verify two-profile handoff and stock preparation

**Inspect:** `app/Services/ProductionEditingService.php`, `resources/js/production-editing.js`, `resources/views/components/production-bench/editing-status.blade.php`, `app/Livewire/ProductionBench/Production/StockPreparation.php`.
**Existing tests:** `tests/Feature/ProductionEditingServiceTest.php`, `tests/Feature/ProductionEditingReleaseTest.php`, `tests/Feature/ProductionEditingStockPreparationTest.php`.

- [ ] Use Philippe as Owner/Admin and Phil as Editor on the same production. Owner viewing without Edit must not reserve it; Owner editing must block Editor without exposing a takeover button to Editor.
- [ ] Save as Owner while Editor waits. Editor must receive a changed-production indication and reload without obtaining competing editing access.
- [ ] Finish editing or leave the page as Owner. Editor must see availability on the next poll without a manual page reload; Editor deliberately resumes. Cancelled navigation must keep Owner's reservation.
- [ ] Let Owner's background reservation expire. Editor may resume; Owner returning must not displace Editor. Owner's pending draft remains intact.
- [ ] Open stock preparation for the same production and verify the same ownership rules. Dirty manual allocations must survive a failed command or conflicting editor. Do not execute stock reservation on an operational batch solely for this walkthrough; atomic stock writes remain covered by factory-backed tests.

## Task 4: Run final checks and prepare release

**Inspect:** `docs/superpowers/plans/2026-10-01-production-editing-protection.md`, Git working tree and the files named above.

- [ ] Run the focused checks after any correction:

```sh
php85 -d memory_limit=2G vendor/bin/pest tests/Feature/ProductionEditingDetailTest.php tests/Feature/ProductionEditingDocumentsTest.php tests/Feature/ProductionEditingClientTest.php tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/ProductionEditingStockPreparationTest.php tests/Feature/ProductionEditingMutationTest.php tests/Feature/ProductionEditingServiceTest.php --compact --colors=never
```

Expected: all affected checks pass; preserve existing tests and their business assertions.

- [x] Run the fresh full suite (executed directly during this phase):

```sh
php85 -d memory_limit=2G artisan test --compact
```

Expected: zero failures. Investigate any failure in the context of the other agent's shared-formula changes before attributing it to production editing.

- [ ] If backend concurrency code changed, rerun the production and sharing PostgreSQL files in separate processes using the previously authorized disposable test database and the existing identity gate. Expect zero skips; never reset Herd's working database or another agent's test database.
- [ ] Run `php85 vendor/bin/pint --dirty --format agent`, `git diff --check` and `graphify update .` after code corrections. Resolve applicable Filacheck findings if any correction touches `app/Filament`.
- [x] Review the integrated production diff, record actual test counts and browser results in the original plan, and explicitly identify any unverified browser case. Inspect `git status --short` to distinguish production files from the other agent's memory/export work.
- [ ] After the user's commit/push instruction, stage only the reviewed production changes and push main. Philippe deploys manually through Forge. Verify a repeated date save, an accepted PDF and an oversized PDF after deployment before closing the phase.

## Completion criteria

Repeated and post-expiry saves retain valid editing access; another editor or changed revision prevents writes without losing local input. Attachments provide persistent local rejection or acknowledged success, with the shared size limit visible. Location controls are compact, owner/editor handoff works across detail and stock preparation, and the fresh full suite passes. All release actions and browser verification results are accurately recorded.

## Execution record — 2026-10-01

- Fresh full suite: **4,609 passed, 65 skipped, 72,506 assertions**, exit 0, 231.10 seconds. No failures.
- Direct Node verification: **24 passed**, zero skipped or failed, covering serialized commands, newer input, expiry recovery, competing acquisition, late departure and persistent attachment feedback.
- Fresh Pint and `git diff --check` passed. Reviewed the coordinator, draft acknowledgements, service ownership/revision guard, detail command dispatch and status/attachment wiring; no additional implementation correction was identified in this phase.
- No code changed during this verification pass. The earlier PostgreSQL and Graphify results remain applicable; no backend concurrency change requires rerunning PostgreSQL.
- Browser verification could not start: both Comet selection attempts returned macOS ScreenCaptureKit error `-3811`, “Failed to start stream due to audio/video capture failure.” The surface inventory succeeds but provides no connected Comet browser alternative. Requested restoration of computer-use access while completing independent checks.
- Tasks 1–3 remain pending actual browser verification, including repeated and post-expiry date saves, accepted/rejected PDFs, mobile control sizing, two-profile handoff and stock-preparation drafts. Automated coverage is evidence for the underlying contracts, not a substitute for recording these browser cases as verified.
- No browser action changed the test production in this phase. No build, translation import, commit, push or deployment occurred. Other agents' memory and export-plan files remain intact and unstaged.

## Final code review — 2026-10-02

**Selected external-review corrections completed:** Philippe authorized the first three findings only. Retained the other reviewer's task-register Alpine wrapper and added a rendered-scope regression covering its department, employee and completion handlers. Restored existing translated output-lot workspace, link and parent validation messages in ReleaseOutputLot and IssueFinishedGoods, with the parent mutation guard and fresh authorization retained. A locked lot's parent link is explicitly rechecked before writing. The opening-balance regression now constructs a valid context before invoking either action and asserts each action's specific error; constructor rejection can no longer satisfy it. Eight reference-validation cases cover both actions, missing workspaces, removed/replaced links and parents outside the lot's workspace, with no movements or acknowledgments. The repaired rejection case and six initial reference regressions failed before implementation. Final affected verification: **109 passed, 497 assertions**; Pint, whitespace checks and Graphify passed. No build, commit or push occurred. Coordinator, group messaging and additional coverage findings remain for the next authorized phase.

Philippe accepted manual browser verification after repeated computer-use failures. Tasks 1–3 remain unverified in the browser and are handed to him; they do not block this code-review phase. Review compared the uncommitted production files, including new sources, against `d4644d09` and excluded unrelated memory/export plans.

- Independent standards review found no actionable defect in fresh authorization, reservation ownership, parent guards, atomic register commands or committed revision receipts.
- Independent spec/client review found and reproduced two defects: one task-date save cleared a sibling's rejected input, and legacy Intermediate output could not reveal its ingredient selector. Task receipts now preserve rejected and newer input while updating clean sibling dates. Draft capture happens at command invocation, protecting input typed while queued behind a poll. The legacy selector follows Alpine draft mode; completion requires an ingredient when that mode is Intermediate, using an existing translated message.
- Each correction had a failing regression before implementation. A second independent review confirmed the fixes, including the held-poll/two-date-changes race, with no further concrete issue.
- Final affected PHP run: **149 passed, 1,135 assertions**. Direct client/draft tests: **27 passed, zero failures or skips**. Pint, whitespace checks and Graphify passed. The recorded full-suite result above predates these narrow corrections; the user should rerun `php artisan test --compact` before release.
- The design and plan now consistently describe Task 9's explicit coherent Reload contract. No ownership, revision, transaction or locking implementation changed, so the earlier real PostgreSQL concurrency results remain applicable.
- No build, translation import, commit, push or deployment occurred. Other agents' unrelated files remain untouched and unstaged.


### Selected coordinator review corrections — 2026-10-02

Philippe authorized the next three confirmed findings. Reload now prepares a Locked snapshot without advancing the server baseline, then accepts and renders that exact receipt after the browser can apply it cleanly. New input/uploads during preparation prevent acceptance; input during acceptance remains dirty against the prepared snapshot. Missing acknowledgments and request failures keep writes disabled through polls until a confirmed retry. Workspace changes, deleted productions and unknown receipts cannot silently rebase the page. Group stock preparation follows the same contract, and accepting a reload retains an upload that arrived after preparation.

A committed save acknowledgment is separate from its subsequent presentation refresh. Refresh failure preserves the successful result, saved baseline, valid editing state and newer local input, with translated recovery feedback and a Reload control. Begin/takeover/finish/reload request failures return handled results with visible feedback. All six catalogue languages include the new messages. Regression tests reproduced the failures before their corrections, including the second-write-during-reload rendering race. Browser verification remains with Philippe. No build, translation import, commit, push or deployment occurred. Remaining group-message, release authorization coverage and cleanup findings are outside this selected phase.

Fresh affected verification: **161 passed, 1,262 assertions**. Direct Node draft/coordinator tests: **40 passed**, zero failures or skips. Pint and `git diff --check` passed; Graphify refreshed successfully. The complete-suite result above predates this phase; request `php artisan test --compact` before release. Parent mutation guards, lease acquisition and database lock order remain unchanged; this phase adds the explicit reload receipt boundary and client error handling.


### Waiting and group-message review corrections — 2026-10-02

Philippe authorized the two messaging findings. Successful acquisition clears the previous waiting state, so finishing returns to ordinary viewing without a stale Resume editing prompt or availability notice; a still-blocked observer retains the deliberate-resume prompt. Blocked multi-production selections now use the existing six-locale group warning from the mounted payload. Single-production notices still identify the holder, and the affected-production list remains available.

The waiting/finish regression, group-copy regression and rendered-payload contract failed before the corrections. Fresh verification: **37 affected tests passed, 746 assertions**, including the Node process bridge; **43 Node tests passed directly**. Pint, whitespace checks and Graphify passed. No build, translation import, commit, push or deployment occurred. Full-suite and browser verification remain with Philippe; rerun `php artisan test --compact` before release.

### Register failure and task-error follow-up — 2026-10-02

Philippe authorized the evaluated review follow-ups. Register requests now catch rejected promises and display a translated warning in both registers without automatically retrying an outcome that could not be confirmed. The English key and all six catalogue locales ask the user to reload and check the result before retrying. A later request can proceed through the same queue.

All five task actions reject deleted tasks and foreign production parents with the existing specific translated error, then repeat the task lookup inside the guarded write. A task disappearing after the production lock raises that validation error and rolls back instead of returning a 404. Tests cover each action at both boundaries. Default-suite guard tests also prove that demotion, revoked membership, workspace switching and cancelled entitlement reject before the domain writer is invoked, leaving data and revisions unchanged. The definitive unavailable-reload branch now has a client regression test; its existing behavior remains unchanged.

The register and task regressions failed before their fixes. Fresh affected verification: **180 passed, 1,458 assertions**; direct Node tests: **45 passed**. The existing prepare/adopt/accept reload contract remains unchanged. Full-suite verification and PostgreSQL migration round-trip verification remain outstanding. Run the full suite with `LANG=en_US.UTF-8 php85 artisan test --compact` to avoid the documented locale trap. No build, translation import, commit, push or deployment occurred.

### Final cleanup and complete verification — 2026-10-02

Philippe authorized the remaining cleanup and coverage. Assign/Complete task workspace failures now use the existing localized key. Removed unused aliases in four task actions and Reopen's redundant type guard after its scoped query/null check. Renamed the public-ID test to describe visible page text. The production register uses Refresh productions with a new English key and all six catalogue locales. Added explicit availability/acquisition coverage for production B while production A is leased. The prepare/adopt/accept reload contract is retained.

The localized-error and register-label regressions failed before correction; all **141 affected tests passed, 1,238 assertions** afterward. The new PostgreSQL migration round-trip test passed separately: **1 test, 15 assertions**. It uses the existing explicit disposable-database opt-in and identity checks for `koskalk_formula_sharing_test_20261001_production`, verifies production history plus index/constraint/trigger definitions through down/up, and verifies editing-table definitions are restored. No working database was reset.

Fresh full suite: **4,659 passed, 66 skipped, 72,891 assertions**, exit 0, 211.17 seconds under `LANG=en_US.UTF-8`. The PostgreSQL-only migration test skips in the default SQLite suite and was verified separately above. This completes the outstanding full-suite and PostgreSQL migration verification from the previous section. Pint, `git diff --check` and Graphify passed. Browser verification remains handed to Philippe. No build, translation import, commit, push or deployment occurred; unrelated agents' files remain untouched and unstaged.
