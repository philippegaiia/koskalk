# Soap Formula Settings Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement the tasks sequentially. If delegating under the user's established preference, use Sol high for orchestration and Luna max for implementation. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the expanded soap settings easier to scan and shorter on desktop, with visibly selectable inactive options and direct access to everyday settings.

**Architecture:** Retain the outer settings panel and existing Alpine state/actions. Flatten the soap-only layout into three formulation columns, a compact output/use row, and a dilution-liquid section. Shared output partials receive an explicit opt-in compact layout; cosmetic rendering keeps its existing defaults.

**Tech Stack:** Laravel Blade, Alpine bindings already supplied by the workbench, Tailwind CSS 4, existing Soapkraft tokens, Pest and Vite.

---

## Design decision and user flow

The person using this bench wants to set oil weight, check superfat and lye/water settings, then enter ingredients. The formula should appear sooner without requiring another disclosure to reach those settings.

Desktop arrangement, within the current outer settings panel:

```text
Formula settings                                             Hide

Total oil weight             Lye type              Water calculation
[1000] [g kg oz lb]           [NaOH KOH Dual]        ( ) Water as % of oils
                                                   ( ) Water : lye ratio
Superfat          [5 %]       Conditional purity    (*) Lye concentration
[slider--------------]       and ratio here        [30] %

------------------------------------------------------------------
This formula produces                      Product use
[Finished product] [Manufactured ingredient] [Rinse-off] [Leave-on]
                                           Label & compliance …

Conditional manufactured-ingredient fields, full width when needed
Conditional compliance editor, full width when opened
------------------------------------------------------------------
Alkali solution · Dilution liquid: Water only    [Alternative switch]
Existing short explanatory text
Conditional dilution-liquid editor
```

The illustration shows example values, not new defaults. Keep actual initial and saved values from the existing state.

### Explicit constraints

- Everyday settings stay visible whenever Formula settings is expanded. Do not add a general “Advanced” disclosure.
- Preserve selected green styling and keyboard focus; inactive choices become transparent with a neutral border. Use the existing 0.5rem control radius.
- Preserve the outer collapse behaviour and its summary, the default 30% concentration for new formulas, numeric parsing, calculations and persistence.
- Keep all three water methods visible. Use compact labelled radio rows rather than a dropdown.
- Keep superfat's numeric input and slider, remove only its duplicate standalone readout.
- Keep conditional KOH purity and dual ratio beside the lye choice. Do not hide them behind another action.
- Keep dilution-liquid enable/disable semantics. `toggleLyeLiquidComposition()` can clear rows after confirmation; it must not be presented as a harmless show/hide action.
- Keep the ingredient selector, row actions, insertion focus, highlighting, and calculated Lye & water summary outside this change.
- No shared palette changes, calculation refactor, new dependency, data migration, or automatic merge.

## Task 1: Establish the baseline and isolate scope

**Inspect:** `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`, `formula-output-type.blade.php`, `formula-output-ingredient-fields.blade.php`, `resources/css/app.css`, `tests/Feature/RecipeWorkbenchDesignPolishTest.php`.

- [ ] Recheck git status and the current branch. The planning baseline is `codex/recipe-workbench-ux` with unrelated soap-quality changes, including modified translations and tests. Preserve those changes; stage only this task's hunks if committing later.
- [ ] Read applicable `.ai/rules` and the frontend/testing skills before editing. Confirm installed package versions before introducing any package-dependent syntax and use Boost documentation for new API usage.
- [ ] Capture the current expanded settings at 1440×900 and 1280×800 if browser access is available. Record actual workbench width and panel height; also capture the default cosmetic settings for comparison.
- [ ] Verify the breakpoint defect: the existing five-column query requires 80rem while the workbench is capped at 74rem. Replace the layout instead of trying to force all five old cards into one narrow row.

## Task 2: Flatten and align the soap formulation controls

**Modify:** `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php` (soap branch only).

