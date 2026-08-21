# Remaining Contextual Help and WordPress Editorial Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the first contextual-help program for Ingredients, Compliance, Settings, and remaining shared concepts, then establish the repeatable translation and WordPress publishing workflow.

**Architecture:** Remaining surfaces reuse the shared panel and declare small page-specific topic subsets. Compliance help remains guidance and never replaces inline warnings. Editorial work follows one sequence: approve English, humanize without changing meaning, synchronize keys, draft and render-review translations, publish WordPress articles, add locale-specific URLs, then export the translation catalogue.

**Tech Stack:** Laravel language files, Blade, Livewire 4, Filament form substrate, shared contextual-help foundation, Spatie Translation Loader, Pest 4, WordPress.

---

## File map

**Create:**

- `lang/en/help_ingredients.php`
- `lang/en/help_compliance.php`
- `lang/en/help_settings.php`
- `tests/Feature/ContextualHelpRemainingSurfacesTest.php`

**Modify:**

- `resources/views/livewire/dashboard/ingredients-index.blade.php`
- `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- `resources/views/livewire/dashboard/settings-index.blade.php`
- selected workbench compliance and labeling partials if a remaining topic is not already registered.
- `database/seeders/data/interface-translations.json`
- ingredient, settings, workbench, and catalogue localization tests.

### Task 1: Define the remaining topic inventory

- [ ] **Step 1: Generate the failing test**

Run: `php artisan make:test --pest ContextualHelpRemainingSurfacesTest --no-interaction`

- [ ] **Step 2: Assert Ingredient topics**

```text
help_ingredients.catalog.platform_vs_private
help_ingredients.identity.inci_and_identifiers
help_ingredients.classification.category_and_functions
help_ingredients.composition.blend
help_ingredients.soap.sap_and_fatty_acids
help_ingredients.lifecycle.formula_usage
help_ingredients.lifecycle.editing_and_deletion
```

- [ ] **Step 3: Assert Compliance topics**

```text
help_compliance.scope.guidance_not_certification
help_compliance.scope.live_reference_data
help_compliance.regime.market_framework
help_compliance.ifra.product_category
help_compliance.substances.allergens_and_restrictions
help_compliance.labeling.review_after_ingredient_change
```

- [ ] **Step 4: Assert Settings topics**

```text
help_settings.preferences.interface_language
help_settings.preferences.number_format
help_settings.workspace.default_currency
help_settings.workspace.mass_display
help_settings.workspace.owner_scope
```

Every topic passes the foundation schema. Compliance topics must contain explicit limitation language and must not claim certification or automatic regulatory approval.

- [ ] **Step 5: Verify red and author English content**

Run: `php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php`

Preserve these decisions:

- Platform ingredients are Soapkraft-managed references; private ingredients belong to the workspace.
- INCI and identifiers are identity data, not marketing names.
- Blend composition describes ingredient composition and does not create a nested product formula.
- Formula usage explains why an ingredient may be unsafe to delete.
- Compliance output is point-in-time guidance based on live linked data.
- A linked ingredient change requires formula labeling and compliance guidance to be reviewed again.
- Interface language and number format are independent preferences.
- Workspace currency controls display and costing defaults; it does not rewrite historical supplier currencies.

- [ ] **Step 6: Synchronize and review all text translations**

Run `php artisan translations:sync`, draft every blank text field for `de`, `es`, `fr`, `it`, `nl`, and `pt_BR`, review each topic in its real surface, then run `php artisan translations:catalogue:export`. Leave `article_url` absent until publication.

- [ ] **Step 7: Verify and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git add lang/en/help_ingredients.php lang/en/help_compliance.php lang/en/help_settings.php database/seeders/data/interface-translations.json tests/Feature/ContextualHelpRemainingSurfacesTest.php
git commit -m "feat: add remaining contextual help content"
```

### Task 2: Add Ingredient catalogue and editor help

**Files:** Ingredient index, editor, and localization tests.

- [ ] **Step 1: Assert page-level subsets and selective triggers**

The index registers Platform versus private, Formula usage, and Editing and deletion. Add Help beside the catalogue heading and `Why?` beside formula-usage deletion blocking.

The editor registers INCI and identifiers, Category and functions, Blend composition, SAP and fatty acids, Guidance not certification, Allergens and restrictions, and Review after ingredient change. Add section triggers to the platform regulatory summary and the carrier-oil chemistry warning. Keep the safety warning visible.

- [ ] **Step 2: Verify red and implement**

Run:

```bash
php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/IngredientTranslationTest.php
```

Render one registry per page and selective triggers. Do not change ingredient ownership filters, identity persistence, formulas using an ingredient, deletion safeguards, platform read-only behavior, or Filament form state.

