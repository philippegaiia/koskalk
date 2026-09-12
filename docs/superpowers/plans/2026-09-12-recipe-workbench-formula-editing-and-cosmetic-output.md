# Recipe Workbench Formula Editing and Cosmetic Output Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make cosmetic formula editing clearer and safer, align the soap and cosmetic row interfaces, and describe cosmetic results as formula composition and labeling rather than chemical output.

**Architecture:** Keep the existing Alpine workbench state, persistence payloads, and calculation rules. Percentage remains the default entry mode; weight mode continues to derive percentages and, for cosmetics or soap oils, the formula/base total from entered quantities. Add one shared entry-mode toolbar above the formula ledger and one reusable row-actions popover for both benches. Ingredient removal is immediate and recoverable through one-level local Undo; cosmetic-phase removal uses the reusable confirmation dialog. Final label-list replacement keeps its own one-level Undo. Cosmetic output remains derived from the same formula rows and labeling snapshot, but its information architecture and wording stop implying a chemical transformation.

**Tech Stack:** PHP 8.5, Laravel 13.30, Blade, Alpine.js/Livewire 4.4, Tailwind CSS 4.2, Vite 8, Pest 4.7.

---

## Required execution topology

- Orchestrator: `gpt-5.6-sol` at `high` reasoning. It owns rule loading, task sequencing, implementation review, integration, and final verification.
- Implementer: `gpt-5.6-luna` at `max` reasoning. It writes the focused tests and production changes for each delegated task.
- The Sol orchestrator reviews each Luna implementation against this plan before accepting it or delegating the next task. It remains responsible for running the integrated regression set, the frontend build, Pint when applicable, and `graphify update .`.

---

## Settled product decisions

- Cosmetic and soap formula row percentages stay at two decimals; cosmetic row weights stay at three decimals.
- An aggregate percentage total displays without trailing decimals when its two-decimal readout is an integer (`100%`), but retains two decimals when needed to expose an imbalance (`99.75%`). Never round `99.6%` to a visually misleading `100%`.
- Percentage entry remains the default. Weight entry is not a second calculation model: it updates the affected quantities, derives percentages, and updates the cosmetic total batch or soap total oils exactly as the current code does.
- The cosmetic formula quantity, formula table weight total, and cosmetic output weight total use the same derived total. At a valid 100% formula this equals the configured total batch quantity.
- Cosmetic output is named and explained as formula composition and labeling. It does not use “ingredient output” or transformation language.
- In cosmetic Formula composition, the INCI/labeling name is the primary row label and the common ingredient name appears beneath it. When INCI is missing, fall back to the common name without rendering the same text twice.
- “Ingredient-browser row” means a result in the left ingredient search/selection list before it is added to the formula. Keep that selection row common-name-led and do not add a persistent INCI subtitle in this change; its inspector remains the detailed disclosure path.
- Formula balance keeps its current severity and still blocks Save when the displayed two-decimal total is not 100%.
- Removing an ingredient row happens immediately and offers one-level Undo. Removing a cosmetic phase requires an accessible confirmation dialog; when populated, the dialog states how many ingredients will also be removed. Replacing or clearing an editable final label list remains a lightweight action and keeps a separate one-level Undo.

## Deliberately out of scope

- No changes to recipe, version, or production database schema.
- No changes to payload field names such as `editing_mode`, `oil_weight`, or `phase_items`.
- No new cosmetic chemistry or ingredient transformation layer.
- No persistent INCI subtitle in the left ingredient search/selection results. Revisit only if testing shows users cannot distinguish supplier/trade-name variants, identical display names with different declarations, or search results whose INCI match is otherwise invisible; the existing inspector remains the disclosure path for now.
- No softer warning state for an unbalanced formula.
- No dependency changes and no replacement of the existing drag-and-drop implementation.

## File map

