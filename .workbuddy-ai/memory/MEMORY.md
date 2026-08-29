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

- Commit subjects are conventional (`fix:`, `feat:`, `test:`, `refactor:`, `merge:`), lowercase.
- Work happens in git **worktrees** under `.worktrees/<slug>` on `codex/<slug>` branches, merged to
  `main`. A worktree can disappear after merge — re-check `git worktree list` before using a path.
- Only automated quality gate is Pint. No PHPStan/Larastan configured, so unused parameters and
  similar issues are caught by nothing — mention them in review.
- Test helpers in Feature tests are often declared at the bottom of the file and are **file-scoped**,
  not global (e.g. `guidanceResult`, `guidanceApplyText` in `IngredientGuidanceBatchReviewTest.php`).
  Copy them into a scratch test rather than calling them cross-file.