- [ ] **Step 3: Run regression and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/IngredientTranslationTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/IngredientsIndexLocalizationTest.php tests/Feature/IngredientsIndexDuplicationTest.php
git add resources/views/livewire/dashboard/ingredients-index.blade.php resources/views/livewire/dashboard/ingredient-editor.blade.php tests/Feature/ContextualHelpRemainingSurfacesTest.php
git commit -m "feat: add Ingredient contextual help"
```

### Task 3: Add Settings help

**Files:** Settings view and settings tests.

- [ ] **Step 1: Test active-tab topic subsets**

Preferences registers Interface language and Number format. Workspace registers Default currency, Mass display, and Owner scope. A Livewire tab change must replace the registry subset without leaving topics from the previous tab.

- [ ] **Step 2: Verify red and implement**

Run: `php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/SettingsLocalizationTest.php tests/Feature/SettingsSecurityTest.php`

Render the current-tab registry and a page-level Help trigger. Add direct triggers beside language, number format, currency, and mass-display controls. Use only `type="button"` contextual-help triggers; leave `savePreferences` and `saveWorkspace` unchanged.

- [ ] **Step 3: Verify and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/SettingsLocalizationTest.php tests/Feature/SettingsSecurityTest.php
git add resources/views/livewire/dashboard/settings-index.blade.php tests/Feature/ContextualHelpRemainingSurfacesTest.php
git commit -m "feat: add Settings contextual help"
```

### Task 4: Complete Compliance trigger coverage

**Files:** remaining workbench compliance and labeling partials plus tests.

- [ ] **Step 1: Audit registered Compliance topics**

Use `rg -n "help_compliance\." resources/views` and compare the result with the six-topic inventory. Add only missing surface associations. At minimum, market framework and IFRA category belong near formula settings; allergens and restrictions belong near their output; review-after-change belongs near the catalogue review warning.

- [ ] **Step 2: Add failing assertions for each missing association**

Extend `ContextualHelpRemainingSurfacesTest.php` with the exact partial and expected topic key before editing the partial.

- [ ] **Step 3: Add triggers without replacing warnings**

Add section Help or state `Why?` triggers. Keep restriction warnings, IFRA evidence, missing-INCI behavior, and catalogue-review notices visible and unchanged.

- [ ] **Step 4: Run compliance regression and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git add resources/views/livewire/dashboard/partials/recipe-workbench tests/Feature/ContextualHelpRemainingSurfacesTest.php
git commit -m "feat: complete Compliance contextual help"
```

### Task 5: Publish remaining WordPress guides

- [ ] **Step 1: Publish the English article set**

```text
/docs/ingredients/platform-and-private-ingredients/
/docs/ingredients/identity-composition-and-soap-data/
/docs/ingredients/editing-used-ingredients/
/docs/compliance/how-soapkraft-guidance-works/
/docs/compliance/market-frameworks-ifra-and-restrictions/
/docs/compliance/reviewing-after-reference-data-changes/
/docs/settings/language-number-format-and-workspace-defaults/
```

Each article includes complete methods and examples but does not overstate compliance authority. Add English topic URLs only after publication.

- [ ] **Step 2: Publish localized articles independently**

Use the locale glossary already approved for interface topics. A locale may have translated in-app help while its guide URL remains blank. Never use the English article URL as the translated `article_url` value.

### Task 6: Complete translation and editorial promotion

- [ ] **Step 1: Apply the English editorial sequence**

For every new or changed topic:

1. verify scientific, regulatory, and operational meaning;
2. apply Humanizer to English only;
3. recheck controlled vocabulary, negations, quantities, and consequences;
4. keep the existing key for wording-only edits;
5. use a new key or deliberately clear affected locale values when meaning changes.

- [ ] **Step 2: Synchronize and draft locale values**

Run: `php artisan translations:sync`

The command must create only missing rows and leave existing translations unchanged. Draft only blank `de`, `es`, `fr`, `it`, `nl`, and `pt_BR` values. Review each in the rendered task.

- [ ] **Step 3: Export and run full contextual-help verification**

```bash
php artisan translations:catalogue:export
php artisan test --compact tests/Feature/ContextualHelpContentTest.php tests/Feature/ContextualHelpRenderingTest.php tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/ContextualHelpProductionPlanningExecutionTest.php tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ContextualHelpRemainingSurfacesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Unit/ContextualHelpInteractionTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: every registered English topic resolves; all six locale values are reviewed or fall back to English; missing localized guide URLs remain absent; all tests and build pass.

- [ ] **Step 4: Commit the completed rollout**

```bash
git add database/seeders/data/interface-translations.json lang/en/help_ingredients.php lang/en/help_compliance.php lang/en/help_settings.php tests/Feature/ContextualHelpRemainingSurfacesTest.php
git commit -m "feat: complete contextual help rollout"
```