- Create `resources/views/components/recipe-workbench/entry-mode-toggle.blade.php` for the shared percentage/weight control and mode-specific helper text.
- Create `resources/views/components/recipe-workbench/formula-row-actions.blade.php` for keyboard/touch reorder, cross-phase movement, and the remove request.
- Create `resources/views/livewire/dashboard/partials/recipe-workbench/formula-confirmation-modal.blade.php` for cosmetic-phase confirmation.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php` to place the shared entry control above the ledger and include one confirmation dialog.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php` to remove the duplicated hidden entry-mode controls while retaining batch quantity/unit settings.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php`, `post-reaction.blade.php`, and `cosmetic-formula.blade.php` to use the same responsive row/action contract.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php` only to make the cosmetic phase chooser an accessible focus-managed popover; do not add an INCI subtitle.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php` and `ingredient-list-preview.blade.php` for cosmetic terminology, consistent totals/precision, and list Undo.
- Modify `resources/js/recipe-workbench/component.js` for row movement, ingredient-removal Undo, and phase-confirmation state.
- Modify `resources/js/recipe-workbench/sections/formula-section.js` for aggregate-total formatting and localized entry-mode summaries.
- Modify `resources/js/recipe-workbench/sections/presentation-section.js` for cosmetic helper copy, shared cosmetic formula quantity, and final-list Undo state.
- Modify `resources/views/components/action-icon.blade.php` only if the shared row-actions trigger needs a missing `more-horizontal` icon.
- Modify `lang/en/workbench.php` and `database/seeders/data/interface-translations.json` together for every new or revised interface string.
- Modify the focused Workbench tests listed below; do not add a browser-test dependency.

### Task 1: Lock the revised numeric and calculation contract with tests

**Files:**
- Modify: `tests/Feature/RecipeWorkbenchNumericFormattingTest.php`
- Modify: `tests/Feature/RecipeWorkbenchMassInteractionTest.php`
- Modify: `tests/Feature/CosmeticRecipeWorkbenchTest.php`
- Modify: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`

- [ ] **Step 1: Add a locale-aware aggregate-total formatting test**

Add a Node-backed test to `RecipeWorkbenchNumericFormattingTest.php` that builds `createFormulaSection()` with the existing `format()` and `number()` stubs and asserts:

```js
assert.equal(workbench.formatPercentageTotal(100), '100');
assert.equal(workbench.formatPercentageTotal(99.75), '99.75');
assert.equal(workbench.formatPercentageTotal(100.004), '100');
assert.equal(workbench.formatPercentageTotal(100.006), '100.01');
```

Repeat the meaningful cases with the French number locale so `99.75` renders as `99,75`.

- [ ] **Step 2: Extend weight-mode tests without changing its semantics**

In `RecipeWorkbenchMassInteractionTest.php`, cover both current paths:

```js
const cosmeticRows = [
    { id: 'water', percentage: 60 },
    { id: 'oil', percentage: 40 },
];
const cosmeticResult = updateFormulaPercentagesFromWeights(cosmeticRows, 100, 'water', 30);
assert.equal(cosmeticResult.totalWeight, 70);
assert.equal(cosmeticResult.percentagesByRowId.get('water'), 42.857);
assert.equal(cosmeticResult.percentagesByRowId.get('oil'), 57.143);

const soapOils = [
    { id: 'olive', percentage: 75 },
    { id: 'coconut', percentage: 25 },
];
const soapResult = updateOilPercentagesFromWeights(soapOils, 1000, 'olive', 500);
assert.equal(soapResult.oilWeight, 750);
assert.equal(soapResult.percentagesByRowId.get('olive'), 66.667);
assert.equal(soapResult.percentagesByRowId.get('coconut'), 33.333);
```

Also retain a soap-addition assertion showing that `updatePercentageFromWeight()` uses total oils as its basis and does not change total oils.

- [ ] **Step 3: Replace old precision and cosmetic-output markup assertions**

Update the relevant assertions in `CosmeticRecipeWorkbenchTest.php` and `RecipeWorkbenchDesignPolishTest.php` to require:

- `format(row.percentage, 2)` and `format(row.weight, 3)` in cosmetic output rows.
- `formatPercentageTotal(...)` in aggregate formula-total locations.
- no visible “Ingredient output” or “Formula output” copy in cosmetic output.
- formula quantity and the table weight total to read from the same `cosmeticOutputIngredientTotalWeight`/`cosmeticFormulaWeightTotal()` calculation path.
- row percentages and phase percentages to keep their existing precision.

- [ ] **Step 4: Run the focused tests and confirm they fail for the intended reasons**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

Expected: FAIL on the missing aggregate formatter, old cosmetic output precision/copy, and old total bindings.

- [ ] **Step 5: Commit the contract tests**

```bash
git add tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
git commit -m "test: define workbench formula editing contract"
```

### Task 2: Surface one entry-mode control beside the formula work

**Files:**
- Create: `resources/views/components/recipe-workbench/entry-mode-toggle.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php:29-40`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php:92-109,172-188`
- Modify: `resources/js/recipe-workbench/sections/formula-section.js:169-190`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Add failing placement and accessibility assertions**

Assert that `formula-tab.blade.php` renders exactly one shared entry-mode component in the main formula column before the cosmetic/soap branch, and that the rendered component contains:

```blade
role="radiogroup"
:aria-checked="editMode === 'percentage'"
:aria-checked="editMode === 'weight'"
```

Assert that `formula-settings.blade.php` no longer renders either old `setting-entry-mode` group. Keep `formulaSetupSummaryCards` showing the current mode while Settings is collapsed.

- [ ] **Step 2: Create the shared compact toolbar**

Build `entry-mode-toggle.blade.php` as one restrained horizontal toolbar, not another large settings card:

```blade
<section class="flex flex-col gap-2 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between" aria-labelledby="formula-entry-mode-heading">
    <div class="min-w-0">
        <p id="formula-entry-mode-heading" class="sk-eyebrow">{{ __('workbench.settings.entry_mode') }}</p>
        <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="entryModeHelperText"></p>
    </div>
    <div role="radiogroup" aria-labelledby="formula-entry-mode-heading" class="inline-flex shrink-0 rounded-lg bg-[var(--color-control)] p-1">
        <!-- two 44px-minimum percentage and weight radio buttons bound to editMode -->
    </div>
</section>
```

Use the same active/inactive token vocabulary as the current settings radio groups. Labels should be `% formula` / `Weight` for cosmetics and `% oils` / `Weight` for soap.

- [ ] **Step 3: Add mode-specific explanatory copy**

Add a getter in `formula-section.js`:

```js
get entryModeHelperText() {
    if (this.editMode === 'weight') {
        return this.isCosmeticFormula
            ? this.t('settings.cosmetic_weight_entry_help')
            : this.t('settings.soap_weight_entry_help');
    }

    return this.isCosmeticFormula
        ? this.t('settings.cosmetic_percentage_entry_help')
        : this.t('settings.soap_percentage_entry_help');
}
```

English intent:

- Cosmetic percentage: “Set formula shares; quantities follow the total batch.”
- Cosmetic weight: “Set ingredient quantities; total batch and percentages recalculate.”
- Soap percentage: “Set oil and addition shares; quantities follow total oils.”
- Soap weight: “Oil quantities recalculate total oils and % oils; additions remain based on total oils.”

- [ ] **Step 4: Include the toolbar and remove the hidden duplicates**

In the main formula column of `formula-tab.blade.php`, add the component immediately before the family-specific formula partials. Remove only the two entry-mode blocks from `formula-settings.blade.php`; keep total batch/total oil quantity and unit controls unchanged.

- [ ] **Step 5: Run focused tests**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the entry-mode interface**

```bash
git add resources/views/components/recipe-workbench/entry-mode-toggle.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php resources/js/recipe-workbench/sections/formula-section.js lang/en/workbench.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "feat: surface formula amount entry mode"
```

### Task 3: Give soap and cosmetic rows one responsive and accessible action model

**Files:**
- Create: `resources/views/components/recipe-workbench/formula-row-actions.blade.php`
- Create: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-confirmation-modal.blade.php`
- Modify: `resources/views/components/action-icon.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-bottom-action-bar.blade.php`
- Modify: `resources/js/recipe-workbench/component.js:196-255,786-992`
- Modify: `resources/js/recipe-workbench/sections/formula-section.js:660-718`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Add failing behavior tests for explicit row movement**

