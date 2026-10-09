# Production editing protection — follow-up review (second pass)

**Date:** 2026-10-02
**Scope:** the current uncommitted working tree at base `d4644d09` (`main`), after the first review's confirmed findings were fixed.
**Method:** three independent reviewer passes on the *delta* (reload/revision-receipt redesign, output-lot validation restore, contested-claim verification), plus empirical verification by the coordinating agent — real test runs, real PHP probes, no build step.
**Intent authority:** `docs/superpowers/specs/2026-10-01-production-editing-protection-design.md`.
**Previous pass:** `.workbuddy-ai/reports/2026-10-02-production-editing-protection-code-review.md`.

---

## Verdict

**Ready to commit.** Every confirmed defect from the first pass is now fixed and independently verified. No Critical or Important issue remains open. I closed two residual Important/Minor items and the spec-required test-coverage gaps during this pass.

---

## What changed since the first pass

**Fixed by the other agent** (before this pass): the missing `productionRegister()` Alpine scope on the task register, the output-lot domain rejections, the vacuous opening-balance test, the client reload/refresh/lifecycle defects, the persistent `waiting` flag, and the unused group-blocked copy. Plus a new recorded rule (`.ai/rules/concerns.md`) and updated spec/plan docs.

**Fixed by me during this pass:**

| Change | File |
| --- | --- |
| Hardened the fixture so fixture misuse can no longer masquerade as a domain rejection | `tests/Support/ProductionEditingFixture.php` |
| Removed the dead `$production` binding | `app/Actions/Production/IssueFinishedGoods.php` |
| Cleared `reloadUnconfirmed` on the definitive no-receipt branch | `resources/js/production-editing.js` |
| Added the missing successful-backfill `edit_revision` assertions (bump once, idempotent second call does not re-bump) | `tests/Feature/ProductionFormulaSnapshotBackfillTest.php` |
| Added the release-endpoint authorization matrix (5 cases) | `tests/Feature/ProductionEditingReleaseTest.php` |
| Recorded the locale trap as a durable rule | `.ai/rules/tests.md` |

---

## Verification of the first pass's findings

All seven confirmed findings are genuinely closed, not worked around:

1. **Task-register Alpine scope** — `task-index.blade.php:3` now opens `<div x-data="productionRegister()" class="space-y-6">`, balanced by the closing `</div>`, and a rendered-wiring regression test now exists (`ProductionEditingRegistersTest`: "it renders task register write controls inside their Alpine command scope").
2. **Output-lot validation messages** — `ReleaseOutputLot` and `IssueFinishedGoods` throw `output_lot_workspace_missing`, `output_lot_unlinked` and `output_production_missing` before anything can derive a `0` parent, so no path falls through to the generic selection text. The changed-link case is now a validation error rather than a 404, re-checked under `lockForUpdate` inside the guarded transaction. **Authorization is not weakened**: the guard remains the sole write path, `assertWritable` runs before and (fresh-actor) inside the locked transaction, and a foreign-workspace lot is rejected before domain code.
3. **Vacuous opening-balance test** — now builds a valid `ProductionRun`-based context and pins the exact error keys, so a regression that let a non-output lot be released would fail the suite.
4. **Reload advancing revisions with dirty input** — fixed on *both* sides, which is what the first pass's proposed client-only fix missed. `reloadProductionEditing()` is `#[Renderless]` and only *prepares* a snapshot; nothing rebases until `acceptProductionReload()` is explicitly called. The client adopts only while clean and keeps writes disabled through polls until acceptance is confirmed.
5. **Presentation-refresh failure obscuring a save** — the refresh now lives in its own `refreshPresentation()` with its own catch, sets a separate `presentationFailed` message, and no longer disables writes or reports the save as failed.
6. **Lifecycle controls rejecting promises** — `operate()` returns `null` on error and `reload()` has its own catch with a visible `reload_failed` message.
7. **Persistent waiting state / unused group copy** — `waiting` is cleared on acquisition; `group_blocked` is now selected for multi-production selections.

The recorded rule in `.ai/rules/concerns.md` matches the shipped code clause for clause.

---

## Correction to my own earlier report

The first report relayed "74 failed / 4,537 passed, root-caused to a pre-existing `CurrencyCatalogTest` currency cluster" and suggested the plan's green figure was stale. **That was wrong, and I reproduced the disagreement to find out why.**

