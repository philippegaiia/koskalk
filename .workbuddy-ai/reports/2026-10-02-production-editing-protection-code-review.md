# Production editing protection — code review of uncommitted work

**Date:** 2026-10-02
**Scope:** everything unstaged/untracked in the working tree at `d4644d09` (`main`). Nothing staged, nothing committed.
**Method:** four independent reviewer passes over disjoint areas (concurrency core / Actions layer / Livewire+Blade+i18n / browser coordinator), then hand-verification of the headline claims by the coordinating agent. Reviewers were read-only and did not run the suite or touch a database.
**Intent authority:** `docs/superpowers/specs/2026-10-01-production-editing-protection-design.md`.

Not reviewed here (unrelated to this feature, left alone): `.workbuddy-ai/memory/**`, `docs/superpowers/plans/2026-09-28-export-*`, and the `.ai/rules/*` additions.

---

## Verdict

**Not ready to merge as-is.** The architecture is sound — lock order, revision semantics, lease ownership, group atomicity, authorization freshness and migration reversibility all match the spec, and no reviewer found a bypass. But one regression makes a shipped page non-functional, and one behaviour regression silently degraded operator-facing validation.

One Critical was fixed during this review (see below). Two Important items need a decision from you.

---

## Critical

### 1. `task-index.blade.php` shipped without its Alpine scope — FIXED during this review

`resources/views/livewire/production-bench/production/task-index.blade.php`

The diff replaced the register's `wire:change`/`wire:click` handlers with Alpine ones:

- `@change="run('assignDepartment', …)"` (line 51)
- `@change="run('assignEmployee', …)"` (line 52)
- `@click="run('toggleTask', …)"` and `:disabled="busy"` (line 53)

…and added a closing `</div>` at line 61 — but never added the matching `<div x-data="productionRegister()">` opener. The file contains no `x-data` at all, `<x-production-bench.page>` and `app-shell` declare none, and `productionRegister` is referenced only in `app.js:36` and `production-index.blade.php:2`.

**Effect:** `run`/`busy` are unresolved in that scope, so the department select, employee select and Mark complete/Reopen button send no request. The task register's assign/complete/reopen actions are dead in the browser, and the DOM is unbalanced. No test asserts rendered wiring, so the suite is blind to it.

**Fixed:** added `<div x-data="productionRegister()" class="space-y-6">` immediately after the contextual-help `<script>` (line 3), which balances the stray `</div>` and matches the `production-index.blade.php:2` pattern. If that file was mid-edit, delete the added line.

**Still recommended:** add a rendered-wiring assertion (e.g. `->assertSeeHtml('x-data="productionRegister()"')`) to the register test so this cannot regress silently.

---

## Important

### 2. Output-lot actions lost their explicit domain rejections

`app/Actions/Production/ReleaseOutputLot.php:38`, `app/Actions/Production/IssueFinishedGoods.php:59`

The diff deleted three explicit guards that existed at the base commit — `output_lot_workspace_missing`, `output_lot_unlinked` (`production_run_id === null`) and `output_production_missing` — and replaced them with:

```php
$parentId = (int) StockLot::withoutGlobalScopes()
    ->where('workspace_id', $lot->workspace_id)->whereKey($lot->id)->value('production_run_id');
```

For a non-production lot (opening balance, adjustment, purchase lot) that yields `0`; `ProductionEditingContext::__construct` (`:24`) rejects any id `< 1`, so the guard throws `production_editing.validation.selection` — *"Choose between 1 and 100 productions from this workspace."* That is rendered into the output error area, where it reads as a selection problem rather than an unlinked lot. The plan requires output operations to "reject a null/changed link" and the spec requires finished-goods issuance to retain its existing domain validations. Three lang keys (`lang/en/production_bench.php:646-648`) are now orphaned.

A *changed* link is also no longer a validation error: the re-lock adds `->where('production_run_id', $parentId)`, so a mismatched link now throws `ModelNotFoundException` (404), which neither Livewire handler catches.

**Fix:** reject `$parentId < 1` explicitly with the existing `output_lot_unlinked` message, and map the guard's `unavailable` failure back to `output_production_missing`.

### 3. The one test covering that path now passes vacuously

`tests/Feature/ProductionExecutionTest.php:1666-1670` with `tests/Support/ProductionEditingFixture.php:61-63`

`ProductionEditingFixture::command()` maps a `StockLot` to `[(int) $selection->production_run_id]`, i.e. `[0]` for the opening-balance lot, then builds the revision map `[0 => 0]`. `ProductionEditingContext::__construct` throws on that — and because the fixture call is an *argument* to `handle()`, it throws **before either Action runs**. `->toThrow(ValidationException::class)` therefore passes for the wrong reason, and the comment at `:1658` no longer describes what is asserted. This currently masks item 2.

**Fix:** assert the specific error key (after fixing item 2), or make the fixture throw a `LogicException` for a `StockLot` with no durable `production_run_id` so misuse cannot be mistaken for the Action's rejection.

