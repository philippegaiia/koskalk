# Review — bd103fb3 "fix: revalidate stale locales in all guidance modes"

**Branch:** `codex/ingredient-guidance-refresh` (worktree `.worktrees/ingredient-guidance-refresh`)
**Diff:** 4 files, +108 / −4
**Verdict:** Correct fix, well tested. **Finding #1 was retracted** — it is approved behaviour per the design spec, not a defect (see addendum). One real cleanup item remains: dead parameters.

> **Status:** `bd103fb3` merged into `main` as `33231bbf`. This review is archived; see addendum for corrections.

---

## What changed

`app/Services/IngredientEnrichment/IngredientGuidanceChangePlanner.php`

```diff
-if ($mode === IngredientEnrichmentBatchMode::GuidanceLocalization
-    && $currentTranslation?->source_fingerprint !== $canonicalTranslationFingerprint) {
+if ($currentTranslation?->source_fingerprint !== $canonicalTranslationFingerprint) {
```

A locale whose proposed text is **identical** to the stored text now gets a `revalidate`
decision in *both* guidance modes, not only `GuidanceLocalization`. Effect: re-stamp the
stale `source_fingerprint` instead of leaving the translation perpetually marked outdated.

Docs stayed in sync (`docs/superpowers/plans/2026-08-28-ingredient-guidance-refresh-corrections.md`).
Two tests added (planner-level + full approve/apply convergence).

---

## Verification performed

| Check | Result |
|---|---|
| `IngredientGuidanceChangePlannerTest` + `IngredientGuidanceBatchReviewTest` | 17 passed, 157 assertions |
| Wider suite (provenance schema, proposal editing, enrichment batch review, hybrid pipeline) | 39 passed, 317 assertions |
| Pint on all 3 changed PHP files | PASS |
| Downstream provenance effect | Empirically probed (temp test, since removed) |

No PHPStan/Larastan in this project — Pint is the only automated gate.

---

## Findings

### 1. RETRACTED — ~~MEDIUM: `revalidate` clobbers prior reviewer provenance~~

> **Retracted 2026-08-29.** The observation is accurate; my classification of it as a defect was
> wrong. The approved design spec mandates exactly this behaviour. See the addendum at the end of
> this document. Kept below for audit trail.

`ApplyIngredientGuidanceRefresh.php:114-124` builds the write intent:

```php
$reviewerEdited ? IngredientTranslationOrigin::ReviewerEdited : IngredientTranslationOrigin::AiGenerated,
$reviewerEdited ? null : $localizationPromptVersion,
$reviewerEdited || $revalidatedLocales->contains($locale),   // refreshMetadata
```

`$reviewerEdited` means "edited by a reviewer **in this batch**" — it does not consult the
stored origin. `IngredientTranslationService::sync()` (lines 105-117) then rewrites
`origin` **and** `prompt_version` whenever `refreshMetadata` is true, not just the fingerprint.

**Probe result:** a French row with `origin = reviewer_edited` and a stale fingerprint, in a
`GuidanceRefresh` batch where the AI returned byte-identical text, ended as:

```
origin before: 'reviewer_edited'
origin after:  'ai_generated'
prompt_version after: 'ingredient-guidance-localization-v1'
(text unchanged, fingerprint correctly refreshed)
```

Before this commit that path was unreachable in `GuidanceRefresh` (no `revalidate` → no forced
metadata refresh). It was already reachable in `GuidanceLocalization`.

