# Ingredient List Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganize the recipe workbench ingredient-list area into consistent generated and editable list workflows with clear copy actions.

**Architecture:** Keep the existing backend labeling, variant selection, final-list persistence, and basis-hash behavior. Refine the Blade layout into two responsive output lanes and centralize clipboard success/failure behavior in a small JavaScript utility used by generated and final list actions.

**Tech Stack:** Laravel Blade, Livewire/Alpine state, vanilla ES modules, Tailwind CSS v4, Pest, Node subprocess tests, Vite.

---

## File Map

- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php`: replace the mixed header/actions layout with the two generated-to-final lanes and consistent action groups.
- Modify `resources/js/recipe-workbench/component.js`: expose clipboard state and the shared `copyIngredientList` action.
- Create `resources/js/recipe-workbench/clipboard.js`: provide a small boolean-returning clipboard helper that handles unavailable and failing clipboard APIs.
- Modify `resources/js/recipe-workbench/sections/presentation-section.js`: provide concise variant helper text and clear copy feedback when a variant changes.
- Modify `resources/js/recipe-workbench/bridge.js`: clear copy feedback when a fresh preview replaces labeling data.
- Modify `resources/js/recipe-workbench/snapshot.js`: reset copy feedback when a saved snapshot is hydrated.
- Modify `tests/Feature/RecipeWorkbenchDesignPolishTest.php`: add Blade hierarchy and action-vocabulary regression coverage.
- Modify `tests/Feature/RecipeWorkbenchPersistenceTest.php`: add Node coverage for the clipboard helper.

## Task 1: Add failing regression coverage

**Files:**

- Modify: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [x] **Step 1: Add the Blade contract test.** Add a test after the existing cured-soap basis test that reads `ingredient-list-preview.blade.php` and asserts:

```php
it('organizes ingredient lists around generated and editable final outputs', function () {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));

    expect($source)
        ->toContain('Ingredient lists')
        ->toContain('Choose a list, then copy it or edit a final version.')
        ->toContain('INCI ingredient list')
        ->toContain('Plain-language ingredient list')
        ->toContain('Final INCI list')
        ->toContain('Final plain-language list')
        ->toContain('Ingredients as added')
        ->toContain('Use generated')
        ->not->toContain('Use as final')
        ->not->toContain('Generated from the selected ingredient-list variant')
        ->not->toContain('Cured soap basis')
        ->and(substr_count($source, 'Copy list'))
        ->toBe(4);
});
```

- [x] **Step 2: Add the clipboard helper test.** Add a Node-backed Pest test that imports `resources/js/recipe-workbench/clipboard.js`, installs a fake `navigator.clipboard.writeText`, and verifies both success and failure:

```php
it('reports clipboard success and failure for ingredient list copies', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { copyText } from './resources/js/recipe-workbench/clipboard.js';

let copiedText = null;
Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { clipboard: { writeText: async (value) => { copiedText = value; } } },
});

assert.equal(await copyText('INCI list'), true);
assert.equal(copiedText, 'INCI list');

Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: { clipboard: { writeText: async () => { throw new Error('blocked'); } } },
});

assert.equal(await copyText('plain list'), false);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
```

- [x] **Step 3: Run the two focused tests and confirm they fail.**

Run:

```bash
php artisan test --compact --filter='organizes ingredient lists|reports clipboard success' tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: the Blade test fails because the current header still contains the long description and badges, and the clipboard test fails because `resources/js/recipe-workbench/clipboard.js` does not exist.

## Task 2: Add shared clipboard behavior

**Files:**

- Create: `resources/js/recipe-workbench/clipboard.js`
- Modify: `resources/js/recipe-workbench/component.js:1-30,209-225,859-873`
- Modify: `resources/js/recipe-workbench/bridge.js:17`
- Modify: `resources/js/recipe-workbench/snapshot.js:90`

- [x] **Step 1: Create the clipboard helper.** Add exactly this module:

```js
export async function copyText(text) {
    if (!text || typeof navigator === 'undefined' || !navigator.clipboard?.writeText) {
        return false;
    }

    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch (error) {
        void error;

        return false;
    }
}
```

- [x] **Step 2: Replace the single INCI copy state with target-aware state.** Import `copyText` from `./clipboard`, replace `inciCopyMessage` with:

```js
ingredientListCopyMessage: '',
ingredientListCopyTarget: '',
ingredientListCopyTimer: null,
```

- [x] **Step 3: Replace `copyGeneratedIngredientList()` with the shared action.** Add this method to the recipe workbench object:

```js
async copyIngredientList(text, target) {
    this.ingredientListCopyTarget = target;
    this.ingredientListCopyMessage = await copyText(text) ? 'Copied' : 'Copy failed';

    clearTimeout(this.ingredientListCopyTimer);
    this.ingredientListCopyTimer = setTimeout(() => {
        this.ingredientListCopyMessage = '';
        this.ingredientListCopyTarget = '';
        this.ingredientListCopyTimer = null;
    }, 1600);
},
```

