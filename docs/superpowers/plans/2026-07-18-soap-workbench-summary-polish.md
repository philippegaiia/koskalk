# Soap Workbench Summary Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refine the soap bench summaries with compact totals, meaningful quality groups, a remembered disclosure, clearer fatty-acid colors, and 36 px ingredient information controls.

**Architecture:** Keep calculations and Livewire state unchanged. Regroup existing quality metrics in the presentation JavaScript, render the approved UI through the existing Blade partials, and store only the disclosure preference in user-scoped browser storage. Extend the existing recipe-workbench presentation contract tests rather than introducing browser-only business logic.

**Tech Stack:** Laravel 13, Blade, Livewire 4 bundled Alpine.js, Tailwind CSS 4, JavaScript ES modules, Pest 4

---

## File Map

- Modify `resources/js/recipe-workbench/sections/presentation-section.js`: define the two approved quality groups and the differentiated fatty-acid color tokens.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/formula-analysis.blade.php`: add remembered disclosure behavior, rename the tabs, and apply the four-column, 11 px eyebrow, and 20 px value contracts.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php`: remove the oversized total-card height and reserve a compact two-line eyebrow area.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php`: render tinted group badges and native full-name titles.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php`: resize the soap formula ingredient information control to 36 px.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php`: apply the same 36 px size to the identical cosmetic formula information control for cross-bench consistency.
- Modify `tests/Feature/RecipeWorkbenchPersistenceTest.php`: update the quality-panel structure and grouping contract.
- Modify `tests/Feature/RecipeWorkbenchDesignPolishTest.php`: cover the final visual, responsive, disclosure, fatty-acid, and information-control contracts.

### Task 1: Lock the approved presentation contracts with failing tests

**Files:**
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Modify: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`

- [ ] **Step 1: Replace the old quality-tab expectations**

Update the quality presentation test to require the new state names and grouping methods:

```php
expect($formulaAnalysis)
    ->toContain("soapQualityPanel: 'bar_cure'")
    ->toContain("soapQualityPanel = 'bar_cure'")
    ->toContain("soapQualityPanel = 'lather_feel'")
    ->toContain('barAndCureQualityRows()')
    ->toContain('latherAndFeelQualityRows()')
    ->not->toContain('defaultQualityRows()')
    ->not->toContain('advancedQualityRows()');
```

- [ ] **Step 2: Add the disclosure and visual contract expectations**

Require the authenticated-user storage key, open default, accessible disclosure, 11 px eyebrows, 20 px values, four wide columns, compact total labels, native fatty-acid titles, tinted badge colors, and `size-9` information buttons:

```php
expect($formulaAnalysis)
    ->toContain('soapQualitiesExpanded: true')
    ->toContain('localStorage.getItem(this.soapQualitiesStorageKey)')
    ->toContain('localStorage.setItem(this.soapQualitiesStorageKey')
    ->toContain(':aria-expanded="soapQualitiesExpanded.toString()"', false)
    ->toContain('Bar &amp; cure', false)
    ->toContain('Lather &amp; feel', false)
    ->toContain('sk-eyebrow block min-h-8')
    ->toContain('numeric mt-1.5 text-xl')
    ->toContain('sm:grid-cols-2 xl:grid-cols-4');

expect($postReaction)
    ->toContain('sk-eyebrow min-h-8')
    ->toContain('numeric mt-1.5 text-xl')
    ->not->toContain('min-h-24');

expect($fattyAcidProfile)
    ->toContain(':title="segment.label"', false)
    ->toContain('backgroundColor: segment.softColor')
    ->toContain('color: segment.textColor');

expect($reactionCore)->toContain('class="grid size-9 place-items-center', false);
expect($cosmeticFormula)->toContain('class="grid size-9 place-items-center', false);
```

- [ ] **Step 3: Run the focused tests and verify they fail**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

Expected: FAIL because the views still expose the old tab state, 24 px values, dark badges, tall total cards, and 40 px information controls.

- [ ] **Step 4: Commit the failing contract tests**

```bash
git add tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
git commit -m "test: define soap workbench summary polish"
```

### Task 2: Regroup quality metrics and define differentiated fatty-acid colors

**Files:**
- Modify: `resources/js/recipe-workbench/sections/presentation-section.js`

- [ ] **Step 1: Extend each fatty-acid group definition**

Replace each dark-only definition with a strong marker color, pale badge color, and dark badge text color:

```js
fattyAcidGroupDefinitions() {
    return [
        { key: 'vs', shortLabel: 'VS', label: 'Quick-cleansing saturated fats', color: 'oklch(0.62 0.13 72)', softColor: 'oklch(0.93 0.04 72)', textColor: 'oklch(0.42 0.09 72)' },
        { key: 'hs', shortLabel: 'HS', label: 'Hard saturated fats', color: 'oklch(0.60 0.10 42)', softColor: 'oklch(0.93 0.035 42)', textColor: 'oklch(0.43 0.08 42)' },
        { key: 'mu', shortLabel: 'MU', label: 'Monounsaturated fats', color: 'oklch(0.58 0.09 145)', softColor: 'oklch(0.93 0.03 145)', textColor: 'oklch(0.39 0.07 145)' },
        { key: 'pu', shortLabel: 'PU', label: 'Polyunsaturated fats', color: 'oklch(0.59 0.09 240)', softColor: 'oklch(0.93 0.03 240)', textColor: 'oklch(0.40 0.07 240)' },
        { key: 'sp', shortLabel: 'SP', label: 'Special lather fats', color: 'oklch(0.60 0.08 315)', softColor: 'oklch(0.93 0.03 315)', textColor: 'oklch(0.42 0.06 315)' },
    ];
},
```

