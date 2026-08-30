# Project notes — koskalk

## Reviewing: read the governing spec before calling something a bug

This repo keeps approved design/spec docs under `docs/superpowers/specs/` and implementation plans
under `docs/superpowers/plans/`. **The spec is the authority on intent; tests are not.**

Learned the hard way on 2026-08-29 reviewing `bd103fb3`: I flagged that an approved `revalidate`
rewrites a translation's `origin` from `reviewer_edited` to `ai_generated`. That is mandated by the
approved design (lines 52 and 101 of
`2026-08-28-ingredient-guidance-refresh-corrections-design.md`), and the per-locale
`IngredientTranslationWriteIntent` exists precisely to express it. I inferred intent from a test
covering a *different* case and reported a non-defect as a medium finding.

Rules that follow:
- Before reporting behavioural findings, locate and read the relevant `docs/superpowers/specs/*.md`.
- Tests pin behaviour; they do not state intent. Do not over-read one test as a design invariant.
- Check for spec drift in both directions: code ahead of spec, and spec ahead of code.
- State plainly when a finding is retracted, rather than quietly dropping it.

## Ingredient enrichment / translation provenance

- A translation row carries `origin`, `prompt_version`, and `source_fingerprint`.
- `IngredientTranslationService::sync()` preserves existing metadata for unchanged rows **unless**
  the write intent sets `refreshMetadata` — then it rewrites fingerprint, origin, and prompt version.
- `revalidate` (text identical, fingerprint stale) and AI-generated rows both receive AI provenance
  plus the localization prompt version. Reviewer edits in the current batch get reviewer provenance
  with a null prompt version.
- Retaining *historical* human authorship across a later AI revalidation is not modelled. It would
  need a separate audit field and a design change, not a conditional patch.

## Conventions

- Commit subjects are conventional (`fix:`, `feat:`, `test:`, `refactor:`, `merge:`, `docs:`),
  lowercase. Plan/design docs go straight onto `main` with a `docs:` subject.
- A **graphify post-commit hook runs automatically** on every commit and rebuilds `graphify-out/`
  (~1500 files, a few seconds). Never run `graphify update .` manually — the hook already did it,
  and it is not a step worth listing in a plan.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches, merged to
  `main`. A worktree can disappear after merge — re-check `git worktree list` before using a path.
- Only automated quality gate is Pint. No PHPStan/Larastan configured, so unused parameters and
  similar issues are caught by nothing — mention them in review.
- Test helpers in Feature tests are often declared at the bottom of the file and are **file-scoped**,
  not global (e.g. `guidanceResult`, `guidanceApplyText` in `IngredientGuidanceBatchReviewTest.php`).
  Copy them into a scratch test rather than calling them cross-file.

## Git forensics: two traps that produced false conclusions

Both bit me on 2026-08-29 while attributing suite failures to a branch.

- **Attribution ranges die at merge.** `git log main..branch -- <file>` run *after* the merge is
  empty by definition, so every file reports "0 commits". Diff against the **pre-merge** main
  (`763a6034` for the production-bench merge) instead. An empty result from a post-merge range
  means nothing.
- **zsh eats `$var:path`.** `git show $c:config/foo.php` is parsed as a history modifier and
  explodes (`fatal: ambiguous argument '56726688onfig/...'`). Write `git show ${c}:config/foo.php`.
  Same class of problem: `grep --include=*.php` fails unquoted in zsh — use the Grep tool.
- When `git show <rev>:<path>` appears to fail, re-check with `git ls-tree -r --name-only <rev> -- <dir>`
  before concluding the file is absent. A silent `2>/dev/null` plus a blocked `/tmp` redirect once
  made me report a file as MISSING that was plainly in the tree.
- **Never let a probe fail silently.** `cmd 2>/dev/null | grep -c X` returning `0` is
  indistinguishable from `cmd` having failed. The `:r` history-modifier trap above produced a
  uniform `0` across eight commits and I reported it as a finding ("the file never contained this").
  Sanity-check a suspiciously uniform zero, or print a sentinel, before trusting it.

## CSS workflow: owner runs `npm run dev`; do NOT run `npm run build` unprompted

`package.json` has `dev: vite` and `build: vite build`. The owner develops against the **dev
server**, which serves from source with HMR — a CSS edit is visible on reload with no build step.
**Do not run `npm run build` after a CSS change** unless asked, or unless the point is specifically
to refresh the production artifact.

