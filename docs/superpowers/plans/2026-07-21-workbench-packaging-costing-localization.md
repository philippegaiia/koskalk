# Workbench Packaging and Costing Localization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Packaging and Costing tabs' hard-coded copy with approved English source strings and contextual French, Spanish, German, Italian, and Dutch database translations.

**Architecture:** Add two nested namespaces to the existing English `workbench` language group and resolve them consistently in Blade, Alpine, JavaScript, and Livewire. Synchronize those English keys into `language_lines`, then use a temporary blank-only validated importer for the five supported translations.

**Tech Stack:** Laravel 13 localization, Livewire 4, Alpine.js, Vite, Pest 4, Spatie Translation Loader.

---

### Task 1: Lock the revised English interface contract

**Files:**
- Modify: `tests/Feature/RecipeWorkbenchCostingContentTest.php`
- Modify: `tests/Feature/SoapWorkbenchLocalizationTest.php`

- [ ] **Step 1: Replace the old copy assertions**

Assert the rendered workbench contains, in order, `Ingredient costs`, `Packaging costs`, and `Cost summary`. Assert the Packaging tab and modal contain `Packaging plan`, `Quantity per unit`, `No packaging added yet.`, `Create packaging item`, `Save to library`, and `Save and add`. Assert Costing contains `Costing setup`, `Oil quantity`, `Finished units`, `Your price / kg`, `Enter finished units`, and the soap phases `Saponification`, `Formula additions`, and `Fragrance and aromatics`. Assert the removed implementation-oriented copy is absent.

- [ ] **Step 2: Add a cosmetic batch-basis assertion**

Build a cosmetic draft with a user-authored phase and assert `Total batch quantity` is rendered while the authored phase name remains unchanged.

- [ ] **Step 3: Run the focused test and confirm RED**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
```

Expected: failures for the newly approved wording because the views still contain the old hard-coded copy.

### Task 2: Localize all Packaging and Costing application surfaces

**Files:**
- Modify: `lang/en/workbench.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/packaging-tab.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/packaging-catalog-modal.blade.php`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/js/recipe-workbench/bridge.js`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`

- [ ] **Step 1: Define the English key structure**

Add `workbench.packaging.*` keys for the plan header, catalog search, table, accessibility text, modal, and messages. Add `workbench.costing.*` keys for setup, save status, fields, ingredient costs, packaging costs, summary, phases, accessibility text, and messages. Use `:item`, `:unit`, and `:count` placeholders where text is dynamic.

- [ ] **Step 2: Replace Blade literals**

Use `__('workbench.packaging...')` and `__('workbench.costing...')` for server-rendered text. Use the existing Alpine `t()` helper for row-specific labels, counts, phase labels, and fallbacks. Select the batch-basis label with `isSoap ? t('costing.settings.oil_quantity') : t('costing.settings.batch_quantity')`.

- [ ] **Step 3: Replace JavaScript literals**

Map the three system soap phases to translation keys rather than English constants. Translate load, save, validation, and unsaved-product fallbacks using `workbench.t()` while continuing to prefer the translated Livewire response when supplied.

- [ ] **Step 4: Translate Livewire responses**

Return `__('workbench.costing.messages.save_product')`, `__('workbench.costing.messages.saved')`, `__('workbench.costing.messages.load_product')`, `__('workbench.packaging.messages.sign_in')`, and `__('workbench.packaging.messages.saved')` from the Packaging/Costing endpoints without changing their response shapes.

- [ ] **Step 5: Run the focused tests and confirm GREEN**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: all tests pass.

### Task 3: Populate contextual database translations

**Files:**
- Create temporarily, then delete: `storage/app/private/packaging-costing-contextual-translations.php`
- Modify locally: `language_lines` database rows created from `lang/en/workbench.php`

- [ ] **Step 1: Synchronize the English source**

Run:

```bash
php artisan translations:sync
```

Expected: every new `workbench.packaging.*` and `workbench.costing.*` key exists in `language_lines` with its English value.

- [ ] **Step 2: Create the validated blank-only importer**

The temporary PHP file must contain exact French, Spanish, German, Italian, and Dutch values for every new key; reject missing or extra keys; compare every `:placeholder` set with English; and update a locale only when its database value is blank.

- [ ] **Step 3: Run the importer and validate completeness**

Run the temporary importer through the application context, then query the `workbench` rows to prove that all six locales are nonblank and every placeholder matches.

- [ ] **Step 4: Delete the temporary importer**

Remove `storage/app/private/packaging-costing-contextual-translations.php` after successful validation so translation data remains database-owned.

### Task 4: Verify the finished workbench

**Files:**
- Verify: all files from Tasks 1 and 2

- [ ] **Step 1: Format PHP**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: no unformatted PHP remains.

- [ ] **Step 2: Build frontend assets**

Run:

```bash
npm run build
```

Expected: Vite succeeds with no compile errors.

- [ ] **Step 3: Run focused regression tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchCostingContentTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/SearchComboboxAdoptionTest.php
```

Expected: all tests pass.

- [ ] **Step 4: Review all locales in the browser**

Open both tabs for soap and cosmetics in English, French, Spanish, German, Italian, and Dutch. Confirm natural terminology, correct phase behavior, complete modal copy, responsive layout, and no raw translation keys or English fallbacks.

- [ ] **Step 5: Refresh the code graph and inspect the diff**

Run:

```bash
graphify update .
git diff --check
git status --short
```

Expected: graph refresh succeeds, the diff has no whitespace errors, and only intended implementation/test/documentation files are changed.