Add Node-backed tests around `createRecipeWorkbench()` that prove:

- move up/down changes order only inside the selected phase and safely no-ops at the first/last boundary;
- moving a cosmetic row to another phase preserves the row and inserts it deterministically;
- soap phase eligibility and duplicate-ingredient safeguards match the existing drag/drop path;
- ingredient removal immediately deletes only the requested row, stores its original phase and index, and Undo restores it exactly once;
- a cancelled phase removal leaves the phase and its rows untouched;
- a confirmed phase removal deletes exactly the named phase and, when present, its rows.

The reusable primitive should have this shape:

```js
moveFormulaRow(sourcePhaseKey, rowId, targetPhaseKey, targetIndex) { /* shared validation and immutable reassignment */ }
moveFormulaRowBy(sourcePhaseKey, rowId, direction) { /* delegates to moveFormulaRow */ }
moveFormulaRowToPhase(sourcePhaseKey, rowId, targetPhaseKey) { /* delegates to moveFormulaRow */ }
```

Refactor `dropDraggedRow()` to delegate its final mutation to the same primitive so pointer, keyboard, and touch movement cannot diverge.

- [ ] **Step 2: Add failing markup assertions for the shared row contract**

Update `RecipeWorkbenchDesignPolishTest.php` so cosmetic rows must match the soap mobile structure already present:

- two-column mobile card;
- top action row;
- full-width common-name + INCI identity row;
- side-by-side percentage and weight row;
- desktop five-column ledger retained.

Require a row-actions trigger with `aria-haspopup="menu"`, an accessible name containing the ingredient name, focus return on close, Move up/Move down entries, eligible phase destinations, and Remove. Require the same component in oils, additives, fragrance/aromatics, and cosmetics.

- [ ] **Step 3: Centralize row mutation, ingredient Undo, and phase-confirmation state**

Add one serializable ingredient-removal snapshot to `component.js`:

```js
removedFormulaRowUndo: null,
```

Store enough data to recover the exact placement, without callbacks:

```js
{
    phaseKey,
    row,
    index,
    message,
}
```

Add `removeFormulaRowWithUndo(phaseKey, rowId)` as the UI-facing wrapper around the existing removal behavior and `undoFormulaRowRemoval()` to restore the row at the captured index, subject to the existing phase-eligibility and duplicate safeguards. A later ingredient removal replaces the prior snapshot. Clear the snapshot after Undo, Save/reload, or a conflicting structural mutation. Do not change lower-level removal calls used for internal recalculation.

Keep phase confirmation separate and explicit:

```js
pendingCosmeticPhaseRemoval: null,
pendingCosmeticPhaseRemovalTrigger: null,
```

`requestCosmeticPhaseRemoval()`, `cancelCosmeticPhaseRemoval()`, and `confirmCosmeticPhaseRemoval()` handle only `{ phaseKey, phaseName, rowCount }`. Replace `window.confirm()` in the current phase-removal path and return focus after cancellation. Do not change the separate negative-superfat safety confirmation in this task.

- [ ] **Step 4: Build the accessible phase-confirmation dialog**

The modal partial must be rendered once from `formula-tab.blade.php` and include:

- `role="dialog"`, `aria-modal="true"`, labelled heading and description;
- `x-trap.inert.noscroll` while open;
- Escape/backdrop cancellation;
- Cancel as the initially focused safe action;
- a danger-styled “Remove phase” confirm action;
- copy stating the number of ingredients when a populated phase is removed.

