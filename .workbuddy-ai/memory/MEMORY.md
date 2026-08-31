# Project notes — koskalk

## Current status: production-bench inventory UX

`codex/production-bench-inventory-ux` is merged into local `main` at merge commit `643918a3`.
The final feature suite passed **2,788 / 25 skipped**, and the merged-main inventory suite passed
**220 / 0 failed**. The branch is not pushed merely by this local merge.

Do not attribute the current four full-suite failures to the inventory merge: three are caused by
the root checkout's local `.env` selecting Luna where the tests pin Terra, and one is a pre-existing
Composer queue-timeout contract mismatch (`--timeout=0` in first-parent `main`, test expects `300`).
The feature worktree is intentionally retained because it contains protected untracked notes/plans.
Manual viewer-role browser acceptance is the only remaining inventory acceptance observation;
automated authorization and no-write coverage is green.

## Reviewing: the spec is the authority on intent, not tests

Approved design docs live in `docs/superpowers/specs/`, plans in `docs/superpowers/plans/`.
Before reporting any behavioural finding, read the governing spec.

- Tests pin behaviour; they do not state intent. Do not over-read one test as an invariant.
- Check spec drift in both directions: code ahead of spec, and spec ahead of code.
- State plainly when a finding is retracted, rather than quietly dropping it.
- Learned 2026-08-29 reviewing `bd103fb3`: I reported a mandated behaviour (approved
  `revalidate` rewrites translation `origin` to `ai_generated`) as a defect, having inferred
  intent from a test covering a different case.

## Reviewing: four traps that each produced a wrong call

Hard-won across three reviews (`bd103fb3`, production-bench inventory rev 1 and rev 2).

- **An interface assertion is not a usage assertion.** Cleared `InventoryIndex` of "bypasses
  Filament forms" because it `implements HasForms`. It does — but the branch had deleted the
  `filtersForm()` the view rendered, replacing it with raw inputs. If the finding is about a
  view, read the view; if it is about a form, grep the blade for `{{ $this->...Form }}`.
- **Check the pre-branch version before calling something absent.** `git show <base>:<path>`
  separates "never had it" from "this branch removed it". The second is a regression and is
  materially worse than a gap.
- **Split a compound rule before ruling on it.** `.ai/rules/app.md:22` bundles isolation +
  `withoutGlobalScopes()->lockForUpdate()` + re-assert access inside transactions. I
  retracted a finding wholesale because one clause was moot, and wrongly cleared two others.
  Retract per-clause.
- **Check whether the thing being guarded is an input to the guard's own predicate.** I
  proposed adding `tracks()` to `SaveMaterialBuffer`, but `tracks()` is *derived from*
  settings (`WorkspaceMaterialCatalog::settingKeys()` — a buffer is what makes a material
  tracked). Self-referential preconditions deadlock.
- **Plan vs spec:** the spec is the authority on *intent*, but when the plan is explicit and
  deliberate about a schema detail, the plan wins for implementation (e.g. plan line 162
  deliberately omits `->nullable()` on `buffer_quantity` while making subject columns
  nullable). Flag the divergence for the spec, do not file it as a defect.

## Conventions