- Under my sandboxed shell (`LANG=C`, `Locale::getDefault()` empty) `CurrencyCatalogTest` fails 2 of 4: `Currencies::exists()` returns false for *every* currency, so `selectableCodes()` returns an empty list.
- With `LANG=en_US.UTF-8`, the same file passes **4/4**.
- `CurrencyCatalog` reads only Symfony Intl data — no database. Setting `Locale::setDefault('en')` makes `exists('EUR')` and `exists('HRK')` both true again.

So the currency failures are an artifact of a locale-less shell, not a defect and not a property of this repo. Codex was right. The cascade also explains the earlier session's inflated failure count. Recorded as a durable rule in `.ai/rules/tests.md` so the next agent does not repeat my mistake.

---

## Contested claims — settled from the code

| Claim | Verdict | Evidence |
| --- | --- | --- |
| "Expected-unit no-op regression" | **Rejected** | `UpdateProductionPlan::handle()` normalises with `(int)` at `:53`/`:153-163` before the `!==` comparison at `:87`, and the column casts to `integer` (`ProductionRun.php:196`). A no-op yields `changedProductionIds: []`, so no spurious revision bump. |
| "Make presentation refresh renderless" | **Rejected** | `refreshProductionPresentation()` writes `editingPresentation`, which is the server-side source for the committed tables (`ProductionDetail::production()`, `StockPreparation::render()`). Marking it renderless would leave the tables stale — it would break the feature. |
| "Backfill/idempotency entirely untested" | **Rejected, one nuance** | `ProductionFormulaSnapshotBackfillTest` covers the full backfill, idempotency and command-level partial failure; `ProductionEditingMaintenanceTest:18` covers the no-bump-on-skip. The *increment-on-success* assertion was genuinely missing — I added it. |
| Release authorization matrix + PostgreSQL migration integrity | **Coverage gap confirmed, implementation correct** | Guest 401, duplicates 422, downgraded Viewer permitted, cancelled Bench permitted, revoked membership 403 — all verified in code and now covered by tests. PostgreSQL `down()`/`up()` integrity objects remain SQLite-only. |

---

## Test evidence from this pass

All runs used `LANG=en_US.UTF-8` with Herd's `php85` (PHP 8.5.10) unless noted.

| Run | Result |
| --- | --- |
| Focused production-editing set (12 files) | **110 passed, 1,052 assertions, 0 failed** |
| Release + backfill + execution (3 files) | **63 passed, 309 assertions** |
| Release endpoint alone (with 5 new cases) | **10 passed, 29 assertions** |
| All fixture-dependent files (11 files) | **184 passed, 16 skipped** (opt-in PostgreSQL gate), **623 assertions** |
| Node client/draft tests | **43 passed, 0 failed** |
| `CurrencyCatalogTest` | **4 passed** with a UTF-8 locale; 2 failed under `LANG=C` |
| Pint (4 changed PHP files) | **clean** |
| `git diff --check` | **clean** |

I did **not** run the full suite — that remains the owner's call before release, and the plan's recorded 4,609-pass figure should be refreshed.

---

## Still open (deliberately not changed)

- **PostgreSQL `down()`/`up()` migration round trip** is unverified; the integrity-object assertions are SQLite-only. Needs the disposable PostgreSQL database and the existing identity gate.
- **`payload.revisions` is a dead write** (`production-editing.js:143`, `:198`) — written, read nowhere. Harmless today, but it advances a client mirror before acceptance, so it should be deleted or commented as vestigial rather than left to be wired up later.
- **`editingPendingReload` duplicates the full model graph** on a `#[Locked]` property that rides every Livewire snapshot, alongside the existing `editingPresentation`. Payload-size concern, not correctness.
- **`production-index.blade.php:3`** renders "Reload production" as the first control even when the Bench is inactive or read-only. Cosmetic; raised in both passes.
- **`presentationFailed` latches** until the next successful refresh or accepted reload — by design, but worth knowing.
- **`acceptProductionReload()` re-checks availability but not revision freshness**, so a change landing between prepare and accept yields a coherent stale page with writes blocked rather than an explicit rejection. Safe, but the reason is worth a comment.