Do not open this dialog for ingredient removal.

- [ ] **Step 5: Build the row-actions popover and apply it everywhere**

Keep the drag handle on desktop for fast pointer use. The shared action popover supplies the dependable keyboard/touch path. Its trigger replaces the direct ×/close button in the last column/top mobile action row; Remove moves inside the menu, removes the ingredient immediately, closes the menu, and announces the available Undo action.

Use the existing soap mobile grid as the baseline, then convert cosmetic rows from `grid-cols-1` to the same two-column/three-row pattern. Use `<x-action-icon name="drag" />`, `<x-action-icon name="info" />`, and the new more-actions icon consistently; give the cosmetic ownership dot the same `role="img"` and `aria-label` as the ingredient browser.

Render a compact `role="status"` strip in `formula-bottom-action-bar.blade.php` while `removedFormulaRowUndo` exists. It names the removed ingredient and includes a real Undo button. Keep the feedback until Undo, a later ingredient removal, Save/reload, or a conflicting structural mutation; do not auto-dismiss the only recovery path.

- [ ] **Step 6: Make the ingredient-browser phase chooser focus-safe**

In `ingredient-browser.blade.php`, retain the current plus-button flow but give the teleported chooser a complete popover contract: matching `aria-controls`, a labelled popup role, focus the first phase after opening, close on Escape/outside click, and return focus to the plus trigger. Here “browser result rows” means the ingredient search/selection list before addition; do not add persistent INCI text to those rows.

- [ ] **Step 7: Run focused row/action tests**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit the shared row interaction**

```bash
git add resources/views/components/recipe-workbench/formula-row-actions.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/formula-confirmation-modal.blade.php resources/views/components/action-icon.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/formula-bottom-action-bar.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php resources/js/recipe-workbench/component.js resources/js/recipe-workbench/sections/formula-section.js lang/en/workbench.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "feat: align formula row actions across benches"
```

### Task 4: Make cosmetic composition totals and wording truthful

**Files:**
- Modify: `resources/js/recipe-workbench/sections/formula-section.js:250-262,501-529`
- Modify: `resources/js/recipe-workbench/sections/presentation-section.js:294-317,387-434`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php:1-187`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php:7-87`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/RecipeWorkbenchNumericFormattingTest.php`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Implement the aggregate percentage formatter**

Add one formatter to `formula-section.js` based on the same two-decimal readout used by the balance/save decision:

```js
formatPercentageTotal(value) {
    const readout = this.number(this.format(value, 2));

    return this.format(readout, Number.isInteger(readout) ? 0 : 2);
}
```

Use it for aggregate formula-total displays in the soap and cosmetic formula headers, total rows, bottom diagnostic card, cosmetic output summary, and cosmetic output table total. Continue to use two decimals for ingredient and phase percentages. Do not alter `oilPercentageReadoutTotal` or `canSaveRecipe`.

- [ ] **Step 2: Correct cosmetic output precision**

In `output-tab.blade.php`, render cosmetic composition rows as:

```blade
x-text="`${format(row.percentage, 2)}%`"
x-text="format(row.weight, 3)"
```

Render the composition total percentage through `formatPercentageTotal()` and its weight at three decimals.

- [ ] **Step 3: Use one formula-quantity source**

Bind the cosmetic summary quantity to `cosmeticOutputIngredientTotalWeight` (which is derived from the same `rowWeight()` values as `cosmeticFormulaWeightTotal()`) instead of displaying `oilWeight` independently. Keep the Settings quantity as the scaling input in percentage mode and as the recalculated total in weight mode.

Add a test invariant for a balanced formula:

```js
assert.equal(workbench.cosmeticFormulaWeightTotal(), workbench.number(workbench.oilWeight));
assert.equal(workbench.cosmeticOutputIngredientTotalWeight, workbench.cosmeticFormulaWeightTotal());
```

- [ ] **Step 4: Replace transformation-oriented cosmetic copy**

Use these English meanings in `lang/en/workbench.php`:

```php
'output' => [
    'cosmetic' => [
        'title' => 'Cosmetic formula',
        'help' => 'Review the formula quantity, composition and label information on the full formula basis.',
        'basis' => 'Full formula basis',
        'batch_quantity' => 'Formula quantity',
        'formula_total' => 'Formula total',
        'ingredient_rows' => 'Ingredients',
        'ingredients_title' => 'Formula composition',
        'ingredients_help' => 'Ingredients are ordered from highest to lowest formula share. The common name appears below the INCI labeling name.',
        'full_formula' => 'Formula total',
        'empty' => 'Add ingredients to build the formula composition.',
    ],
],
```

Remove the redundant “Descending” badge once the help text states the ordering. In `cosmeticOutputIngredientRows`, expose the row identity explicitly as `label_name: row.inci_name || row.name` and `common_name: row.name`. In the composition cell, render `label_name` as the primary text and render `common_name` beneath it only when it is present and differs from `label_name`; do not introduce another column. This is the Formula composition table, not the left ingredient search/selection list.

- [ ] **Step 5: Give cosmetics their own generated-list helper**

Change `ingredientListVariantHelperText` so cosmetics never receive the soap-specific “before saponification” sentence:

```js
if (this.isCosmeticFormula) {
    return this.t('output.lists.cosmetic_generated_help');
}