- [ ] Wrap the expanded soap content in `data-soap-formula-settings`; retain the shared outer panel and collapse markup.
- [ ] Replace the five inset cards with three top-aligned plain groups. Group oil weight and superfat in the first column, lye type and its conditional controls in the second, water method and value in the third.
- [ ] Use actual workbench-container breakpoints: one column below 48rem, two columns from 48rem with the water group spanning both, three columns from 56rem with water returning to one column. This keeps source/tab order coherent and fits below the 74rem cap.

```html
<div class="grid min-w-0 items-start gap-x-6 gap-y-5 @3xl/workbench:grid-cols-2 @4xl/workbench:grid-cols-3">
    <!-- Existing oil-weight controls followed by existing superfat controls -->
    <!-- Existing lye controls and their conditional children -->
    <div class="min-w-0 @3xl/workbench:col-span-2 @4xl/workbench:col-span-1">
        <!-- Water methods and existing waterValue input -->
    </div>
</div>
```

- [ ] Align oil weight input with its unit choices on desktop, allowing units to wrap underneath on narrow widths. Keep `changeOilUnit()` calls and `normalizeDecimalBlur()` unchanged.
- [ ] Move the superfat numeric input beside its label; leave the slider underneath. Preserve both negative-superfat confirmation handlers, min/max/step, and danger styling. Remove the duplicate readout span and empty spacer.
- [ ] Remove the nested dual-lye inset wrapper; preserve both ratio readouts, the slider and purity choices.
- [ ] Present water options as native radio inputs with clickable translated labels, preserving `waterMode` string values and the current option order. Keep radio groups keyboard-operable. Keep `waterValue` below, paired with a visible `%` suffix for percentage methods or `: 1` for the ratio. Associate its accessible label with the selected method's existing translated name.
- [ ] Retain numeric field backgrounds, adequate input widths, and comfortable touch targets. Compactness comes primarily from removed wrappers and redundant lines; do not reduce text below the current size.

## Task 3: Apply the outlined inactive choice treatment

**Modify:** the soap choices in `formula-settings.blade.php`, the compact output variant in `formula-output-type.blade.php`, and locally scoped styles in `resources/css/app.css` only if needed.

- [ ] Give soap option buttons a constant 1px border in every state to prevent size changes on selection. Reuse existing tokens and transition conventions. The state classes are:

```text
Base:     border rounded-lg text-xs font-medium transition-colors
Inactive: border-[var(--color-field-outline)] bg-transparent text-[var(--color-ink-strong)]
Hover:    hover:border-[var(--color-line-strong)] hover:bg-[var(--color-field-muted)]
Selected: border-[var(--color-active)] bg-[var(--color-active)] text-[var(--color-on-active)]
```

- [ ] Apply to units, lye type, KOH purity, production output and product use. Water methods use the native radio treatment from Task 2. Preserve selected state and existing event handlers for the buttons.
- [ ] Keep the existing global keyboard-focus rule. Do not introduce `outline: none`, global token changes, or CSS that restyles ingredient-browser controls, cosmetic options or the entry-mode toggle.
- [ ] Verify that radio semantics expose the selected state and that every choice remains keyboard reachable. Native water radios support arrow-key selection; do not regress keyboard access on retained option buttons.

## Task 4: Compact production output and product use

**Modify:** `formula-settings.blade.php`, `formula-output-type.blade.php`.
**Reuse:** `formula-output-ingredient-fields.blade.php`, `ifra-category-modal.blade.php`.

- [ ] Move soap production output below the formulation controls beside Product use in a two-column row, with slightly more space for its longer labels. Use a simple top separator. Stack the groups on narrow widths; allow translated option labels to wrap.
- [ ] Add `compactSoapOutputType`, defaulting to false, to the shared output partial. With true: render its label and choices without `sk-inset`, tone background or bottom margin; apply the outlined choice treatment; defer its ingredient fields to the parent. Keep both existing default and cosmetic `inlineFormulaOutputType` branches intact.
- [ ] In the soap parent, call the partial with `compactSoapOutputType => true`, retaining `@unless ($isPublicCalculator)`. Render the existing output-ingredient fields once beneath the whole output/use row under the same public-calculator guard. Preserve `productionOutputType`, `outputIngredientId`, creation handlers and current conditional visibility.
- [ ] Keep the compact Label & compliance trigger and current coverage summary beneath Product use. Render the expanded compliance editor below the row rather than stretching its adjacent group. Retain the existing `isComplianceSettingsOpen` binding, IDs and IFRA modal behaviour.
- [ ] On the public calculator, avoid an empty production-output column or manufactured-ingredient fields. Product use and compliance remain accessible.

