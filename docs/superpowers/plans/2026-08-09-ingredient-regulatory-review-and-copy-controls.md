# Ingredient Regulatory Review and Copy Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add evidence-based EU and U.S. regulatory checks to the ingredient-classification prompt and provide reliable, adjacent Generate and Copy controls in both customer and admin views.

**Architecture:** Keep regulatory instructions inside the existing `IngredientClassificationPromptBuilder`, which remains the single prompt-contract boundary for both callers. Keep the working customer Alpine clipboard component, but render its Copy action before generation and disable it until a prompt exists. Replace the admin Alpine registration with a delegated external JavaScript click handler that reuses `copyText()` and survives Filament/Livewire rendering order and DOM morphs.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Blade, Alpine.js, Vite 8, Pest 4.

**Working-tree note:** The production and test files in this plan already contain broader uncommitted taxonomy/classification work. Do not stage or commit those shared files during task execution. Use scoped diffs and leave the final implementation unstaged unless the user explicitly requests a combined commit.

---

## File Map

- Modify `app/Services/IngredientClassificationPromptBuilder.php`: extend the shared prompt evidence rules and Specialist review response contract.
- Modify `tests/Feature/IngredientClassificationPromptBuilderTest.php`: own the complete EU/U.S. regulatory prompt contract.
- Modify `resources/views/livewire/dashboard/ingredient-editor.blade.php`: place Generate and Copy together and keep Copy visible but disabled until generation.
- Modify `tests/Feature/IngredientEditorLocalizationTest.php`: assert the initial customer helper exposes disabled Copy next to Generate.
- Modify `tests/Feature/UserIngredientAuthoringTest.php`: preserve generated-prompt and enabled-copy behavior.
- Modify `resources/views/filament/resources/ingredients/classification-prompt.blade.php`: render stable adjacent admin controls and delegated-copy data hooks.
- Modify `resources/js/filament/admin/classification-prompt.js`: remove Alpine lifecycle registration and install delegated copy handling.
- Modify `tests/Feature/RecipeWorkbenchPersistenceTest.php`: execute the admin delegated handler contract and remove the obsolete Alpine-registration expectation.
- Modify `tests/Feature/Filament/CatalogResourcesTest.php`: assert initial disabled Copy and generated enabled Copy on create/edit.

### Task 1: Add EU and U.S. Regulatory Evidence to the Prompt Contract

**Files:**
- Modify: `tests/Feature/IngredientClassificationPromptBuilderTest.php`
- Modify: `app/Services/IngredientClassificationPromptBuilder.php`

- [ ] **Step 1: Extend the prompt-builder test with the regulatory contract**

Add assertions to `builds a locale-aware classification prompt with strict evidence boundaries`:

```php
->toContain('Review the exact ingredient and every declared component of a commercial blend')
->toContain('current consolidated Regulation (EC) No 1223/2009')
->toContain('Annex II', 'Annex III', 'Annex IV', 'Annex V', 'Annex VI')
->toContain('official FDA prohibited and restricted cosmetic ingredient information')
->toContain('applicable official 21 CFR provision')
->toContain('Prohibited, Restricted, No specific restriction found, or Not verified')
->toContain('No specific restriction found must not be described as approval')
->toContain('EU regulatory status:')
->toContain('U.S. FDA regulatory status:')
->toContain('exact matched substance or blend component')
```

Also assert the clarification gate explicitly covers incomplete blends:

```php
->toContain('Do not issue a regulatory conclusion for a blend until its components are established')
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php --filter='strict evidence boundaries'
```

Expected: FAIL because the prompt does not yet contain the EU/FDA review contract.

- [ ] **Step 3: Add the regulatory instructions to the prompt builder**

In the clarification gate, add:

```text
- For a commercial blend, review the exact ingredient and every declared component only when an authoritative supplier INCI, SDS, specification sheet, or composition establishes them.
- Do not issue a regulatory conclusion for a blend until its components are established. Ask for the missing supplier documentation instead.
```

In the rules for a sufficiently clear ingredient, add:

```text
- Review the exact ingredient and every declared component of a commercial blend separately for EU and U.S. regulatory restrictions.
- For the EU, check the current consolidated Regulation (EC) No 1223/2009: Annex II for prohibited substances, Annex III for restricted substances, and Annexes IV, V, and VI when colorant, preservative, or UV-filter authorisation is relevant.
- Treat CosIng as informative for identification and functions, not as legal authorisation. The regulation and its annexes establish EU regulatory status.
- For the United States, check official FDA prohibited and restricted cosmetic ingredient information and the applicable official 21 CFR provision. Flag color-additive intended-use requirements and drug or drug/cosmetic implications when relevant.
- Use only these regulatory statuses: Prohibited, Restricted, No specific restriction found, or Not verified.
- For Prohibited or Restricted, identify the exact matched substance or blend component, legal entry, conditions, and a directly accessed official URL.
- No specific restriction found must not be described as approval, proof of safety, complete legal clearance, or finished-product compliance.
- Use Not verified when identity, blend composition, official source access, or legal matching is insufficient.
- Never call an ordinary U.S. cosmetic ingredient FDA approved. Cosmetic ingredients generally do not require FDA premarket approval, except for applicable color additives.
- State the date or version of official material reviewed when the source exposes one. Do not invent a legal entry, condition, concentration, URL, or regulatory conclusion.
```

