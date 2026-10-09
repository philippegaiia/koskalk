> **Historical analysis — superseded for implementation.** Use [Contextual Print and Export Implementation Plan](2026-09-28-export-print-tier1-implementation.md), which incorporates the final UX review and current code findings. This document is retained as background; its task priorities and technical claims are not implementation instructions.

# Evaluation of the alternative export/print approach

Comparing the "follow the user's task on each view" proposal against the analysis in
`2026-09-28-export-and-print-actions-design.md` and the plan in
`2026-09-28-export-print-tier1-implementation.md`.

**Verdict: merge.** The alternative is better on artifact decomposition and UX principles,
weaker on grounding, and contains one proposal that would recreate a bug already present in
the codebase. Adopt 7 of its decisions, adjust 3, and keep 4 things it missed.

---

## Adopt — where it beats my analysis

1. **"Never silently export older numbers."** The workbench draft and the published version
   are different things, and a costing export built from stale numbers is worse than no
   export. I missed this entirely. It is the most important thing in the alternative.

2. **Batch sheet vs batch record are two artifacts, not one.** "Take this to the bench"
   (frozen formula, planned quantities, allocated lots, blanks for actuals) and "record
   what we actually made" (recorded consumption, lots, output, observations) are genuinely
   different documents at different lifecycle stages. I had a single "batch production
   record". Theirs is more faithful to how manufacturing works. Feasible — a
   `ProductionRunStatus` enum already exists
   (`app/Livewire/ProductionBench/Production/ProductionDetail.php:27`, used at line 355).

3. **Export filtered results across all pages, not just the current page.** A classic
   export bug. My plan said "respect filters" but did not say "all pages".

4. **A completed batch record must not drift.** It must not change because today's formula
   or ingredient price changed. This is a correctness requirement for a GMP-style record,
   and I did not state it.

5. **Drop the standalone method card.** Their argument for reusing the working formula
   document instead of creating a second 90%-identical one is correct YAGNI. My method card
   was redundant.

6. **"Ingredients to weigh" and "calculated finished composition" are different outputs**,
   and calling both "Export formula" makes files easy to misunderstand. Sharper naming
   discipline than mine.

7. **Spreadsheet hygiene:** numeric cells, a separate units column, clear columns, minimal
   decorative rows, and a generation date in the file. I under-specified all of this. Their
   boundary — "start with reusable data; an Excel calculator is a separate product
   decision" — is the right scope line.

## Adjust — where it is wrong or risky

1. **"Print formula & procedure" is a phantom artifact.** `recipes/print.blade.php` already
   renders the manufacturing procedure, conditionally, at lines 50-53. So the proposal's
   Formula row and its Instructions & media row would produce *the same document* under two
   labels — recreating precisely the five-duplicate-print-routes problem already in the
   codebase. One artifact, one label.

2. **Do not couple print to saving.** "Save formula and print" mutates state as a side
   effect of a print action. This project has unresolved questions about autosave behaviour,
   and silent version creation would surprise users. There is a simpler answer:
   `RecipeController::accessibleCurrentVersion()` (line 844) already resolves the
   work-in-progress draft, so an export can serve the draft directly with no save at all.
   Label the source explicitly — "Current draft" vs "Saved version" — and remove the need
   for the save coupling.

3. **Demoting the INCI print is half right.** It is right that the "paste into my label
   artwork" need is already served: `ingredient-list-preview.blade.php` has four copy
   buttons (generated INCI line 56, final INCI line 86, generated plain-language line 125,
   final plain line 154). So my Tier 1 rating was too high — **demote to Tier 2**. But do
   not delete it: a printable declaration for the product information file is still a real
   physical artifact, distinct from clipboard copy.

4. **Selected-rows export is scope creep for v1.** "When relevant" will become always.
   Leave it out.

5. **It is silent on throttling.** Every new route needs `throttle:workspace-exports` or
   `throttle:workspace-prints`, and `tests/Feature/HttpSecurityHardeningTest.php:57-64`
   already asserts this for `recipes.export.xlsx`.

## Keep — what it missed that mine has

1. **The five duplicate print routes.** Verified: `print.recipe`, `print.production`,
   `print.details`, `print.technical`, `print.costing` all render the identical
   `recipes.print` view; `$printMode` is accepted and never used
   (`app/Http/Controllers/RecipeController.php:813-836`). The alternative does not mention
   this, and its reuse-first instincts would inherit the broken structure.

2. **The ingredient price catalog.** The alternative never discusses the dashboard
   Ingredients list at all. In a costing-centric app that list is the user's price book.
   Keep it Tier 1.

3. **Feasibility grounding.** `RecipeVersionCostPreviewBuilder` already produces the full
   costing payload and already authorises via the recipe `view` gate (lines 38-39);
   `accessibleCurrentVersion()` exists; costing context is persisted on
   `RecipeVersionCosting`; OpenSpout is already installed. The alternative proposes "a real
   costing export" as though from scratch — it is far cheaper than implied.

4. **Production runs vs production batches.** It does not distinguish them.
   `production-batches.print` prints a *batch*; *runs* have no print at all.

## Agree — no change needed

Contextual placement beside the relevant tab heading or filters · format as the second
choice inside the export action · the materials export honouring the shortage filter rather
than a separate shortage report · stock-count sheet being distinct from a printed copy of
the table · picking sheet from stock preparation · packaging folded into costing rather than
standalone · leaving suppliers, settings and dashboards alone · keeping purchasing's
existing Print / Save PDF.

## Resulting merged Tier 1

1. Workbench · formula → **Print batch sheet** (quantities, settings, procedure, blanks for
   observations), labelled with which version it came from
2. Workbench · formula → **Export formula** (phases, ingredients, percentage basis,
   quantities, units)
3. Workbench · costing → **Export costing** (ingredient + packaging detail, batch quantity,
   finished units, currency, totals, cost per unit, missing prices flagged)
4. Inventory · materials → **Export materials**, all pages, filters honoured
5. Inventory · stock lots → **Export stock lots** + **Print stock count sheet**
6. Production · run, before/during → **Print batch sheet**
7. Production · run, completed → **Print batch record** (immutable)
8. Production · stock preparation → **Print picking sheet**
9. Ingredients index → **Export ingredient price catalog**

Moved out of Tier 1: INCI print (→ Tier 2, copy already covers the main need); standalone
method card (dropped). Moved in: nothing new beyond the above.

## Non-negotiable behaviours for every item

- Export honours filters **and spans all pages**.
- Recipe exports state whether they came from the current draft or the saved version; never
  silently stale.
- A completed batch record is immutable — it must not change when the formula or prices do.
- Every file is self-describing outside the app: product or batch identity, save reference,
  quantity basis, units, currency, generation date.
- Spreadsheets carry numeric cells, a separate units column, and minimal decorative rows.
- New routes are throttled and covered by `HttpSecurityHardeningTest`.