- Conventional, lowercase commit subjects (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`).
  Plan/design docs go straight onto `main` with a `docs:` subject.
- A **graphify post-commit hook runs automatically** on every commit and rebuilds
  `graphify-out/`. Never run `graphify update .` manually, and never list it as a plan step.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches.
  A worktree can vanish after merge — re-check `git worktree list` before using a path.
- Pint is the only automated quality gate; no PHPStan/Larastan. Unused parameters and similar
  are caught by nothing — raise them in review.
- Test helpers in Feature tests are declared at the bottom of the file and are **file-scoped**,
  not global. Copy them into a scratch test rather than calling them cross-file.

## `interface-translations.json` must stay sorted by group AND key

Adding a key to `database/seeders/data/interface-translations.json` in the wrong position fails six
tests with `InvalidInterfaceTranslationCatalogue: Catalogue translations must be sorted by group and
key.` The ordering is `strcmp` over `group.key`, so `validation.buffer_overflow` sorts **before**
`validation.buffer_precision` — insert alphabetically, never append. New keys also need all six
locales (de, es, fr, it, nl, pt_BR) or the same test fails.

## The Herd preview runs PostgreSQL, not SQLite

The test suite runs SQLite, but the Herd-served worktree previews point at a **PostgreSQL** database
(`koskalk_restore_20260722_023001` as of 2026-08-30). Anything whose behaviour differs by driver —
`SUM()` over `decimal`, NULL ordering, numeric overflow, alias visibility in `WHERE`, string quoting
in raw SQL — can only be checked for real on the preview, and a green suite proves nothing about it.

Practical consequences:
- Write raw SQL with **single quotes** for literals. Double quotes are identifiers in PostgreSQL, so
  `status = "active"` fails with `SQLSTATE[42703] Undefined column` where SQLite shrugs.
- The `decimal(20,9)` buffer overflow (#1) is a PostgreSQL-only 500 — verify it on the preview.
- Preview URL pattern: `http://<worktree-slug>.test`, site symlinks under
  `~/Library/Application Support/Herd/config/valet/Sites/`. This branch:
  `http://koskalk-inventory-ux.test`.

## Adding a lang key: two files, not one

A new `production_bench.*` message needs `lang/en/production_bench.php` **and**
`database/seeders/data/interface-translations.json` (all six locales, correctly sorted). Only the
English file is read at runtime by `__()`; the catalogue is what the translation test checks.

## Git forensics traps (each produced a false conclusion once)

- **Attribution ranges die at merge.** `git log main..branch -- <file>` is empty by definition
  after a merge. Diff against pre-merge main instead.
- **zsh eats `$var:path`.** Write `git show ${c}:config/foo.php`, not `$c:...` — the latter is a
  history modifier and explodes. Unquoted `grep --include=*.php` fails too; use the Grep tool.
- **Never let a probe fail silently.** `cmd 2>/dev/null | grep -c X` returning `0` is
  indistinguishable from `cmd` having failed. Sanity-check a suspiciously uniform zero, or print
  a sentinel, before trusting it.
- Before concluding a file is absent, re-check with `git ls-tree -r --name-only <rev> -- <dir>`.
- Before asserting a test "never passed", confirm the measurement on a commit where you *know*
  the value, and prefer `git log --all --oneline -S '<string>' -- <path>`.

## CSS: owner runs `npm run dev`; do NOT run `npm run build` unprompted

The owner develops against the dev server (HMR from source); a CSS edit is visible on reload
with no build step. Only build if the point is specifically to refresh the production artifact.

- `public/build` is gitignored and is the **deploy** artifact. It goes stale during local branch
  work — that is normal, not a bug. Old orphaned bundles also linger (Vite does not empty the
  build directory).
- Design-polish contract tests read `resource_path('css/app.css')` — the **source**, never the
  bundle. The suite is green whether or not `public/build` is current.

### Surfaces are lifted by shadow, never outlined

`.sk-card` has **no `border`**. Internal `border-b` / `border-t` / `divide-y` dividers only —
an outer border is wrong. Reference implementation: the ingredients page. The shadow lives in the
`--shadow-card` token in the `soapkraft.css` `@theme` block (do not retype the literal);
`.sk-card-elevation` / `.sk-card-elevation-subtle` exist for elements already carrying a
border/radius. Owner override O6 (2026-08-30) migrated the production bench nav rail and all 11
table shells from `rounded-2xl border … bg-[var(--color-panel)]` to `sk-card`.
**Do not symmetrise `.sk-nav-rail`'s `padding-inline: 1.125rem 0.75rem`** — the start excess is
the nesting signal that sets level-2 tabs inboard of level-1.

### Undefined CSS custom properties fail silently

`--color-panel-muted`, `--color-ink-muted`, `--color-surface-muted` were used dozens of times
while never being defined. A `var()` that does not resolve makes the declaration invalid at
computed-value time: non-inherited props (background) fall back to **initial** (transparent);
inherited props (color) fall back to **inherit**, so text merely looks wrong. Nothing in the
toolchain flags it — Tailwind emits `var(--x)` verbatim, Vite does not resolve custom properties.
Guard: `ProductionBenchLayoutTest` reads every `var(--color-*)` out of the production bench views
and asserts the name is defined in one of the four stylesheets.

## Filament actions hide markup from static contract tests

`inventory-index.blade.php` renders `{{ $this->addStockAction }}`; Filament emits the `<button>`
at runtime, so **no `sk-btn sk-btn-primary` literal exists in the Blade source** even though the
page has a primary CTA. Static `file_get_contents` contract tests must assert the action slot,
not the rendered class. Same for any `<x-filament-actions::modals />` page.

## Test suite: recurring failure causes

Triage doc: `docs/superpowers/plans/2026-08-29-test-suite-failures-triage.md`.

- **`56726688`** changed model casts and `config('ingredient-enrichment.schema_version')` 3 → 4
  without updating tests that pinned the old shapes — caused 4 of 8 failures. Check its blast
  radius when a test suddenly stops matching.
- **Schema-gated validation:** `IngredientEnrichmentResultValidator` early-returns its whole
  cross-field provenance block when the payload is not on the current schema. Tests hard-coding
  an old `schema_version` silently pass strict rules by skipping them, so a "passing" test may
  prove nothing. Prefer `config('ingredient-enrichment.schema_version')` over a literal.
- **Superseded tests linger** — e.g. `MediaStorageTest` survived the `b603f798` removal of
  rich-editor uploads that added `RecipeContentMediaContractTest`.

## SQLite SUM() is lossy on decimal columns — never push decimal aggregation into SQL

Measured 2026-08-30: SQLite `SUM()` over a `decimal(20,9)` column returns `typeof` = `real`.
`123456789.123456789 + 0.000000001 + 0.000000001` → `123456789.12345679` against an exact
`123456789.123456791` — a **1e-9 drift**, exactly the scale the column stores. PostgreSQL's
`SUM(numeric)` is exact, but the suite and the dev server run SQLite.

Consequence: when a plan says "use bcadd/bcsub/bccomp, never cast quantities to float",
row-by-row BCMath accumulation in PHP is **not** an inefficiency to be optimised away with
`SUM(CASE …)` — it is the only implementation that satisfies the line on both drivers. Verified
as the defence of `MaterialActivityService::groupTotals()`.

Corollary for `decimal(20,9)`: only **11 integer digits** are available. Any quantity entered in
a display unit and converted to canonical grams must be validated **after** conversion, not
before. `999999999` kg → 12 integer digits → PG `numeric field overflow` (500); SQLite shrugs,
so the suite cannot catch it.

## Server-rendered Alpine `x-data` options self-refresh — no `wire:key` or `replaceOptions()` needed

Verified 2026-08-30 in `vendor/livewire/livewire/dist/livewire.js` (Alpine is bundled there; there is
**no** separate alpinejs package in `node_modules`). Chain, with line numbers:

1. Livewire `patchAttributes` (`:14562-14590`) does `from.setAttribute(name, value)` whenever the
   value differs. **No skip list for `x-data`** — the only special case is `open` on `<dialog>`.
2. `_x_ignoreMutationObserver` (`:1216`) is read once and **never set** by Livewire, so Alpine's
   observer (`:1168`, `attributes: true, attributeOldValue: true`) fires on the morph.
3. `onAttributesAdded` (`:1813-1815`) re-runs `directives(el, attrs)`.
4. The `x-data` directive (`:4658`) early-returns only if the expression *string* is unchanged
   (`:4662`); otherwise it re-evaluates and, because the prior `cleanup` (`:4690-4694`) left
   `{ reactiveData }` on the element, calls `reconcileData` (`:4674-4676`, `:4702-4714`), which
   assigns every key — **including `options`** — onto the existing reactive object. Then `init()`
   re-runs (`:4689`).

So `<x-search-combobox :options="$serverComputed">` refreshes its options on re-render with no
extra wiring. Side effect: `reconcileData` also resets `query` / `open` / `activeIndex`, so transient
combobox state clears — usually desirable.

`replaceOptions()` (in `resources/js/search-combobox.js`) and `x-effect` are for **client-side**
option sources. The only `x-effect` caller, `recipe-workbench/packaging-tab.blade.php:31`, maps an
Alpine-side `packagingCatalog` array — a different problem. Do not cite it as evidence that
server-rendered options go stale.

## `.ai/rules` — read the operative clause, not the parenthetical

`.ai/rules/services.md:15` — "Resolve them from the container (method injection into
Livewire/controllers, constructor injection into Actions) — **never instantiate with `new`**."
The prohibition is on `new`. `app(SomeService::class)` *is* container resolution and satisfies
the rule; the parenthetical states a preference, not an exclusive mechanism.

Measured 2026-08-30 across `app/Livewire/**`: **15 of 39** files use `app()` (52 calls total,
`RecipeWorkbench` 12, `IngredientEditor` 9); only **6 of 39** use `boot()`. The dominant
injection site is `render()`/`mount()` method injection. Before filing "uses `app()` instead of
injection" as a violation, count the files it would fail — a rule that fails 15 files including
the owner's own components is not the house rule.
