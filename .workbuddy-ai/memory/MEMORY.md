# Project notes — koskalk

## State as of 2026-09-03

`codex/ingredient-index-density` merged (fast-forward) into local `main` at **`9aad805b`** —
2893 passed / 25 skipped / 0 failed. **Unpushed:** `main` is 10 commits ahead of `origin/main`,
only 6 of them from that branch; the other 4 are the owner's own unpushed AI-enrichment work.
Do not push without asking.

Earlier: `codex/production-bench-inventory-ux` merged into local `main` at `643918a3` (2788 passed
/ 25 skipped; not pushed by that merge).

**Six pre-existing Pint failures on `main` are not from the density branch** — all inventory /
production-bench files. Scope Pint to a branch's own files before calling it dirty.

Four older known full-suite failures are NOT from the inventory merge: three are the root `.env`
selecting Luna where tests pin Terra, one is a pre-existing Composer queue-timeout contract
mismatch (`--timeout=0` vs expected `300`).

## Reviewing: habits that prevent wrong calls

- **The spec is the authority on intent, not the test.** Approved designs live in
  `docs/superpowers/specs/`, plans in `docs/superpowers/plans/`. Read the governing spec before
  reporting any behavioural finding. Check drift both ways (code ahead of spec, spec ahead of code).
- **State retractions plainly** rather than quietly dropping a finding.
- **An interface assertion is not a usage assertion.** `implements HasForms` says nothing about
  whether the Blade still renders `{{ $this->filtersForm }}`. Read the view for view findings.
- **Check the pre-branch version before calling something absent** — `git show <base>:<path>`
  separates "never had it" (gap) from "branch removed it" (regression, much worse).
- **Split compound rules before ruling.** Retract per-clause, never wholesale.
- **Check whether the guarded thing feeds the guard's own predicate** (self-referential
  preconditions deadlock — e.g. `tracks()` is derived from the settings a buffer writes).
- **Plan vs spec:** spec wins on intent, but an explicit deliberate plan detail wins on
  implementation. Flag divergence for the spec; do not file it as a defect.
- **Never trust a single headless-Chrome measurement.** `--dump-dom` runs are flaky by up to
  **15px — exactly one scrollbar** — and a harness with a retry loop reports the flake as a real
  number. Take ≥3 samples per width and keep the median; a value that is not reproduced across
  samples is a symptom, not a measurement.

## Conventions