---

## Minor

**Browser coordinator** (`resources/js/production-editing.js`)

- `reload()` advances the revision baseline unconditionally (`:182`) while the form replacement is guarded by `draft.replaceClean()` (`:181`). If the draft turns dirty between click and response, the revision advances while old form values remain — the exact anti-pattern the spec forbids. Only assign `payload.revisions` when the replacement succeeded.
- `await this.call('refreshProductionPresentation')` sits inside the command's `try` (`:151`). If that non-essential re-render rejects, the catch marks the command failed and disables writes — showing "failed" for a save that actually succeeded. Move it outside the `try` or swallow it.
- `operate()` rethrows (`:92`) and `reload()` never catches (`:179`), so `begin`/`takeover`/`finish`/`reload` can produce unhandled promise rejections when bound directly to DOM handlers; a failed Reload is silent. The sibling `recipe-workbench/editing.js` never rejects.
- `waiting` is set true at `:64` and never reset, so after a waiting editor resumes and then finishes, the page can still show "available to edit" / "Resume editing" while merely viewing.

**Backend / actions**

- Nested `withLocked` re-entry: `ProductionMutationGuard` calls `acquire()`/`release()` inside an already-held `withLocked` transaction (`ProductionMutationGuard.php:39,71`), re-locking the same rows and re-running the authorization pass. Reviewers disagreed on the mechanism (one calls the inner `attempts: 5` inert, an earlier pass called it wasteful 5×5) but agree it is **not a correctness defect** — same transaction, same rows, same order. Worth a comment recording the rationale so nobody "simplifies" it into a bare table write that drops the authorization re-check.
- `ProductionMutationGuard.php:73-76` only patches `edit_revision` on a bare `ProductionRun` return value; collections/arrays hand back pre-increment snapshots. Harmless today (callers use `acknowledgedRevisions()`), latent otherwise.
- `ProductionEditingContext.php:20-22` reports a non-UUID token as `validation.selection` instead of `validation.token`.
- `CompleteProductionTask.php:31`, `ResetProductionTaskDate.php:35` and peers: `(int) …` turns a missing parent into `0`, surfacing a generic selection error rather than a task-specific one. Fails closed.
- `IssueFinishedGoods.php:62` binds `$production` and never reads it.
- Dead code from the refactor: `ProductionDetail.php:993 loadSavedProductionState()`, `ProductionDetail.php:92 $actualsDirty` (written, never read), `StockPreparation.php:70 toggleManual()`.
- `lang/en/production_bench.php:23 editing.group_blocked` is localized in six locales and rendered nowhere; the group failure surfaces raw `editing.validation.*` text instead.
- `InteractsWithProductionEditing.php:41` — the `#[Locked] editingPresentation` property carries a full model graph (requirements, reservations, formula lines, documents, tasks, journal) back from the client on every 15-second poll.
- `production-index.blade.php:3` renders the editing copy "Reload production" as the first control, visible even when the Bench is inactive or read-only.

**Test coverage gaps**

- `ProductionEditingReleaseTest` covers only matching/wrong token, malformed/oversized selection and foreign+deleted ids. The spec's verification list also requires guest authentication, duplicate ids, Viewer-downgraded release permitted, cancelled-Bench release permitted and revoked-membership release blocked. The implementation looks correct; the only HTTP entry point of the feature is simply untested for its authorization matrix.
- `ProductionEditingStockPreparationTest` is missing the spec's foreign-workspace rejection, stale selection, late-stock-change draft retention, redirect retention and "never silently claims the old detail token" cases.
- No PostgreSQL `down()`/`up()` round trip (the migration test's integrity assertions are SQLite-only).

---

## What was verified by hand

- The Critical: diff read directly; `productionRegister` grep across `resources/` + `app/`; ancestor scopes (`production-bench/page.blade.php`, `app-shell`) checked for `x-data`. Confirmed.
- Item 2: `git diff` of both Actions against the base shows the three deleted guards. Confirmed.
- Item 3: `ProductionEditingFixture.php:61-69` read; the `[0]`-id context throws in the constructor. Confirmed.
- The two Node test files were executed: **27 passed, 0 failed** — matching the plan's record.

## Context worth knowing

- The four reviewers did **not** run the Laravel suite, so their behavioural verdicts are from source inspection. Today's memory records a full-suite run on this tree of **74 failed / 4,537 passed / 65 skipped**, root-caused to a pre-existing `CurrencyCatalogTest` currency-catalogue cluster that fails standalone and is untouched by this diff. The plan's "4,609 passed" figure predates it. Treat "N pre-existing failures on main" as volatile.
- The plan's browser verification (Tasks 1–3) is still unverified; the task-register regression is exactly the kind of defect that browser pass would have caught.

## Assessment

**Ready to merge: With fixes.** Close items 2 and 3 (and add the rendered-wiring assertion for item 1), then decide on the coordinator minors. Nothing found so far threatens data integrity, lock ordering or the guard's ability to reject an unguarded write.