- [x] **Step 4: Reset copy feedback in preview and snapshot hydration.** Replace the existing `workbench.inciCopyMessage = '';` and `inciCopyMessage: '',` assignments with:

```js
workbench.ingredientListCopyMessage = '';
workbench.ingredientListCopyTarget = '';
```

and:

```js
ingredientListCopyMessage: '',
ingredientListCopyTarget: '',
ingredientListCopyTimer: null,
```

- [x] **Step 5: Run the clipboard test.**

Run:

```bash
php artisan test --compact --filter='reports clipboard success' tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: PASS.

## Task 3: Rebuild the ingredient-list layout and action vocabulary

**Files:**

- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php`
- Modify: `resources/js/recipe-workbench/sections/presentation-section.js:348-380,668-671`

- [x] **Step 1: Replace the preview header.** Use `Ingredient lists` as the eyebrow and this single helper line:

```blade
<p class="mt-1 max-w-3xl text-sm text-[var(--color-ink-soft)]">Choose a list, then copy it or edit a final version.</p>
```

Remove the formula-basis badge, active-variant badge, and header-level `Copy list` action.

- [x] **Step 2: Add two responsive lanes.** Wrap the generated and final content in a parent with classes `mt-5 grid gap-5 xl:grid-cols-2`. Give each child lane the classes `space-y-5`. Place the INCI generated and final sections in the first lane and the plain-language generated and final sections in the second lane.

Use `INCI ingredient list`, `Plain-language ingredient list`, `Final INCI list`, and `Final plain-language list` with the same `text-base font-semibold text-[var(--color-ink-strong)]` heading class.

- [x] **Step 3: Move variant selection into the INCI lane.** Keep the two existing variant buttons as the selector, but change the incorporated label to `Ingredients as added`. Render the concise helper text from `ingredientListVariantHelperText`, not the backend note.

- [x] **Step 4: Place generated actions with their generated text.** Every generated section gets a compact action group using `sk-btn` classes:

```blade
<button type="button" @click="copyIngredientList(curedSoapOutputListText, 'generated-inci')" class="sk-btn sk-btn-outline">
    Copy list
</button>
<button type="button" @click="useGeneratedIngredientListAsFinal()" class="sk-btn sk-btn-ghost">
    Use generated
</button>
<span x-show="ingredientListCopyTarget === 'generated-inci' && ingredientListCopyMessage" x-text="ingredientListCopyMessage" role="status" aria-live="polite" class="text-xs text-[var(--color-ink-soft)]"></span>
```

Use target names `generated-inci` and `generated-plain` for generated sections.

- [x] **Step 5: Place copy actions with final editors.** Each final section keeps its textarea and outdated warning. Add `Copy list` targeting `final-inci` or `final-plain`; keep `Clear` only when the matching final value is present. Remove the duplicate `Use generated` buttons from final section headers because that action now lives beside the matching generated suggestion.

- [x] **Step 6: Add concise helper copy and preserve declaration behavior.** Use `Common names, highest amount first.` under the plain-language heading. Keep warnings, final textareas, final basis hashes, and declaration details unchanged apart from their position below the two lanes.

- [x] **Step 7: Add the presentation helper getter and clear target feedback on variant changes.** Add:

```js
get ingredientListVariantHelperText() {
    if (this.activeIngredientListVariantKey === 'incorporated_ingredients') {
        return 'Ingredients before saponification, with required allergens.';
    }

    return 'Saponified oil names with the estimated unsaponified oils.';
},
```

Update `selectIngredientListVariant()` to clear `ingredientListCopyMessage` and `ingredientListCopyTarget`.

## Task 4: Verify the complete workflow

**Files:**

- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Source: all files listed above

- [x] **Step 1: Run focused regression tests.**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: all tests pass, including the new hierarchy, action vocabulary, copy helper, basis-hash, and persistence coverage.

- [x] **Step 2: Run formatting.**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: Pint passes without changing unrelated files.

- [x] **Step 3: Build the frontend.**

```bash
npm run build
```

Expected: Vite completes successfully and emits the production manifest.

- [x] **Step 4: Refresh the code graph.**

```bash
graphify update .
```

Expected: Graphify rebuilds the local graph successfully.

- [x] **Step 5: Check the final diff.**

```bash
git diff --check
rg -n "Use as final|inciCopyMessage|Cured soap basis|activeIngredientListVariant\?\.label|copyGeneratedIngredientList" resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php resources/js/recipe-workbench
```

Expected: no obsolete action/state/badge references remain in the ingredient-list workflow. Existing `Cured soap basis` labels elsewhere in the output tab are allowed; the ingredient-list preview itself must not contain the duplicate basis badge.

## Self-Review

- The plan covers the approved spec sections for hierarchy, variants, generated lists, editable final lists, action vocabulary, clipboard handling, responsive behavior, accessibility, and testing.
- No backend labeling or calculation code changes are required.
- The clipboard helper returns `false` for missing or failing browser clipboard APIs and leaves final fields untouched.
- Target names are consistent across Blade actions and the shared `copyIngredientList` method.
- Existing stale-state and persistence behavior is preserved.