- [ ] **Step 2: Replace the Basic and Advanced row methods**

Define the two approved groups while retaining the existing metric keys and explanation mapping:

```js
barAndCureQualityRows() {
    const quality = this.qualityMetrics();

    return [
        ['Unmolding firmness', 'unmolding_firmness'],
        ['Cured hardness', 'cured_hardness'],
        ['Longevity', 'longevity'],
        ['Cure speed', 'cure_speed'],
        ['DOS risk', 'dos_risk'],
    ].map(([label, key]) => ({
        label,
        key,
        value: quality[key],
        level: this.qualityLabel(quality[key]),
        explanation: this.qualityExplanation(key, quality[key]),
    }));
},

latherAndFeelQualityRows() {
    const quality = this.qualityMetrics();

    return [
        ['Cleansing strength', 'cleansing_strength'],
        ['Mildness', 'mildness'],
        ['Bubble volume', 'bubble_volume'],
        ['Creamy lather', 'creamy_lather'],
        ['Lather stability', 'lather_stability'],
        ['Conditioning feel', 'conditioning_feel'],
        ['Slime tendency', 'slime_risk'],
    ].map(([label, key]) => ({
        label,
        key,
        value: quality[key],
        level: this.qualityLabel(quality[key]),
        explanation: this.qualityExplanation(key, quality[key]),
    }));
},
```

- [ ] **Step 3: Run the focused tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

Expected: the JavaScript grouping and palette expectations pass, while Blade presentation expectations still fail.

- [ ] **Step 4: Commit the presentation data change**

```bash
git add resources/js/recipe-workbench/sections/presentation-section.js
git commit -m "refactor: organize soap quality presentation"
```

### Task 3: Implement the compact and remembered Blade presentation

**Files:**
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-analysis.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php`

- [ ] **Step 1: Add user-scoped disclosure state**

Initialize the qualities component with a user-scoped storage key and safe storage access:

```blade
x-data="{
    soapQualityPanel: 'bar_cure',
    soapQualitiesExpanded: true,
    soapQualitiesStorageKey: @js('soapkraft:soap-qualities-expanded:' . auth()->id()),
    init() {
        try {
            const storedValue = localStorage.getItem(this.soapQualitiesStorageKey);
            this.soapQualitiesExpanded = storedValue === null ? true : storedValue === 'true';
        } catch {
            this.soapQualitiesExpanded = true;
        }
    },
    toggleSoapQualities() {
        this.soapQualitiesExpanded = ! this.soapQualitiesExpanded;

        try {
            localStorage.setItem(this.soapQualitiesStorageKey, this.soapQualitiesExpanded.toString());
        } catch {}
    },
}"
```

Add a button whose `aria-expanded`, accessible label, visible Show/Hide text, and chevron state all derive from `soapQualitiesExpanded`. Hide the tab list and panel body with `x-show` when collapsed; leave the section title visible.

- [ ] **Step 2: Render the approved tabs and quality cards**

Use `bar_cure` and `lather_feel` tab values, call the matching row methods, and retain `grid gap-3 sm:grid-cols-2 xl:grid-cols-4`. Change each card label to `sk-eyebrow block min-h-8` and each number to `numeric mt-1.5 text-xl`.

- [ ] **Step 3: Compact Batch totals safely**

Replace the total card classes with:

```blade
<div class="flex flex-col bg-[var(--color-panel)] px-4 py-3">
    <p class="sk-eyebrow min-h-8" x-text="card.label"></p>
    <p class="numeric mt-1.5 text-xl font-semibold text-[var(--color-ink-strong)]" x-text="card.value"></p>
</div>
```

The reserved 2rem label area accommodates two eyebrow lines without restoring the previous excessive card height.

- [ ] **Step 4: Render the fatty-acid badge and native title**

Keep the strong marker and bind the badge to pale and dark tokens:

```blade
<span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: segment.color }"></span>
<span class="shrink-0 rounded-full px-2 py-0.5 font-medium" :style="{ backgroundColor: segment.softColor, color: segment.textColor }" x-text="segment.shortLabel"></span>
<span class="min-w-0 flex-1 truncate text-[var(--color-ink-strong)]" :title="segment.label" x-text="segment.label"></span>
```

- [ ] **Step 5: Resize both formula information controls**

Change only the ingredient inspector buttons in `reaction-core.blade.php` and `cosmetic-formula.blade.php` from `size-10` to `size-9`. Preserve their focus, pointer, popover, and accessibility behavior.

- [ ] **Step 6: Run focused tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the Blade presentation**

```bash
git add resources/views/livewire/dashboard/partials/recipe-workbench/formula-analysis.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php
git commit -m "feat: polish soap workbench summaries"
```

### Task 4: Verify the completed bench

**Files:**
- Verify only

- [ ] **Step 1: Run the focused Pest suite**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

Expected: PASS with no failures.

- [ ] **Step 2: Build production frontend assets**

```bash
npm run build
```

Expected: Vite completes successfully with no build errors.

- [ ] **Step 3: Refresh the knowledge graph**

```bash
graphify update .
```

Expected: the graph refresh completes successfully.

- [ ] **Step 4: Inspect the final diff and repository state**

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; only the user's pre-existing unrelated documentation and critique files remain outside the task commits.

- [ ] **Step 5: Manually verify responsive behavior**

Open the existing Herd workbench and verify wide desktop, the two-column total breakpoint, and mobile. Confirm four quality cards on wide desktop, aligned two-line totals, keyboard-operable tabs and disclosure, remembered browser state, full native fatty-acid titles, and 36 px information controls.