return this.activeIngredientListVariantKey === 'incorporated_ingredients'
    ? this.t('output.lists.soap_as_added_help')
    : this.t('output.lists.soap_saponified_help');
```

Cosmetic English intent: “Ingredients as added, ordered for the selected label market, with required declarations.” Localize all three helper strings instead of retaining hard-coded English.

- [ ] **Step 6: Run focused output tests**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the output correction**

```bash
git add resources/js/recipe-workbench/sections/formula-section.js resources/js/recipe-workbench/sections/presentation-section.js resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php lang/en/workbench.php tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "fix: describe cosmetic formula composition accurately"
```

### Task 5: Add one-level Undo for final label-list replacement

**Files:**
- Modify: `resources/js/recipe-workbench/component.js:220-244`
- Modify: `resources/js/recipe-workbench/sections/presentation-section.js:719-745`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php:27-164`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`

- [ ] **Step 1: Add failing state-transition tests**

Cover all four actions (`useGeneratedIngredientListAsFinal`, `useGeneratedPlainIngredientListAsFinal`, `clearFinalIngredientList`, `clearFinalPlainIngredientList`) and assert that each stores the previous text and basis hash before changing them. Assert that Undo restores both values, and that a subsequent destructive list action replaces the prior undo snapshot.

- [ ] **Step 2: Add a single typed undo snapshot**

Add component state:

```js
ingredientListUndo: null,
```

Store plain serializable data only:

```js
{
    target: 'inci' | 'plain',
    value: previousValue,
    basisHash: previousBasisHash,
    message: translatedMessage,
}
```

`undoIngredientListChange()` restores the correct field/hash pair and clears the snapshot. Clear a stale undo snapshot when the user manually edits the same final textarea so Undo never overwrites newer typing.

- [ ] **Step 3: Render local feedback near the final editors**

Add one compact `role="status"` strip above the two-column generated/final list grid. It shows the change message and a real `Undo` button while `ingredientListUndo` exists. Keep it visible until the next manual edit, another destructive list action, Save/reload, or Undo; do not auto-dismiss it while it is the only recovery path.

- [ ] **Step 4: Run the list tests**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit Undo**

```bash
git add resources/js/recipe-workbench/component.js resources/js/recipe-workbench/sections/presentation-section.js resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php lang/en/workbench.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
git commit -m "feat: add undo for final label list changes"
```

### Task 6: Complete localization and record the durable formatting decision

**Files:**
- Modify: `lang/en/workbench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/SoapWorkbenchLocalizationTest.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php` only if its focused reviewed-key list must expand

- [ ] **Step 1: Add/update every owned Workbench key in the deterministic catalogue**

Mirror all new and revised `workbench.*` keys in German, Spanish, French, Italian, Dutch, and Brazilian Portuguese. Preserve placeholders such as `:ingredient`, `:phase`, and `:count`; keep rows strictly sorted by `group.key`; do not leave copied English values as placeholders for review.

- [ ] **Step 2: Add localization assertions**

Extend `SoapWorkbenchLocalizationTest.php` to assert:

- the revised French cosmetic headings (“Formule cosmétique”, “Composition de la formule”, and “Quantité de la formule”);
- all entry-mode, row-action, phase-confirmation, generated-list-helper, ingredient-removal Undo, and label-list Undo keys exist for all six catalogue locales;
- placeholder sets match the English source.

- [ ] **Step 3: Validate the catalogue and run localization tests**

```bash
php -r 'json_decode(file_get_contents("database/seeders/data/interface-translations.json"), true, 512, JSON_THROW_ON_ERROR);'
php artisan test --compact tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