This matters because provenance is clearly load-bearing here — see
`IngredientEnrichmentProvenanceSchemaTest` and the test at
`IngredientGuidanceBatchReviewTest.php:232` ("refreshes reviewer provenance when a locale is
edited back to its stored text"), which pins `ReviewerEdited` + `null` prompt version.

**Direction (not implemented):** on `revalidate`, refresh the fingerprint only; preserve
`origin`/`prompt_version` unless the text actually changed or the reviewer edited it in this batch.

### 2. LOW-MEDIUM — `$mode` is now dead in `plan()`

`$mode` (line 23) is no longer referenced in the body. `$editedFields` (line 24) was already
unused. Both call sites still pass them:

- `IngredientGuidanceRefreshProcessor.php:190` — `plan($currentIngredient, $normalized, $mode)`
- `IngredientGuidanceProposalReviewService.php:99` — `plan($ingredient, $normalized, $mode, $editedFields)`

Nothing in the toolchain will catch this. A parameter that *looks* like it drives mode-specific
behaviour but doesn't is a trap for the next reader — which is exactly what this commit just removed.
Recommend dropping `$mode` (and `$editedFields`), or documenting why they're retained.

### 3. LOW — Review-load increase (**intentional**, reframed)

Not accidental blast radius. The spec's error-handling invariants explicitly require
"revalidation-only changes remain human-review gated" (design line 147). Flagged only as an
operational heads-up for whoever runs the first post-merge `GuidanceRefresh`, not as a defect.

`IngredientGuidanceRefreshProcessor.php:190-200` keys item status off `plan['changed']`:

```php
$status = ! $plan['changed'] ? Unchanged : ($warnings === [] ? Ready : Warning);
```

Items that previously auto-skipped as `Unchanged` will now land in `Ready`/`Warning` and require
human review. Correct per the fix's intent, but expect a one-time surge of revalidate-only items on
the first `GuidanceRefresh` run — especially for legacy-imported translations.

### 4. LOW — Test coverage gaps

- No negative test for refresh mode: a locale with a **matching** fingerprint should *not* be
  revalidated. The existing negative (`IngredientGuidanceChangePlannerTest.php:88`) uses
  `GuidanceLocalization`. Adding the `GuidanceRefresh` counterpart locks the gate from both sides.
- No test pins the provenance interaction in finding #1.

### 5. NIT — Test duplication

The new ~80-line feature test largely mirrors the existing "revalidates an identical stale locale
without changing its text". A shared builder for the stale-translation fixture would shrink both.

### 6. NIT — Defensive gap in `plan()` (pre-existing, now wider)

`plan()` is public and unvalidated. A locale with no stored row plus an empty `info_markdown`
yields `null !== $fingerprint` → a `revalidate` decision on empty content. Currently unreachable:
`IngredientGuidanceRefreshResultValidator::normalizeTranslations()` rejects empty translations and
both call sites pass validated output. Low risk, but the planner has no guard of its own.

---

## Non-issues confirmed

- `changed` aggregation already counts `revalidate` (planner line 103) — no wiring change needed.
- Convergence is sound: the applier updates English *before* `sync()`
  (`ApplyIngredientGuidanceRefresh.php:79` vs `:125`), so the re-stamped fingerprint matches the
  canonical one and the locale won't be re-flagged next run. The new test asserts this.
- Mode widening is safe: `IngredientGuidanceChangePlanner` is only reached through guidance paths,
  and `ApplyIngredientGuidanceRefresh::handle()` guards with `! $mode->isGuidance()`.

---

## Tooling question — Laravel Boost / MCP

- **`laravel/boost` ^2.5 is installed** (dev), with `boost.json` and `php artisan boost:mcp`.
  Guidelines are synced to `AGENTS.md` / `CLAUDE.md` (`<laravel-boost-guidelines>` markers).
- **`laravel/mcp` is present**, but only as a Boost dependency — not a direct requirement, and no
  Truss MCP server is configured.
- **No MCP connector is wired into this session.** There is no `~/.workbuddy-ai/mcp.json`, and no
  Boost MCP tools are exposed to me. I read Boost's synced guidelines as static context; I did not
  call any MCP tool.

To get live Boost tooling, add a stdio MCP server running `php artisan boost:mcp` from this
worktree root. Boost's own `laravel-best-practices` and `pest-testing` skills (listed in
`boost.json`) would then be authoritative for this repo.

**Skill:** I did not route through a skill — this was a direct repo review. The matching skill for
this stack is **`senior-developer`** (Laravel/Livewire/FluxUI); `fullstack-dev` is the
backend/integration counterpart.

---

# Addendum — 2026-08-29 (corrections)

Reviewed against `docs/superpowers/specs/2026-08-28-ingredient-guidance-refresh-corrections-design.md`
(Status: **Approved**), which I did not read during the original review. My error was inferring
design intent from a test rather than from the governing spec.

## Finding #1 — retracted

The spec is explicit, twice:

> **Line 52:** "Applying an approved `revalidate` decision updates only the locale's source
> fingerprint, origin, and prompt version. It does not alter identity, localized names, or guidance text."

> **Line 101:** "Unmentioned and unchanged rows preserve their existing metadata. Reviewer-edited rows
> receive reviewer provenance. **AI-generated or revalidated rows receive AI provenance and the
> localization prompt version.**"

So `origin: reviewer_edited → ai_generated` on an approved revalidation is the specified outcome,
not a regression. The write-intent mechanism (`IngredientTranslationWriteIntent`, `refreshMetadata`)
exists precisely to distinguish these cases per locale.

My mistake was treating spec line 146 — *"Reviewer edits cannot silently inherit AI provenance"* —
as if it implied the converse (that AI revalidation must preserve prior human authorship). It does
not. Those are two different invariants, and the spec states only the first.

The test I cited (`IngredientGuidanceBatchReviewTest.php:232`) covers a reviewer edit **made in the
current batch**, which is correctly `reviewer_edited`. It was never evidence about the revalidation
path, and I over-read it.

**Agreed:** retaining historical human authorship across a later AI revalidation would require a
design change — a separate historical/audit field — not a conditional patch in this planner.
Out of scope for `bd103fb3`.

## Finding #3 — reframed, not a defect

Design line 147 requires revalidation-only changes to stay human-review gated. The increase in
reviewable items is the mechanism working as designed.

## What survives

1. **Dead `$mode` / `$editedFields` parameters** (finding #2) — confirmed, low-risk cleanup, not a
   merge blocker. Two production callers to update.
2. **A `GuidanceRefresh` negative test** — "already-current locale stays unchanged" — sensible
   addition, independently of the provenance question.
3. **Spec drift (new, non-blocking).** Design line 47 still scopes the invariant to *"A
   localization-only run is a reviewable metadata operation…"*, but `bd103fb3` widened the
   behaviour to **both** guidance modes. The commit updated the *plan* doc but not the *design*
   doc (`git show --stat bd103fb3` shows no change to `corrections-design.md`). If the widening was
   intended, line 47's wording should be generalised so the spec matches the shipped behaviour.

## Recommendation (unchanged from the codex review)

Keep `bd103fb3` as-is. Optionally land one small cleanup commit before release: drop the unused
parameters, add the `GuidanceRefresh` negative test, and align design line 47. No production
behaviour change from this review.