Corrected 2026-08-29: I twice treated a stale `public/build` as a defect ("the app still ships
2px") and rebuilt to close a "gap". It was not one. The owner never loads `public/build` locally,
so the stale bundle was invisible and irrelevant — unnecessary work, reported as a finding it did
not deserve.

Related facts, still true:
- `public/build` is gitignored and is the **deploy** artifact. It goes stale during local branch
  work; that is normal. Do not report it as a bug again.
- The design-polish contract tests read `resource_path('css/app.css')` — the **source**, never the
  bundle. The suite is green whether or not `public/build` is current. Only inspect the bundle when
  the question is what *production* will actually ship.
- Old orphaned bundles linger on disk (Vite does not appear to empty `buildDirectory`).

## Surfaces are lifted by shadow, never outlined

A panel reads as raised through elevation, not a rule around it: **`.sk-card` has no `border`.**
The reference implementation is the ingredients page (`sk-card` wrapper; internal `border-b` /
`border-t` / `divide-y` dividers only — the border is wrong on the *outer* box alone).

- The shadow lives in the `--shadow-card` token in the `soapkraft.css` `@theme` block, read by
  `.sk-card`, `.sk-nav-rail` and the generated `shadow-card` utility. Do not retype the literal.
- Two sibling utilities exist for elements that already carry their own border/radius:
  `.sk-card-elevation` and `.sk-card-elevation-subtle`, both in `soapkraft.css`.
- Owner override O6 (2026-08-30) converted the production bench nav rail and all 11 table shells
  from `rounded-2xl border … bg-[var(--color-panel)]` to `sk-card`. This is O1's rule from the
  other direction: O1 removed a container border that duplicated its child's edge; O6 removes
  them because the app does not draw panels that way at all.
- **Do not symmetrise `.sk-nav-rail`'s `padding-inline: 1.125rem 0.75rem`.** The 0.375rem start
  excess is the nesting signal — it sets the level-2 tabs inboard of the level-1 tabs above them.
  It was incidental while a 2px start border sat beside it; O6 removed the border, so the padding
  is now load-bearing.

## Undefined CSS custom properties fail silently — no build step catches them

`--color-panel-muted`, `--color-ink-muted` and `--color-surface-muted` were each used dozens of
times across the production bench while **never being defined**, in any stylesheet or commit.

- A `var()` that does not resolve makes the declaration *invalid at computed-value time*. For
  non-inherited properties (`background-color`) that falls back to the **initial** value, so the
  background silently goes transparent.
- For **inherited** properties (`color`) it falls back to `inherit` — the text does not vanish, it
  silently takes the parent's colour. That is why 47 elements looked merely "too dark" instead of
  obviously broken. Expect this asymmetry when diagnosing.
- Nothing in the toolchain flags it: Tailwind emits `var(--x)` verbatim and Vite does not resolve
  custom properties.
- Guard: `ProductionBenchLayoutTest::defines every colour token the production bench views
  reference` reads every `var(--color-*)` out of the production bench views and asserts the name is
  defined in one of the four stylesheets. It found two of the three on its first run.

## Filament actions hide markup from static contract tests

`resources/views/livewire/production-bench/inventory-index.blade.php:125` renders
`{{ $this->addStockAction }}` (defined `InventoryIndex.php:134`). Filament emits the `<button>` at
runtime, so **no `sk-btn sk-btn-primary` literal exists in the Blade source** even though the page
has a primary CTA. Static `file_get_contents` contract tests that assert rendered classes break
whenever a button is migrated to a Filament action — the fix is to assert the action slot
(`{{ $this->addStockAction }}`), not to conclude the button is missing. Same class of issue for
any `<x-filament-actions::modals />` page.

## Test suite: standing failures and how they arose

Triage lives in `docs/superpowers/plans/2026-08-29-test-suite-failures-triage.md`. Recurring causes:

- **`56726688` (ingredient guidance refresh) caused 4 of the 8 failures** by changing model casts
  (`mode` → `IngredientEnrichmentBatchMode`) and `config('ingredient-enrichment.schema_version')`
  3 → 4 without updating tests that pinned the old shapes. Check its blast radius when a test
  suddenly stops matching.
- **Schema-gated validation:** `IngredientEnrichmentResultValidator` runs its whole cross-field
  provenance block only when the payload is on the *current* schema (early `return` when
  `! $required`). Tests hard-coding an old `schema_version` silently pass strict rules by skipping
  them — so a "passing" test may prove nothing. Prefer `config('ingredient-enrichment.schema_version')`
  over a literal, as `IngredientEnrichmentProposalEditingTest` and `PromoteIngredientIntakeItemTest` do.
- **Superseded tests linger.** `b603f798` removed rich-editor uploads and added
  `RecipeContentMediaContractTest`, but never deleted the old `MediaStorageTest` case.
- **A test that "never passed" is a rare thing — prove it.** I claimed
  `WorkflowActionConsistencyTest` had never passed because `inventory-index.blade.php` "never had"
  a `sk-btn sk-btn-primary`. It did: the test passed 2026-08-02 → 2026-08-20, when `cf8efead`
  swapped the literal button for the Filament `addStockAction`. My per-commit counts were all
  bogus (see the zsh `:r` trap above). Before asserting a test never passed, confirm the
  measurement works on a commit where you *know* the value, and prefer
  `git log --all --oneline -S '<string>' -- <path>` to find the commit that changed it.