Extend `Specialist review` with:

```text
- EU regulatory status: Prohibited, Restricted, No specific restriction found, or Not verified; identify the exact material or component reviewed, applicable annex and entry, conditions, and directly accessed official EUR-Lex or European Commission URL
- U.S. FDA regulatory status: Prohibited, Restricted, No specific restriction found, or Not verified; identify the exact material or component reviewed, applicable 21 CFR citation and conditions, and directly accessed official FDA or eCFR URL
```

- [ ] **Step 4: Run the prompt-builder tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php
```

Expected: 2 tests pass with all prompt-contract assertions.

- [ ] **Step 5: Inspect the scoped diff without staging**

Run:

```bash
git diff -- app/Services/IngredientClassificationPromptBuilder.php tests/Feature/IngredientClassificationPromptBuilderTest.php
```

Expected: only the regulatory prompt rules and matching assertions are added within these already-dirty files.

### Task 2: Place Customer Generate and Copy Controls Together

**Files:**
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`

- [ ] **Step 1: Change the initial-render expectation to require Copy**

In `IngredientEditorLocalizationTest`, replace the initial `assertDontSeeText('Copy prompt')` with assertions that both controls render and that the Copy button is disabled. Give the button a stable hook in the Blade implementation and assert it without escaping:

```php
->assertSeeText('Generate prompt')
->assertSeeText('Copy prompt')
->assertSee('data-classification-prompt-copy disabled', escape: false);
```

In `UserIngredientAuthoringTest`, retain the existing generated-state `assertSeeText('Copy prompt')` and add:

```php
->assertDontSee('data-classification-prompt-copy disabled', escape: false);
```

- [ ] **Step 2: Run both focused tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php --filter='classification prompt'
```

Expected: FAIL because Copy is currently absent before generation and remains below the preview.

- [ ] **Step 3: Render adjacent customer controls**

Replace the single Generate button in the helper header with:

```blade
<div class="flex flex-wrap items-center gap-3">
    <button
        type="button"
        class="sk-btn sk-btn-outline"
        wire:click="generateClassificationPrompt"
        wire:loading.attr="disabled"
        wire:target="generateClassificationPrompt"
    >
        {{ __('ingredients.editor.classification_prompt.generate') }}
    </button>
    <button
        type="button"
        class="sk-btn sk-btn-outline"
        data-classification-prompt-copy
        @disabled($generatedClassificationPrompt === null)
        x-on:click="copy($refs.classificationPrompt?.value ?? '')"
    >
        <span x-show="! copied">{{ __('ingredients.editor.classification_prompt.copy') }}</span>
        <span x-cloak x-show="copied">{{ __('ingredients.editor.classification_prompt.copied') }}</span>
    </button>
</div>
```

Remove the duplicate Copy button below the textarea. Keep only the translated failure paragraph below the preview:

```blade
<p x-cloak x-show="copyFailed" class="mt-3 text-sm text-[var(--color-danger-strong)]">
    {{ __('ingredients.editor.classification_prompt.copy_failed') }}
</p>
```

- [ ] **Step 4: Run the customer helper tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php --filter='classification prompt'
```

Expected: the customer helper tests pass and prove Copy is visible-disabled before generation and enabled afterward.

- [ ] **Step 5: Inspect the scoped diff without staging**

Run:

```bash
git diff -- resources/views/livewire/dashboard/ingredient-editor.blade.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php
```

Expected: the Copy control moves beside Generate without changing prompt generation or persistence.

### Task 3: Replace the Admin Alpine Clipboard Component with Delegated Copying

**Files:**
- Modify: `resources/js/filament/admin/classification-prompt.js`
- Modify: `resources/views/filament/resources/ingredients/classification-prompt.blade.php`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`

- [ ] **Step 1: Replace the obsolete Alpine-registration contract test**

Update `loads the shared classification prompt clipboard component in the admin panel` to assert the new dependency and hooks:

```php
expect($source)
    ->toContain("import { copyText } from '../../recipe-workbench/clipboard.js';")
    ->toContain("document.addEventListener('click'")
    ->toContain("closest('[data-ingredient-classification-copy]')")
    ->not->toContain("document.addEventListener('alpine:init'")
    ->not->toContain('window.Alpine.data');

expect($view)
    ->toContain('data-ingredient-classification-helper')
    ->toContain('data-ingredient-classification-copy')
    ->toContain('data-ingredient-classification-prompt')
    ->not->toContain('x-data="classificationPrompt"');
```

Add create-page assertions to `CatalogResourcesTest`:

```php
->assertSeeText('Generate prompt')
->assertSeeText('Copy prompt')
->assertSee('data-ingredient-classification-copy disabled', escape: false);
```

After `generateIngredientClassificationPrompt`, assert:

```php
->assertDontSee('data-ingredient-classification-copy disabled', escape: false);
```

- [ ] **Step 2: Run the admin contract tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php --filter='classification prompt clipboard component'
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter='classification prompt|classification helper'
```