- [ ] **Step 4: Record the aggregate-total exception through Laravel Boost**

Use `record-rule` with:

```text
glob: resources/**/recipe-workbench/**
title: Format aggregate percentage totals without redundant zeroes
note: Ingredient and phase percentages keep their established precision. Aggregate percentage totals render without decimals when the two-decimal readout is an integer (for example 100%), but retain two decimals when needed to expose an imbalance. Calculations and the save decision keep unrounded values and use the existing two-decimal readout contract.
```

Do not edit `.ai/rules` directly.

- [ ] **Step 5: Commit localization and the durable rule**

```bash
git add lang/en/workbench.php database/seeders/data/interface-translations.json .ai/rules/recipe-workbench.md tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git commit -m "chore: localize recipe workbench interface updates"
```

### Task 7: Verify the integrated workbench

**Files:**
- Verify all files changed in Tasks 1-6

- [ ] **Step 1: Run the complete focused regression set**

```bash
php artisan test --compact tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

- [ ] **Step 2: Format changed PHP files**

```bash
vendor/bin/pint --dirty --format agent
```

Rerun any focused test whose PHP file Pint changes.

- [ ] **Step 3: Build frontend assets**

```bash
npm run build
```

Expected: successful Vite production build with no missing Tailwind classes or JavaScript syntax errors.

- [ ] **Step 4: Perform authenticated visual/interaction QA**

Check soap and cosmetic workbenches at phone, tablet, and desktop widths:

- percentage mode is the default and the toolbar is next to the ledger;
- cosmetic and soap rows use the same mobile hierarchy;
- percentage and weight controls remain side by side without overflow;
- action menus open by keyboard and touch, arrow movement announces/reflects the new order, and focus returns to the trigger;
- ingredient removal is immediate, removes only the named row, and Undo restores its phase and order;
- phase cancellation is non-destructive and confirmation removes only the named phase and its contained rows;
- cosmetic weight entry updates the total batch and derived percentages;
- soap oil weight entry updates total oils and derived `% oils`, while addition weights remain based on total oils;
- balanced totals read `100%`; a fractional imbalance remains visible with two decimals;
- cosmetic output says “Cosmetic formula” and “Formula composition”, shows the INCI/labeling name first with the common name beneath it, and shows three-decimal weights;
- generated-list replacement and Clear can restore the previous value and basis hash through Undo;
- Save remains disabled while the formula is not balanced.

- [ ] **Step 5: Refresh the knowledge graph after code changes**

```bash
graphify update .
```

- [ ] **Step 6: Review the final diff and commit any verification-only adjustments**

```bash
git status --short
git diff --check
git diff --stat
```

If verification required fixes, commit them separately:

```bash
git add <only-the-adjusted-files>
git commit -m "fix: finish recipe workbench interaction polish"
```

- [ ] **Step 7: Ask the project owner to run the complete suite**

```bash
php artisan test --compact
```

The focused suite and asset build must already be green before requesting this final full-suite run.
