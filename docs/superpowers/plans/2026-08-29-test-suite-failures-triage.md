# Test suite failures triage — 2026-08-29

> **Status: superseded for execution.**
> There is a second, more actionable plan for the same eight failures:
> `docs/superpowers/plans/2026-08-29-full-suite-failure-remediation.md`.
> It has per-task commands, commit boundaries and build/format steps, and its remediation is
> stronger than this document's in two places (see §6 and "Where the sibling plan is better").
> **Use that one to execute.** Keep this one for the attribution analysis and the two caveats
> it adds that the other lacks (§4 knock-on risk, §5 dead-code follow-up).
> A factual error in §6 of this document has been corrected — see the retraction note there.

Baseline: full suite on `main` at `e5387850` → **8 failed / 2648 passed**.

## The question

> "is it related to not updating the tests"

**Mostly yes — but not entirely.** Six of the eight are stale tests that the code moved past.
One is a genuine regression from the production-bench navigation branch. The last was a real
behaviour change whose test was never updated. (An earlier version of this document claimed
one had "never passed" — that was wrong; see §6.)

| # | Test | Root cause | Mine? | Fix size |
|---|------|-----------|-------|----------|
| 1 | `StartIngredientIntakeResearchTest` ×3 datasets | enum cast added to model | no | 1 line |
| 2 | `RecipeWorkbenchDesignPolishTest` | **2px focus ring I added** | **yes** | 1 line |
| 3 | `SearchComboboxAdoptionTest` | view state renamed | no | 1 line |
| 4 | `IngredientEnrichmentTrustDimensionsTest` | hard-coded `schema_version` 3 vs current 4 | no | 1 line |
| 5 | `MediaStorageTest` | upload path deliberately removed | no | delete test |
| 6 | `WorkflowActionConsistencyTest` | literal button replaced by Filament action | no | 1 line |

Attribution was re-verified with `git log 763a6034..codex/production-bench-navigation-hierarchy -- <file>`
(pre-merge main, not post-merge — an earlier check of mine used the post-merge range, which is
empty by definition, and wrongly reported a clean bill of health). Result: the branch touched
**only `resources/css/app.css`** among the files these tests read.

---

## 1. `StartIngredientIntakeResearchTest` — mode enum cast (3 failures, 1 test)

One dataset-driven test (`1`, `10`, `70` rows) failing three times.

- Fails at `tests/Feature/StartIngredientIntakeResearchTest.php:44`
- `Failed asserting that App\Enums\IngredientEnrichmentBatchMode Enum #8551 (Intake, 'intake') is identical to 'intake'.`

`56726688 feat: separate ingredient guidance refresh workflow` added
`'mode' => IngredientEnrichmentBatchMode::class` at `app/Models/IngredientEnrichmentBatch.php:71`.
The test compares the resulting enum instance to the raw string.

Telling detail: the assertion immediately above compares `status` enum-to-enum and passes. The
author just never came back for `mode`.

**Fix:** change to `->toBe(IngredientEnrichmentBatchMode::Intake)` and import the enum.

## 2. `RecipeWorkbenchDesignPolishTest` — 2px focus ring (REGRESSION, mine)

- Fails at `tests/Feature/RecipeWorkbenchDesignPolishTest.php:877`
- `Expecting '@import 'tailwindcss'…' not to contain 'outline: 2px solid var(--color-accent);'`

`resources/css/app.css:487`:

```css
.sk-nav-item:focus-visible {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
}
```

Introduced by `f2c7d38b` on the navigation branch. Measured:

```
pre-merge main 763a6034:  2px=0   1px=1
HEAD (merged):            2px=1   1px=1
```

This is not a fussy assertion. The whole app standardises on a **1px** accent focus ring —
`resources/css/shared/soapkraft.css` (`button:focus-visible, a:focus-visible { outline: 1px solid var(--color-accent) }`),
and Filament uses `box-shadow: inset 0 0 0 1px var(--color-accent)`. The test exists to stop that
token drifting. I broke the token; the test is right.

No test anywhere asserts the 2px value, so the change is safe.

**Fix:** `outline: 2px solid var(--color-accent);` → `1px` at `app.css:487`.

## 3. `SearchComboboxAdoptionTest` — stale overflow assertion

- Fails at `tests/Feature/SearchComboboxAdoptionTest.php:39`
- Expects `isFormulaSettingsOpen ? 'overflow-visible' : 'overflow-hidden'`
- View has `formulaSettingsOverflow ? 'overflow-visible' : 'overflow-hidden'`
  (`resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php:52`)

