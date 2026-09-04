# Project notes — koskalk

## State as of 2026-09-04

`main` at `9aad805b` (density branch `codex/ingredient-index-density` merged --ff-only; 2893 passed
/ 25 skipped / 0 failed). Uncommitted right now: the price-input `text-right` fix in
`ingredients-index.blade.php`.

**Unpushed: `main` is 13 ahead / 0 behind `origin/main`** — only 9 of those are ours (6 density + 3
cleanup); 4 are the owner's own AI-enrichment work. **Never push without asking** — publishing ours
publishes theirs too. Split with `git rev-list origin/main..<pre-merge main> --count`.

Known-failing on `main`, none of it ours:
- 6 Pint failures, all inventory / production-bench files.
- 4 older full-suite failures: three from the root `.env` selecting Luna where tests pin Terra; one
  a pre-existing Composer queue-timeout contract mismatch (`--timeout=0` vs expected `300`).

## Reviewing: habits that prevent wrong calls

- **The spec is the authority on intent, not the test.** Approved designs in
  `docs/superpowers/specs/`, plans in `docs/superpowers/plans/`. Read the governing spec before
  reporting a behavioural finding; check drift both ways (code ahead of spec, spec ahead of code).
  Spec wins on intent, but an explicit deliberate plan detail wins on implementation — flag
  divergence for the spec, don't file it as a defect.
- **State retractions plainly**; never quietly drop a finding. **Retract per-clause**, never
  wholesale — split compound rules before ruling.
- **An interface assertion is not a usage assertion.** `implements HasForms` says nothing about
  whether the Blade still renders `{{ $this->filtersForm }}`. Read the view for view findings.
- **Check the pre-branch version before calling something absent** — `git show <base>:<path>`
  separates "never had it" (gap) from "branch removed it" (regression, much worse).
- **Check whether the guarded thing feeds the guard's own predicate** — self-referential
  preconditions deadlock (e.g. `tracks()` derived from the settings a buffer writes).
- **Never trust a single headless-Chrome measurement.** `--dump-dom` is flaky by up to **15px —
  exactly one scrollbar** — and a retry loop reports the flake as a real number. Take ≥3 samples
  per width, keep the median. A value not reproduced across samples is a symptom, not a measurement.

## Conventions