## Task 5: Flatten the dilution-liquid section

**Modify:** `formula-settings.blade.php` (soap branch only).

- [ ] Replace its tinted outer inset card with a plain separated section. Keep the heading, current selection summary, enable switch and explanatory text, with compact spacing.
- [ ] Place heading/summary and switch on the same row where space permits. Keep the help text below. Preserve full text and let the layout wrap on mobile.
- [ ] Keep the existing conditional editor and table, limits, validation, summary and ingredient search intact. Do not change `toggleLyeLiquidComposition()` or introduce a second collapse mechanism in this task.

## Task 6: Verify the layout and interactions

**Existing test files:** `tests/Feature/RecipeWorkbenchDesignPolishTest.php`, `tests/Feature/RecipeWorkbenchContractTest.php`, `tests/Feature/RecipeWorkbenchNumericFormattingTest.php`, `tests/Feature/RecipeWorkbenchMassInteractionTest.php`, `tests/Feature/SoapWorkbenchLocalizationTest.php`, `tests/Feature/CosmeticRecipeWorkbenchTest.php`.

- [ ] Run the current settings-related tests before changing their expectations. Update existing presentation assertions that intentionally encode the old markup; preserve checks covering field availability, summary/collapse, public visibility and cosmetic behaviour. Avoid adding tests that simply repeat utility-class strings.
- [ ] Run the affected existing test files after implementation:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/RecipeWorkbenchNumericFormattingTest.php tests/Feature/RecipeWorkbenchMassInteractionTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
npm run build
```

- [ ] Investigate failures against the recorded baseline before attributing them to this change. Do not modify unrelated soap-quality work to make this task's checks pass.
- [ ] Inspect the rendered default NaOH/30% state, KOH, dual lye, each water method, and a reopened saved formula. Switch units; edit superfat with keyboard and slider; confirm negative-superfat handling still works.
- [ ] Select Manufactured ingredient; confirm its full-width fields appear once, focus order remains logical, and switching back performs only the existing state changes.
- [ ] Open compliance; confirm its controls remain operable and it does not create a tall empty adjacent column. Enable alternative dilution liquids; verify cancelling the removal confirmation retains rows and confirming retains the existing reset behaviour.
- [ ] Visually compare before/after at 1440×900 and 1280×800. Also check 1024×768, 390×844, French/German labels and 200% zoom. Use screenshots and measured panel heights; Blade rendering alone cannot prove height, contrast or overflow.
- [ ] Confirm inactive boundaries are visible, selected/focused states distinguishable, labels untruncated, and there is no horizontal overflow or clipped conditional content. Do not set a fixed panel height or internal vertical scrollbar.
- [ ] Verify public calculator and cosmetic settings. Shared partial defaults must retain their former layout. Check that ingredient selector stickiness and desktop insertion focus still behave as before.
- [ ] If browser access remains unavailable, report visual validation as pending instead of claiming the UI is visually verified.
- [ ] Run `vendor/bin/pint --dirty --format agent` if PHP files were modified, inspect formatter changes for unrelated edits, and run `graphify update .` after code changes per project guidance. Review `git diff --check` and the scoped diff.

## Completion and handoff

- [ ] Report the changed controls and measured default-panel height reduction, tests/build outcome, and any remaining visual-verification limitation.
- [ ] Keep implementation on the working branch for user review; do not merge automatically. The complete suite command for a later broad check is `php artisan test --compact`.

## Self-review

The plan covers outlined inactive controls, the unreachable wide breakpoint, tall/stretching cards, oversized production output, everyday access, conditional fields, public/cosmetic isolation and the dilution-switch semantics. It introduces no general advanced menu and makes no unmeasured claim that every expanded state fits within one viewport. The default case should become shorter; additional user-requested editors must remain free to grow naturally.