`e37901f4 fix: animate the formula settings panel when opening` split the two concerns. Both
properties are real and both are still used:

- `isFormulaSettingsOpen` — collapse state, still at blade lines 20, 44, 48, 51
- `formulaSettingsOverflow` — declared at `resources/js/recipe-workbench/component.js:208`
  (`initialDraft === null`) and driven by a timer at lines 369–382: cleared while the panel
  animates, restored afterwards so a combobox dropdown is not clipped mid-transition

**The application code is correct. The test is stale.**

**Fix:** update the assertion to `formulaSettingsOverflow ? 'overflow-visible' : 'overflow-hidden'`.
The sibling plan additionally pins the mechanism in the component source, which is worth doing —
verified present verbatim:

- `resources/js/recipe-workbench/component.js:208` — `formulaSettingsOverflow: initialDraft === null,`
- `resources/js/recipe-workbench/component.js:380` — `this.formulaSettingsOverflowTimer = setTimeout(() => {`

> Do **not** fix this by editing the blade back to `isFormulaSettingsOpen`. That would
> re-introduce the clipping bug the timer exists to prevent.

## 4. `IngredientEnrichmentTrustDimensionsTest` — hard-coded schema version (subtlest)

- Fails at `tests/Feature/IngredientEnrichmentTrustDimensionsTest.php:65`
- `Failed asserting that true is false` (expects `valid === false`)

The rule this test is checking **does exist and does work** —
`app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php:971-975` raises
`soap_ai_requires_identity` when an `ai_proposed` salt has no reliable base identity, via
`hasReliableBaseIdentity()` (line 1008). Its message at `lang/en/ingredient_enrichment.php:153`
reads *"A proposed soap declaration requires a reliable base identity; otherwise leave it
unresolved."* — which is exactly the string the test greps for.

The reason it never fires is nine lines earlier, at line 928:

```php
if (! $required || ! is_array($proposal)) {
    return;
}
```

`$required` is `$isCurrentSchema`. The entire cross-field provenance block (lines 932–976),
including the salt rule, is skipped whenever the payload is not on the current schema.

`trustResult()` hard-codes `'schema_version' => 3` (line 131), while
`config/ingredient-enrichment.php:6` is now `4` — bumped by the same `56726688`.

**Fix:** replace the literal with `config('ingredient-enrichment.schema_version')`. That is the
pattern already used at `IngredientEnrichmentProposalEditingTest.php:184` and
`PromoteIngredientIntakeItemTest.php:238`.

**Knock-on to verify, not assume.** The sibling test *"keeps an AI-proposed NaOH salt
independent from an unresolved KOH salt"* (line 40) currently passes **for the wrong reason** —
with schema 3 the strict block is skipped, so it asserts `valid === true` trivially. Reading the
rule, it should still pass after the bump (it sets `inci_name` to confidence `verified` and
provenance `source_confirmed`, so `hasReliableBaseIdentity()` returns true and no error fires),
but that must be confirmed by running the file, not by reasoning about it.

## 5. `MediaStorageTest` — obsolete superseded test

- Fails at `tests/Feature/MediaStorageTest.php:135`
- `ValidationException: The product description is text-only. Choose images from the Media Library.`
  thrown from `app/Services/RecipeRichContentAttachmentProvider.php:132`

`saveUploadedFileAttachment()` now throws unconditionally (lines 130–135). This was deliberate:
`b603f798 fix: refuse rich editor uploads that recipe validation rejects` (2026-08-21). That
commit **also added the replacement coverage** — `tests/Feature/RecipeContentMediaContractTest.php`,
which asserts the rejection for both `description` and `manufacturing_instructions` (lines
207–216). It runs 21 tests, all passing today.

The old test was simply never removed.

**Fix (two acceptable options; prefer the first).** The sibling plan replaces the obsolete
success-path test with a rejection test — assert `ValidationException` with the
`description_text_only` message **and** `Storage::disk('local')->allFiles()` is empty. That is
better than deleting outright: it keeps a guard in the storage file rather than relying entirely
on a test in another file. Straight deletion also works, since `RecipeContentMediaContractTest`
covers the same contract.

**Follow-up worth logging, not blocking:** the bounded-conversion path it covered is now dead.
`config/media.php:42` still defines `recipe_rich_content_images` (680×680, quality 80) and
`app/Services/MediaStorage.php:115-130` still exposes accessors for it, but nothing in `app/`
consumes them any more — `b603f798` removed the only caller. Those accessors are dead code.