- Conventional lowercase commit subjects (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`). Plan /
  design docs go straight onto `main` with a `docs:` subject.
- A **graphify post-commit hook runs automatically** and rebuilds `graphify-out/`. Never run
  `graphify update .` manually, never list it as a plan step.
- **Check `git rev-parse --abbrev-ref HEAD` before every commit** — the owner works in this same
  checkout and may have switched branches between my commands. Leave HEAD where they had it.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches. A
  worktree can vanish after merge — re-check `git worktree list` before using a path.
- Pint is the only automated gate; no PHPStan/Larastan. Unused params etc. are caught by nothing —
  raise them in review.
- Test helpers in Feature tests are **file-scoped**, declared at the bottom. Copy them into a
  scratch test rather than calling cross-file.
- **Under `.workbuddy-ai/`: commit `memory/`, `artifacts/`, `reports/`; ignore only `skills/`.** An
  untracked `memory/YYYY-MM-DD.md` is a *missed commit*, not a deliberate omission (logs are
  tracked since 2026-08-29). But `.workbuddy-ai/skills/` is a **generated mirror** of the
  gitignored `.agents/skills/`, rebuilt by `scripts/sync-boost-skills.sh` — committing it is like
  committing `node_modules`. Never ignore `.workbuddy-ai/` wholesale.
- **Agent tooling dirs are gitignored as a rule** (`/.agents`, `/.codex`, `/.gemini`, `/.hermes`,
  `/.superpowers`, `/.worktrees`). If a new tool drops a dir at the repo root, check whether it is
  generated before letting it show up as untracked.
- **`mcp__laravel-boost__record-rule` writes to the main checkout, not your worktree.** Boost is
  configured against `/Users/philippe/Herd/koskalk`, so calling it from `.worktrees/<slug>`
  silently creates/edits `.ai/rules/*` **on `main`**. After calling it: copy the file into the
  worktree, apply any `index.md` change by hand, then delete it from the main checkout and
  `git checkout -- .ai/rules/index.md`. Verify the `index.md` diff is only your line first — the
  owner's tree routinely carries other people's uncommitted work.

## Schema / data gotchas

- **SQLite `SUM()` is lossy on decimal** — returns `real`, a 1e-9 drift at exactly the stored
  scale. Never push decimal aggregation into SQL; row-by-row BCMath in PHP is the only
  implementation correct on both drivers (defends `MaterialActivityService::groupTotals()`).
- **`decimal(20,9)` leaves only 11 integer digits.** Validate quantities *after* unit conversion:
  `999999999` kg → PG `numeric field overflow` (500); SQLite shrugs, so the suite can't catch it.
- **The Herd preview runs PostgreSQL, not SQLite** (`koskalk_restore_20260722_023001`).
  Driver-divergent behaviour (NULL ordering, alias visibility in `WHERE`, quoting) is only
  verifiable there. Use **single quotes** for SQL literals — double quotes are identifiers in PG.
- **`interface-translations.json` must stay sorted by group AND key** (`strcmp` over `group.key`).
  Wrong position → `InvalidInterfaceTranslationCatalogue` in six tests. New keys need all six
  locales (de, es, fr, it, nl, pt_BR).
- **A new lang key needs three places:** (1) `lang/en/<file>.php` — what `__()` reads, live
  immediately for English; (2) `database/seeders/data/interface-translations.json` — what the
  translation test checks; (3) the **`language_lines` table** — the live store for every other
  locale. Nothing in `database/seeders` imports the catalogue; it is a manual step:
  `translations:catalogue:import --mode=preserve-existing` (adds missing rows only).
  `--mode=authoritative` overwrites and would clobber hand-edits made in the Filament
  InterfaceTranslations resource. `translations:sync` inserts missing keys only.
  **Verify with `localizedShortLabel('de')`, not by reading the JSON — if every locale returns the
  English string, that is the fallback firing, not success.** Measured 2026-09-03: zero
  `*.short_label` rows existed, and 170/170 `categories.*.label` de/es/fr/it/nl values were
  `Kategorie: <English>` placeholders.
- **The 22 badge hues are generated, not hand-authored.** `scripts/badge-palette.mjs` (node, no
  deps) solves every text colour for 6:1 against its own background, pulls chroma back into sRGB,
  and reports contrast / gamut / Oklab ΔE. `node scripts/badge-palette.mjs --check` fails if
  `app.css` has drifted — the taxonomy test can't catch that, since it only sees that a rule
  exists. Change the inputs and regenerate; never hand-edit a single `oklch()` in `app.css`.

## Test suite: recurring failure causes

Triage doc: `docs/superpowers/plans/2026-08-29-test-suite-failures-triage.md`.

- **`56726688`** changed model casts and bumped
  `config('ingredient-enrichment.schema_version')` 3 → 4 without updating tests pinning the old
  shapes. Check its blast radius when a test suddenly stops matching.
- **Schema-gated validation:** `IngredientEnrichmentResultValidator` early-returns its whole
  cross-field provenance block when the payload isn't on the current schema. Tests hard-coding an
  old `schema_version` silently pass strict rules by *skipping* them. Always use
  `config('ingredient-enrichment.schema_version')`, never a literal.
- **Superseded tests linger** (e.g. `MediaStorageTest` survived removal of rich-editor uploads).
- **Tests are hard-blocked from the real database.** `tests/Support/TestDatabaseSafety.php`
  (from `tests/TestCase.php`) throws unless the connection is `sqlite: :memory:`. The only
  sanctioned exception is a disposable `koskalk_fk_index_roundtrip_*` PG database for explicit
  FK-index verification. `phpunit.xml` sets `DB_CONNECTION=sqlite` *without* `force`, so
  `DB_CONNECTION=pgsql ... artisan test` gets past PHPUnit and is then refused by the guard.
  **Do not work around it.** Consequence: you cannot render a page against the Herd/PG database
  from the suite — driver divergence can only be checked in a real browser.
- **`pest --parallel` ignores `-d memory_limit`.** ParaTest workers don't inherit the parent's `-d`
  flags, so `php -d memory_limit=1G vendor/bin/pest --parallel` still dies at 128M
  (`HtmlSanitizerConfig.php` is the usual victim). For a full suite drop `--parallel` and let the
  parent's `-d memory_limit=2G` apply — ~127s for 2910 tests. `phpunit.xml` sets no `memory_limit`
  and no `passthru-php`, so there's nothing to lean on.

## CSS

- **Owner runs `npm run dev`; do NOT run `npm run build` unprompted.** `public/build` is gitignored
  and is the *deploy* artifact — it goes stale during branch work, which is normal.
- Design-polish contract tests read `resource_path('css/app.css')` — the **source**, never the
  bundle. The suite is green whether or not `public/build` is current.
- **Surfaces are lifted by shadow, never outlined.** `.sk-card` has no `border` (internal
  `border-b` / `divide-y` only). Shadow lives in `--shadow-card`. Reference: ingredients page.
  Do **not** symmetrise `.sk-nav-rail`'s `padding-inline: 1.125rem 0.75rem` — the start excess is
  the nesting signal for level-2 tabs.
- **Undefined CSS custom properties fail silently.** An unresolvable `var()` is invalid at
  computed-value time: non-inherited props (background) fall back to **initial** (transparent);
  inherited props (color) fall back to **inherit**. Nothing flags it. Guard:
  `ProductionBenchLayoutTest` asserts every `var(--color-*)` in production bench views is defined
  in one of the four stylesheets.
- **Per-category badge colours cannot ride on `getColor()`.** Filament's `HasColor` collapses
  categories into semantic buckets (Koskalk has 5× `gray`, 3× `danger`); a categorical palette with
  one hue per case can't be derived from that. The fix is `IngredientCategory::badgeVariant()`
  returning `$this->value` — CSS emits `sk-badge-<value>` and `getColor()` is left untouched for
  Filament. Literals in `app.css`, not `--color-*` tokens: this is a purpose-built categorical
  scale, not a face of the semantic palette. Contract test in `IngredientTaxonomyTest.php` parses
  each `.sk-badge-<x> { body }` from `app.css` and asserts both `background-color:` and `color:`
  are present, plus all 22 variants unique.
- **`.sk-badge-inert` is a 23rd rule that is not a category and is not generated** — the
  null-category fallback, emitted by `ingredients-index.blade.php:162` as
  `sk-badge-{{ $ingredient->category?->badgeVariant() ?? 'inert' }}`. Written in
  `--color-panel-strong` / `--color-ink-soft` **tokens**, deliberately, so it sits outside both
  the taxonomy test and `badge-palette.mjs --check` — **nothing catches its deletion.** It reads as
  dead CSS under a literal grep because the class is composed at runtime; it is not. 0 of 170 live
  ingredients have a null category, so it is unexercised but correct to keep.
- **Decimal alignment.** `.sk-decimal-aligned` (`app.css:572`, top level in `@layer components`, so
  **global** — not scoped to `.sk-workbench`) sets `text-align: start` and
  `padding-inline-start: max(0.75rem, calc(50% - var(--sk-decimal-offset)))`, parking the separator
  on the **50% centre line**; the `max()` clamp stops long values pushing past the left edge. It
  needs `decimalAlignmentStyle(value)`
  (`resources/js/recipe-workbench/sections/formula-section.js:736`, emits
  `--sk-decimal-offset: <int-digits>ch`, bound via `:style`) plus
  `x-effect="syncFormattedInput($el, v, decimals)"` (rewrites to fixed decimals, **bailing out
  while focused** so it never fights typing) paired with `@blur="normalizeDecimalBlur($event)"`
  (`costing-section.js:617`). **`.numeric` (tabular-nums) is a hard prerequisite — without it the
  `ch` math breaks.** Used only on per-row workbench table inputs (`reaction-core`,
  `post-reaction`, `cosmetic-formula`); standalone settings fields use plain `numeric` with no
  alignment; read-only values use `numeric … text-right` (`fatty-acid-profile`).
  **Nothing in `tests/` pins any of it.**
- **A decimal anchor is only needed when the fraction length varies — otherwise use `text-right`.**
  Where decimals are *fixed* (price, always 2 via `formatDecimal`), `text-right` aligns it
  perfectly with zero new code. **Check the fraction length before reaching for the anchor.**
  Done 2026-09-04 on the ingredient-index price input — one utility class, `.numeric` was already
  there.
- **Measured 2026-09-04: 36 of the 40 `sk-decimal-aligned` call sites don't need the JS.** 30 pass a
  literal `format(x, N)`; 4 use `additionWeightDecimals` (profile `addition` has **no magnitude
  term** — 3 for g/oz, 4 for kg/lb, constant down a column); 2 are costing inputs at fixed 2.
  **Only 4 genuinely vary:** `oilWeightDecimals` (reaction-core 127/130/153) — g: `>=1000 ? 1 : 2`,
  oz: `>=1 ? 2 : 3`; and `formatPackagingQuantity` (costing-tab 170) — integer → 0 decimals, else
  3 (**3-char drift**, the worst case). `oilUnit` is a **single user-selectable scalar for the whole
  formula** (`component.js:492`, `changeOilUnit`), NOT auto-promoted g→kg, so the unit is constant
  down a column — only the magnitude thresholds in `massDisplayDecimals` (`mass.js:58`) can vary
  the fraction length. **Root cause is the per-row decimal count, not the alignment technique:**
  make it uniform per column and the whole mechanism is deletable — but that changes displayed
  precision (`1200.0`→`1200.00`, `4`→`4.000`), so it's a product decision. Plan:
  `docs/superpowers/plans/2026-09-04-decimal-alignment-consolidation.md`.
- **Padding-right that varies per value BREAKS decimal alignment.** Separator x =
  `right edge − padding-right − (fraction × 1ch)`, so a per-row computed padding moves the
  separator with the value. "Conditional" padding is only safe when the condition is not the
  number itself (this-input-only, breakpoint…).
- **`--font-numeric` is monospace** (`ui-monospace, SFMono-Regular, Menlo…`, `soapkraft.css:4`) —
  `1ch` = one glyph advance exactly (≈8.4px at 14px), separator included, so `tabular-nums` is
  belt-and-braces. Makes `ch` arithmetic exact and predictable.
- **Tailwind utilities beat `.sk-input`'s padding without `!important`.** `.sk-input` is in
  `@layer components` (`app.css:213`); Tailwind emits `pr-*` into `@layer utilities`, which is
  registered after `components`. **Layer order beats specificity**, so `pr-6` overrides the
  `padding: 0.75rem 1rem` shorthand.
- `decimalAlignmentStyle` / `syncFormattedInput` are **methods inside `createFormulaSection()`**,
  not standalone exports, so "just import them" doesn't work — extract to a shared module first.
  `.sk-decimal-aligned` *is* reusable as-is (top level in `@layer components`).
- **All number formatting deliberately omits thousands grouping** — that is what makes a decimal
  anchor viable. PHP `NumberLocale::formatDecimal($v, n, $locale)` and JS `formatNumber(v, n,
  locale)` (`Intl.NumberFormat`, `useGrouping: false`) both give exactly n decimals, no separators,
  locale-aware `.`/`,`. Input side: `formatAdaptiveDecimal` (trailing zeros trimmed) and
  `formatDecimalInput` (0–12 decimals). Price unit is **kg** or **lb** only
  (`MassDisplaySystem::priceUnit()`), never g — integer parts run 1–5 digits.

## Frontend / Livewire

- **Filament actions hide markup from static contract tests.** The page renders
  `{{ $this->addStockAction }}`; Filament emits the `<button>` at runtime, so no `sk-btn` literal
  exists in the Blade source. Assert the action slot, not the rendered class.
- **Server-rendered Alpine `x-data` options self-refresh** — no `wire:key` or `replaceOptions()`
  needed. Livewire's `patchAttributes` re-sets `x-data` when the expression string changes; Alpine
  re-runs `directives()`, and `reconcileData` assigns every key (incl. `options`) onto the existing
  reactive object. Side effect: transient combobox state (`query`/`open`/`activeIndex`) clears.
  `replaceOptions()` and `x-effect` are for *client-side* option sources only. Alpine is bundled
  inside `vendor/livewire/livewire/dist/livewire.js` — no separate alpinejs package.

## `.ai/rules` — read the operative clause, not the parenthetical

`services.md:15` prohibits instantiating services **with `new`**. `app(SomeService::class)` *is*
container resolution and satisfies the rule; the parenthetical ("method injection… constructor
injection…") states a preference, not an exclusive mechanism. Measured: 15 of 39 Livewire files use
`app()`. Don't file that as a violation — a rule failing the owner's own components is not the
house rule.

## Git forensics traps (each produced a false conclusion once)

- **Attribution ranges die at merge.** `git log main..branch -- <file>` is empty by definition
  after a merge. Diff against pre-merge main instead.
- **zsh eats `$var:path`.** Write `git show ${c}:config/foo.php`. Unquoted `grep --include=*.php`
  also fails — use the Grep tool.
- **Never let a probe fail silently.** `cmd 2>/dev/null | grep -c X` returning `0` is
  indistinguishable from `cmd` having failed. Print a sentinel before trusting a uniform zero.
- **A literal grep for a runtime-composed string always returns nothing, and reads as "dead."**
  Search the *interpolation site* (`?? 'inert'`, `sk-badge-{{`), never the composed result. "No
  literal reference" ≠ "no reference". Applies to CSS classes, translation keys, route names alike.
- **macOS/BSD `grep` has no `-P`.** Use `sed -n "s/…/\1/p"` for lookaround-style extraction, or the
  Grep tool. `grep -oP` fails with an unhelpful "invalid option -- P".
- Before concluding a file is absent, re-check `git ls-tree -r --name-only <rev> -- <dir>`.
- Before asserting a test "never passed", verify the measurement on a commit where the value is
  known; prefer `git log --all --oneline -S '<string>' -- <path>`.
- **A diffstat does not distinguish "created" from "extended."** Files I described as added were
  11/8/42-line stubs at the base. `create mode` lines are easy to skim past; run
  `git show <base>:<path>` for anything you plan to call new.
- **Never trust `git merge-tree` for conflict prediction** — it printed `our`/`their` stages and
  still exited 0. Use `git rev-list main..HEAD --count` / `HEAD..main --count`, and
  `git merge-base --is-ancestor main <branch>` to prove a fast-forward is possible. (`merge-base`
  has no `--short` flag; compare full SHAs.)

## Merging into `main` (the owner's own checkout)

- **The tree is routinely dirty with other sessions' work** — agent memory, `.workbuddy-ai/skills/`,
  untracked `docs/` write-ups. **Never `git clean` or `git stash` it.** Snapshot
  `git status --porcelain` + `shasum` of the untracked files before, compare after, and report the
  count. A `--ff-only` merge only moves the branch pointer, so it *cannot* touch untracked files —
  but verify that rather than asserting it.
- **Prove the merge introduced nothing:** `git rev-parse main^{tree}` must equal the branch tip's
  tree. If those match, the branch's own green test run transfers to `main` verbatim.
- **Scope Pint to the branch before calling it dirty:**
  `git diff --name-only <base>..<tip> | grep '\.php$' | xargs php vendor/bin/pint --test`.
  `pint --test $FILES` with a quoted variable fails — it treats the whole list as one filename.
  Use `xargs`.

## Serving / previewing

- **`koskalk.test` needs no symlink.** Herd *parks* `~/Herd`, so `/Users/philippe/Herd/koskalk` is
  served automatically — it will **not** appear in `config/valet/Sites/`. Worktree previews *do*
  need a symlink there (`herd link`, or `herd-worktree-preview`).
- **Check `public/hot` before believing `public/build`.** If `public/hot` exists (it holds the Vite
  dev-server URL, e.g. `https://koskalk.test:5173`), `@vite` serves **from source** and the build
  directory is irrelevant. If it doesn't, Blade falls back to `public/build` — gitignored, stale,
  and it will silently serve an *old* CSS bundle. **Merging to `main` is not the same as the owner
  seeing the change.** Stopping the dev server re-exposes the stale bundle — as of 2026-09-04 the
  server is down and `public/build` (2026-08-30) carries **0 of the 22 badge hues**.
  Verify with: `curl -s "$(cat public/hot)/resources/css/app.css" | grep -c '<your-new-class>'`.

## Environment

- The **`herd` CLI is on PATH** at `/Users/philippe/Library/Application Support/Herd/bin/herd`
  (verify with `command -v herd` before hand-editing anything under Herd's config). An earlier note
  claimed it was not; re-check rather than trusting it. `herd unlink <site>` removes only the
  symlink in `~/Library/Application Support/Herd/config/valet/Sites/`.
- **PHP is not on PATH.** Use
  `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **`php artisan test` does not forward `-d memory_limit`** to the Pest subprocess — the run dies at
  128M inside `blade-icons/IconsManifest.php`. Invoke Pest directly:
  `php85 -d memory_limit=1024M vendor/bin/pest`. `phpunit.xml` already pins `sqlite :memory:` and
  `APP_ENV=testing`, so the database needs no special handling at the root checkout.
