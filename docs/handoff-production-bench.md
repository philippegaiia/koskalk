# Handoff — Production Bench (Checkpoints 0–4 delivered, UX improvements planned)

**Date:** 2026-08-07
**Branch:** `codex/ingredient-catalog-curation` (all work lives here; **do not merge to main / do not deploy** until the owner decides)
**Owner priorities right now:** ingredient catalog curation (in progress), Production Bench finishing work on this branch, waiting for the ingredient MVP list.

---

## Where the code stands

The Production Bench roadmap (docs/superpowers/plans/2026-07-28-production-bench-delivery-roadmap.md) has six checkpoints; **0–4 are delivered**, 5 (traceability) is not started.

- **Checkpoint 4 (execution)** is fully implemented and **reviewed twice — both APPROVED** (see the review summary below).
- Full suite: **1791+ passing**, 23 skipped, **1 pre-existing failure**: `tests/Feature/UserIngredientAuthoringTest` → "it shows one live fatty acid profile total…" — unrelated to Production Bench (ingredient-authoring domain; zero ingredient files in the bench commits). Do not chase it here.

### Lifecycle implemented (all actions in `app/Actions/Production/`)

- Create (draft or scheduled) → `CreateProductionDraft`, `PlanProduction`
- Rescale plan → `UpdateProductionPlan` (snapshot-only, in place)
- Prepare/release stock → `PrepareProductionStock` (partial allowed), `ReleaseProductionStock` (per-requirement)
- Numbering → planning references automatic; permanent via `AssignProductionBatchNumbers` (burned on delete; counter forward-only)
- Start → `StartProduction` (requires reserved + permanent number)
- Actuals → `SaveProductionActuals` (multi-lot, per requirement+lot)
- Complete → `CompleteProduction` / `ProductionCompletionService` (atomic: consumption → release reservations → costs → output lot → movement → close)
- Abort → `AbortProduction`; Cancel → `CancelProduction`
- Delete → `DeleteProductionRun` (draft/scheduled, no reservations; numbered runs deletable, number burned)
- Output → `ReleaseOutputLot` (blocked until ready date), `IssueFinishedGoods` (shipment/sample/damaged/internal use; intermediates subtract reservations)
- Journal → `SaveProductionJournalEntry` + private document attachments (`ProductionDocumentType::Journal`)
- Task sets/tasks → full (pinned at creation, no product fallback)

### Key design decisions to respect

- **No link to Basic `ProductionBatch`** (independent products; docs reconciled).
- **Formula snapshots are the run's truth** — `recipe_id`/`recipe_version_id` are nullable; runs never reopen a version; rescale reads snapshot columns only.
- **Ready dates**: output lots get `available_from` = last task's scheduled date, else +21 days (soap) / +3 days (cosmetic) from manufacture date — never user-entered.
- **Costing**: ingredient cost = grams × `historical_unit_cost` (per gram; receipts store grams). Prefer `costing_unit_cost`/`costing_currency`; mixed currencies reject completion.
- **SQLite trigger preservation** is critical: any migration that rebuilds `production_runs` or `stock_lots` must restore their triggers (the restore functions live in the Aug 6/7 migrations — copy them; the 9 `production_runs` + 2 `stock_lots` triggers).
- **Number formatting** everywhere via `NumberLocale` (`auth()->user()?->number_locale`); inputs parse via `normalizeDecimalString`.
- **Permissions**: all mutation controls gated by `mutationLocked = isReadOnly || ! canMutate`.
- **Lock order** in every production action: workspace → run → requirements → lots → reservations.
- **Interface translations**: `lang/en/*.php` + `database/seeders/data/interface-translations.json` must stay in sync — the seed is strictly sorted by `group.key` (the catalogue import enforces it; `php artisan translations:catalogue:import --mode=authoritative` validates).

### Migration state

All migrations through `2026_08_07_160000` exist. **The owner's live DB may be behind** — they are actively curating ingredients; do not run migrations without asking. (Earlier symptom: detail page crashed with `production_consumption does not exist` until migrations ran.)

### Uncommitted / dirty working tree

Pre-existing unrelated changes still uncommitted (do not stage unless asked): `app/Filament/Resources/Ingredients/Tables/IngredientsTable.php`, `composer.lock`, built Filament assets under `public/`, and `resources/views/layouts/app-shell.blade.php` + `tests/Feature/AppNotificationTest.php` (the session-error toast — the bench's archive/delete error messages render through it).

---

## Review history (both APPROVED)

1. **ProdExecReview (4e7d844..85fab0b)** — Critical 1×2 (per-gram cost 1000×, intermediate cost propagation), 8 Important, 3 Minor. All resolved in `docs/superpowers/plans/2026-08-07-production-execution-review-fixes.md` (13 tasks).
2. **ProdExecReviewR2 (574bdbb..002205d)** — APPROVED, 3 below-threshold observations; all dispositioned (partial index, required manufacture date, task-control gates, call-site comment).
3. **Review round three** (observations on the R2 output): task controls compound `isReadOnly` gates fixed; index-collapse left as-is (migration already run); output-validation call site commented.

---

## Next work (owner-approved direction)

1. **Production Bench UX improvements** — plan ready: `docs/superpowers/plans/2026-08-07-production-bench-ux-improvements.md` (7 tasks):
   - Reachable draft stage (date optional at creation; wire existing `ScheduleProduction`)
   - Statuses that shout (per-status badges + detail banner with the primary next action)
   - Partial-reservation signals ("short by X" on list + detail)
   - Production list as a table with header (date, batch size, expected units, tasks)
   - Compact inline task controls (narrow selects, single row)
   - Button hierarchy (visible danger actions, primary CTA per status)
2. **Checkpoint 5** (traceability, genealogy, search, print/export) — needs a detailed plan written from the design doc when requested.
3. Journal **rich-text editor** — deferred (attachments already wired).

## How to run

```bash
php artisan test --compact                                # full suite (expect 1 pre-existing failure)
php artisan test --compact tests/Feature/ProductionExecutionTest.php   # focused
vendor/bin/pint --dirty --format agent
npm run build
php artisan translations:catalogue:import --mode=authoritative   # validates seed sorting
graphify update .                                          # refresh knowledge graph
```

## Gotchas learned

- Livewire 4 treats `hydrate{Property}` as a lifecycle hook — never name a private method that way (`loadSavedActualRows`, not `hydrateActualRows`).
- Livewire tests: `toThrow(QueryException::class)` requires the import in the test file; unqualified class strings are treated as message matches.
- The `tasks()` relation applies its own `orderBy` — query `ProductionTask` directly when ordering differently.
- Receipts price by listing net quantity (not actual received quantity); `historical_unit_cost` is per gram.