Expected: FAIL because the current admin module still registers during `alpine:init` and Copy is conditional below the preview.

- [ ] **Step 3: Implement delegated admin clipboard handling**

Replace `resources/js/filament/admin/classification-prompt.js` with:

```js
import { copyText } from '../../recipe-workbench/clipboard.js';

document.addEventListener('click', async (event) => {
    const trigger = event.target instanceof Element
        ? event.target.closest('[data-ingredient-classification-copy]')
        : null;

    if (!(trigger instanceof HTMLButtonElement) || trigger.disabled) {
        return;
    }

    const helper = trigger.closest('[data-ingredient-classification-helper]');
    const prompt = helper?.querySelector('[data-ingredient-classification-prompt]');
    const failure = helper?.querySelector('[data-ingredient-classification-copy-failed]');

    if (!(prompt instanceof HTMLTextAreaElement)) {
        return;
    }

    const copied = await copyText(prompt.value);

    failure?.classList.toggle('hidden', copied);

    if (!copied) {
        prompt.focus();
        prompt.select();

        return;
    }

    const copyLabel = trigger.dataset.copyLabel ?? trigger.textContent.trim();
    const copiedLabel = trigger.dataset.copiedLabel ?? copyLabel;

    trigger.textContent = copiedLabel;

    window.setTimeout(() => {
        if (trigger.isConnected) {
            trigger.textContent = copyLabel;
        }
    }, 1500);
});
```

- [ ] **Step 4: Render stable adjacent admin controls**

Remove `x-data` from the section and add the stable helper attribute:

```blade
<x-filament::section data-ingredient-classification-helper>
```

Render both controls in one flex container before the preview:

```blade
<div class="flex flex-wrap items-center gap-3">
    <x-filament::button
        type="button"
        color="gray"
        wire:click="generateIngredientClassificationPrompt"
        wire:loading.attr="disabled"
        wire:target="generateIngredientClassificationPrompt"
    >
        {{ __('ingredients.editor.classification_prompt.generate') }}
    </x-filament::button>

    <x-filament::button
        type="button"
        color="gray"
        data-ingredient-classification-copy
        data-copy-label="{{ __('ingredients.editor.classification_prompt.copy') }}"
        data-copied-label="{{ __('ingredients.editor.classification_prompt.copied') }}"
        :disabled="$this->generatedIngredientClassificationPrompt === null"
    >
        {{ __('ingredients.editor.classification_prompt.copy') }}
    </x-filament::button>
</div>
```

Add `data-ingredient-classification-prompt` to the textarea. Remove the old conditional Copy button and render the failure message with a real initial hidden state:

```blade
<p
    data-ingredient-classification-copy-failed
    class="hidden text-sm text-danger-600 dark:text-danger-400"
>
    {{ __('ingredients.editor.classification_prompt.copy_failed') }}
</p>
```

- [ ] **Step 5: Run the focused admin tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php --filter='classification prompt clipboard component'
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter='classification prompt|classification helper'
```

Expected: the admin contracts pass, the label is server rendered, and no Alpine registration remains.

- [ ] **Step 6: Build the frontend bundle**

Run:

```bash
npm run build
```

Expected: Vite succeeds and emits the admin classification-prompt entry without compilation errors.

- [ ] **Step 7: Inspect the scoped diff without staging**

Run:

```bash
git diff -- resources/js/filament/admin/classification-prompt.js resources/views/filament/resources/ingredients/classification-prompt.blade.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/Filament/CatalogResourcesTest.php vite.config.js app/Providers/Filament/AdminPanelProvider.php
```

Expected: the admin helper no longer relies on Alpine initialization; the existing provider and Vite entry remain valid.

### Task 4: Run Final Verification

**Files:**
- Verify all files listed in the File Map

- [ ] **Step 1: Run the complete focused feature set**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: all relevant prompt, layout, admin, clipboard, and persistence tests pass; existing intentionally skipped tests remain skipped.

- [ ] **Step 2: Run Filament and PHP format checks**

Run:

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
```

Expected: Filacheck reports no unresolved violations and Pint completes successfully.

- [ ] **Step 3: Re-run tests affected by formatter changes**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: the focused suite still passes.

- [ ] **Step 4: Refresh the knowledge graph and inspect whitespace**

Run:

```bash
graphify update .
git diff --check
```

Expected: Graphify completes and `git diff --check` produces no output.

- [ ] **Step 5: Review only the scoped implementation changes**

Run:

```bash
git diff -- app/Services/IngredientClassificationPromptBuilder.php resources/views/livewire/dashboard/ingredient-editor.blade.php resources/views/filament/resources/ingredients/classification-prompt.blade.php resources/js/filament/admin/classification-prompt.js tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: all changes map directly to the approved specification, with no staged production files and no unrelated edits introduced by this plan.
