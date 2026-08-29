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

## CSS fixes need `npm run build` — the suite cannot catch a stale bundle

`public/build` is gitignored, and the design-polish contract tests read
`resource_path('css/app.css')` — the **source**, never the compiled output. So a CSS-only fix can
be fully green in CI while the app still ships the old value. Confirmed on 2026-08-29: the 1px
focus fix passed the suite, but the built bundle still carried `outline:2px` until `npm run build`
was run manually. **After any change under `resources/css/`, rebuild.** Old orphaned bundles also
linger on disk (Vite here does not appear to empty `buildDirectory`).

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
- **Some tests never passed.** `WorkflowActionConsistencyTest` has asserted a primary button in
  `inventory-index.blade.php` since 2026-08-02; that file never had one. Confirm with
  `git log --all --oneline -S '<string>' -- <path>` before assuming a regression.