## 6. `WorkflowActionConsistencyTest` — literal button replaced by a Filament action

- Fails at `tests/Feature/WorkflowActionConsistencyTest.php:43`
- Expects `resources/views/livewire/production-bench/inventory-index.blade.php` to contain the
  literal `class="sk-btn sk-btn-primary"`

The page *does* have a primary action — it is just no longer a literal in the Blade file.
`inventory-index.blade.php:125` renders `{{ $this->addStockAction }}`, backed by
`app/Livewire/ProductionBench/InventoryIndex.php:134`. Filament emits the button at runtime, so
the class string never appears in the source.

Real history, measured per-commit:

| commit | `sk-btn sk-btn-primary` | `addStockAction` |
| --- | --- | --- |
| `1562495f` file created 2026-07-28 | 0 | 0 |
| `afe20cc2` **test authored** 2026-08-02 | **1** | 0 |
| `cf8efead^` | 1 | 0 |
| `cf8efead` Improve manual inventory stock entry (2026-08-20) | 0 | **1** |
| `40c6fffc` | 0 | 1 |
| `HEAD` | 0 | 1 |

`cf8efead` removed `<button type="submit" class="sk-btn sk-btn-primary">Add lot</button>` and put
the Filament action in its place. It reached main via `4c26750b merge: integrate workspace
material codes`. **The test passed from 2026-08-02 until that merge and broke on 2026-08-20.**
Not caused by my branch (0 commits to that file).

> **Retraction.** An earlier version of this document said the file "has zero primary buttons at
> every commit since creation" and that the test "has never passed". Both statements were wrong.
> The measurement that produced them was broken: the shell loop used `git show $c:resources/...`
> in zsh, which parses `:r` as a history modifier and mangles the path, so `git show` failed
> silently behind `2>/dev/null` and every count came back `0`. Using `${c}` gives the table above.

**Fix** (matches the sibling plan): assert the action slot instead of the rendered class.

```php
->toContain('{{ $this->addStockAction }}')
```

Keep the `->not->toContain('class="rounded-full bg-[var(--color-accent)] px-5 py-2.5')` half, which
still protects the migration away from the old rounded markup. `ProductionBenchInventoryModalTest.php`
already covers the action's existence and Filament colour, so don't duplicate that here.

---

## Execution order

This document is analysis, not the runbook. Use
`docs/superpowers/plans/2026-08-29-full-suite-failure-remediation.md`, which has the same ordering
with per-task commands and commits.

Rough order either way: **#2** first (the only production regression, `app.css:487` 2px → 1px),
then the four test-only fixes (#1, #3, #4, #5), then #6.

Two things the sibling plan does that this one omitted and that are worth keeping:

- `vendor/bin/pint --dirty` on the changed PHP.
- `npm run build` after the CSS change, so the emitted bundle matches the 1px contract.

## Where the sibling plan is better

1. **#6** — it identified the Filament `addStockAction` boundary and produced a real fix. This
   document wrongly declared the failure unfixable without a product decision.
2. **#5** — it replaces the obsolete test with a rejection test (throw + no file written) rather
   than deleting it outright.
3. **#3** — it also pins the component-side mechanism, not just the Blade string, so the
   clipping guard survives a future rename.
4. It carries the build/format/graph verification steps this document lacks.

## Two caveats to carry into that plan

- **#4 knock-on.** Task 2 Step 3 states "all trust-dimension tests pass" as an expectation. The
  sibling test at line 40 currently passes only because the strict provenance block is skipped for
  schema 3; after the bump it is actually exercised. Reading the rule it should still pass
  (`inci_name` is `verified` / `source_confirmed`), but that must be confirmed by a run. If it
  fails, fix the test on its merits — do not weaken the line-65 assertion to make it green.
- **#5 dead code.** Removing the upload path orphaned `MediaStorage.php:115-130`: the
  `recipe_rich_content_images` bounds accessors still exist and `config/media.php:42` still
  defines the values, but nothing in `app/` calls them any more. Log it; don't fold it into this
  remediation.

## Open decisions

- **#5 follow-up:** remove the now-dead `MediaStorage` rich-content bounds accessors, or leave
  them for when a new caller arrives?

## Note on this triage

Half of these failures trace to a single commit — `56726688 feat: separate ingredient guidance
refresh workflow` — which caused #1 (enum cast) and #4 (schema bump), i.e. four of the eight
failures. That commit changed model casts and config without updating the tests that pinned the
old shapes. Worth a look at whether it missed anything else: `git show --stat 56726688`.