- Conventional lowercase commit subjects (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`).
  Plan/design docs go straight onto `main` with a `docs:` subject.
- A **graphify post-commit hook runs automatically** on every commit and rebuilds
  `graphify-out/`. Never run `graphify update .` manually, never list it as a plan step.
- **`mcp__laravel-boost__record-rule` writes to the main checkout, not your worktree.** The Boost
  MCP server is configured against `/Users/philippe/Herd/koskalk`, so calling it while working in
  `.worktrees/<slug>` silently creates/edits `.ai/rules/*` **on `main`** — an untracked file plus a
  line in `index.md`, which then shows up as the owner's own dirty tree. After calling it: copy the
  file into the worktree, apply any `index.md` change there by hand, then delete the file from the
  main checkout and `git checkout -- .ai/rules/index.md`. Verify the `index.md` diff is only your
  line before reverting — the owner's tree routinely carries other people's uncommitted work.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches.
  A worktree can vanish after merge — re-check `git worktree list` before using a path.
- Pint is the only automated quality gate; no PHPStan/Larastan. Unused params etc. are caught by
  nothing — raise them in review.
- Test helpers in Feature tests are **file-scoped**, declared at the bottom. Copy them into a
  scratch test rather than calling cross-file.
- Check `git rev-parse --abbrev-ref HEAD` before every commit — the owner works in this same
  checkout and may have switched branches between my commands.

## Schema / data gotchas

- **SQLite `SUM()` is lossy on decimal.** `SUM()` over `decimal(20,9)` returns `real` — a 1e-9
  drift at exactly the stored scale. Never push decimal aggregation into SQL; row-by-row BCMath
  in PHP is the only implementation correct on both drivers (defends
  `MaterialActivityService::groupTotals()`).
- **`decimal(20,9)` leaves only 11 integer digits.** Validate quantities *after* unit conversion:
  `999999999` kg → PG `numeric field overflow` (500); SQLite shrugs, so the suite cannot catch it.
- **The Herd preview runs PostgreSQL, not SQLite** (`koskalk_restore_20260722_023001`). Driver-
  divergent behaviour (NULL ordering, alias visibility in `WHERE`, quoting) can only be verified
  on the preview. Use **single quotes** for SQL literals — double quotes are identifiers in PG.
  Preview URL: `http://<worktree-slug>.test`.
- **`interface-translations.json` must stay sorted by group AND key** (`strcmp` over `group.key`).
  Wrong position → `InvalidInterfaceTranslationCatalogue` in six tests. Insert alphabetically.
  New keys need all six locales (de, es, fr, it, nl, pt_BR).
- **A new lang key needs two files:** `lang/en/<file>.php` (what `__()` reads) *and*
  `database/seeders/data/interface-translations.json` (what the translation test checks).
- **…and a third place: the `language_lines` table.** The catalogue is the *reviewed
  source*, not the live store. Nothing in `database/seeders` imports it — it is a manual
  step. English resolves straight from `lang/en/` and is live immediately; every other
  locale comes from the DB and needs
  `translations:catalogue:import --mode=preserve-existing` (safe: adds missing rows only).
  `--mode=authoritative` overwrites, and would clobber translations edited by hand in the
  Filament InterfaceTranslations resource. `translations:sync` inserts missing keys only.
  Verify a translation change with `localizedShortLabel('de')`, not by reading the JSON —
  **if every locale returns the English string, that is the fallback firing, not success.**
  Measured 2026-09-03: zero `*.short_label` keys existed in the DB, and 170/170
  `categories.*.label` de/es/fr/it/nl values were `Kategorie: <English>` placeholders.
- **The 22 badge hues are generated, not hand-authored.** `scripts/badge-palette.mjs`
  (node, no deps) solves every text colour for 6:1 against its own background, pulls
  chroma back into sRGB, and reports contrast / gamut / Oklab ΔE. `node
  scripts/badge-palette.mjs --check` fails if `app.css` has drifted from it — the
  taxonomy test cannot catch that, since it sees only that a rule exists. Change the
  inputs in the script and regenerate; never hand-edit a single `oklch()` in `app.css`.

## Test suite: recurring failure causes

Triage doc: `docs/superpowers/plans/2026-08-29-test-suite-failures-triage.md`.

- **`56726688`** changed model casts and bumped
  `config('ingredient-enrichment.schema_version')` 3 → 4 without updating tests pinning the old
  shapes. Check its blast radius when a test suddenly stops matching.
- **Schema-gated validation:** `IngredientEnrichmentResultValidator` early-returns its whole
  cross-field provenance block when the payload is not on the current schema. Tests hard-coding an
  old `schema_version` silently pass strict rules by *skipping* them. Always use
  `config('ingredient-enrichment.schema_version')`, never a literal.
- **Superseded tests linger** (e.g. `MediaStorageTest` survived removal of rich-editor uploads).
- **Tests are hard-blocked from the real database.** `tests/Support/TestDatabaseSafety.php`
  (called from `tests/TestCase.php`) throws unless the connection is `sqlite: :memory:`. The only
  sanctioned exception is a **disposable `koskalk_fk_index_roundtrip_*`** PostgreSQL database, for
  explicit FK-index verification. `phpunit.xml` sets `DB_CONNECTION=sqlite` *without* `force`, so
  `DB_CONNECTION=pgsql ... artisan test` will get past PHPUnit and then be refused by the guard.
  **Do not work around it.** Consequence: you cannot render a page against the Herd/PostgreSQL
  database from the suite — driver-divergent behaviour can only be checked in a real browser.
- **`pest --parallel` ignores `-d memory_limit`.** ParaTest spawns worker processes that do not
  inherit the parent's `-d` flags, so `php -d memory_limit=1G vendor/bin/pest --parallel` still
  blows up at the default 128M (`HtmlSanitizerConfig.php` is the usual victim). For a full-suite
  run, drop `--parallel` and let the parent's `-d memory_limit=2G` apply — ~127s for 2910 tests.
  `phpunit.xml` sets no `memory_limit` and no `passthru-php`, so there is nothing to lean on.

## CSS

- **Owner runs `npm run dev`; do NOT run `npm run build` unprompted.** `public/build` is gitignored
  and is the *deploy* artifact — it goes stale during branch work, which is normal.
- Design-polish contract tests read `resource_path('css/app.css')` — the **source**, never the
  bundle. Suite is green whether or not `public/build` is current.
- **Surfaces are lifted by shadow, never outlined.** `.sk-card` has no `border` (internal
  `border-b`/`divide-y` only). Shadow lives in the `--shadow-card` token. Reference: ingredients
  page. Do **not** symmetrise `.sk-nav-rail`'s `padding-inline: 1.125rem 0.75rem` — the start
  excess is the nesting signal for level-2 tabs.
- **Undefined CSS custom properties fail silently.** A `var()` that does not resolve is invalid at
  computed-value time: non-inherited props (background) fall back to **initial** (transparent);
  inherited props (color) fall back to **inherit**. Nothing flags it. Guard:
  `ProductionBenchLayoutTest` asserts every `var(--color-*)` in production bench views is defined
  in one of the four stylesheets.
- **Per-category badge colours cannot ride on `getColor()`.** Filament's `HasColor` collapses
  categories into semantic buckets (Koskalk has 5× `gray`, 3× `danger`); a categorical palette
  with one hue per case cannot be derived from that grouping. The fix is
  `IngredientCategory::badgeVariant()` returning `$this->value` — the CSS emits
  `sk-badge-<value>` and `getColor()` is left untouched for Filament. Literals in `app.css`,
  not `--color-*` tokens: this is a purpose-built categorical scale, not a face of the semantic
  palette. Contract test in `IngredientTaxonomyTest.php` parses each `.sk-badge-<x> { body }` from
  `app.css` and asserts both `background-color:` and `color:` are present, plus all 22 variants
  unique. Palette generator (oklch → Oklab → linear sRGB → WCAG) is the **committed**
  `scripts/badge-palette.mjs` — see the schema section above; re-tuning any value by hand
  breaks AA parity — regenerate the whole scale.
- **`.sk-badge-inert` is a 23rd rule that is not a category and is not generated.** It is the
  null-category fallback, emitted by `ingredients-index.blade.php:162` as
  `sk-badge-{{ $ingredient->category?->badgeVariant() ?? 'inert' }}`. Written in
  `--color-panel-strong` / `--color-ink-soft` **tokens**, deliberately, so it sits outside both
  the taxonomy test and `badge-palette.mjs --check` — **nothing catches its deletion.** It reads
  as dead CSS under a literal grep because the class is composed at runtime; it is not.
  0 of 170 live ingredients have a null category, so it is unexercised but correct to keep.

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
injection…") states a preference, not an exclusive mechanism. Measured: 15 of 39 Livewire files
use `app()`. Do not file that as a violation — a rule failing the owner's own components is not
the house rule.

## Git forensics traps (each produced a false conclusion once)

- **Attribution ranges die at merge.** `git log main..branch -- <file>` is empty by definition
  after a merge. Diff against pre-merge main instead.
- **zsh eats `$var:path`.** Write `git show ${c}:config/foo.php`. Unquoted `grep --include=*.php`
  also fails — use the Grep tool.
- **Never let a probe fail silently.** `cmd 2>/dev/null | grep -c X` returning `0` is
  indistinguishable from `cmd` having failed. Print a sentinel before trusting a uniform zero.
- **A literal grep for a runtime-composed string always returns nothing, and reads as "dead".**
  `grep -rn 'sk-badge-inert'` found only the CSS definition — but the class is built as
  `sk-badge-{{ $x ?? 'inert' }}` in Blade, so it is live. Search the *interpolation site*
  (`?? 'inert'`, `sk-badge-{{`), never the composed result. "No literal reference" ≠ "no
  reference". This applies to CSS classes, translation keys, and route names alike.
- **macOS/BSD `grep` has no `-P`.** Use `sed -n "s/…/\1/p"` for lookaround-style extraction,
  or the Grep tool. `grep -oP` fails with an unhelpful "invalid option -- P".
- Before concluding a file is absent, re-check `git ls-tree -r --name-only <rev> -- <dir>`.
- Before asserting a test "never passed", verify the measurement on a commit where the value is
  known; prefer `git log --all --oneline -S '<string>' -- <path>`.
- **A diffstat does not distinguish "created" from "extended."** Files I described as added by a
  branch were 11/8/42-line stubs at the base. The `create mode` lines are easy to skim past; run
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
- **Scope Pint to the branch before calling it dirty.** `main` carries failures from other people's
  merges (6 as of 2026-09-03, all inventory/production-bench files). Run
  `git diff --name-only <base>..<tip> | grep '\.php$' | xargs php vendor/bin/pint --test`.
  Note `pint --test $FILES` with a quoted variable fails — it treats the whole list as one filename.
  Use `xargs`.
- **`main` runs ahead of `origin/main` on other people's commits.** Before pushing, split them:
  `git rev-list origin/main..<pre-merge main> --count` vs the branch's own count. Pushing
  publishes the owner's unpushed work as a side effect — always ask first.

## Serving / previewing

- **`koskalk.test` needs no symlink.** Herd *parks* `~/Herd`, so `/Users/philippe/Herd/koskalk`
  is served automatically — it will **not** appear in `config/valet/Sites/`. Worktree previews
  do need a symlink there (`herd link`, or `herd-worktree-preview`).
- **Check `public/hot` before believing `public/build`.** If `public/hot` exists (it holds the
  Vite dev-server URL, e.g. `https://koskalk.test:5173`), `@vite` serves **from source** and the
  build directory is irrelevant. If it does not, Blade falls back to `public/build` — which is
  gitignored, stale, and will silently serve an *old* CSS bundle. **Merging to `main` is not the
  same as the owner seeing the change**; verify against the dev server, and remember that
  stopping it re-exposes the stale bundle. Only the owner runs `npm run build`.
- Verify a CSS change is actually served with:
  `curl -s "$(cat public/hot)/resources/css/app.css" | grep -c '<your-new-class>'`.

## Environment

- The **`herd` CLI is on PATH** at `/Users/philippe/Library/Application Support/Herd/bin/herd`
  (verify with `command -v herd` before hand-editing anything under Herd's config). An earlier note
  claimed it was not; re-check rather than trusting it. `herd unlink <site>` removes only the
  symlink in `~/Library/Application Support/Herd/config/valet/Sites/`.
- **PHP is not on PATH.** Use
  `/Users/philippe/Library/Application Support/Herd/bin/php85`.
- **`php artisan test` does not forward `-d memory_limit`** to the Pest subprocess — the run dies at
  the default 128M inside `blade-icons/IconsManifest.php`. Invoke Pest directly instead:
  `php85 -d memory_limit=1024M vendor/bin/pest`. `phpunit.xml` already pins `sqlite :memory:` and
  `APP_ENV=testing`, so the database needs no special handling at the root checkout.
