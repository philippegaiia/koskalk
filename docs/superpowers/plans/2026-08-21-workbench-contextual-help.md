# Soap and Cosmetic Workbench Contextual Help Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add concise, localized contextual help to the Soap and Cosmetic Workbenches while keeping their specialized concepts separate and linking each topic to the correct WordPress guide.

**Architecture:** The workbench root declares the family-specific page topic list and renders one registry. Reusable triggers in existing partials open shared, Soap, or Cosmetic topics without touching workbench state. English help and localized guide URLs use separate domain groups and the existing translation catalogue.

**Tech Stack:** Laravel language files, Blade, existing Alpine recipe workbench, shared contextual-help foundation, Spatie Translation Loader, Pest 4, WordPress.

---

## File map

**Create:**

- `lang/en/help_workbench.php`
- `lang/en/help_soap_workbench.php`
- `lang/en/help_cosmetic_workbench.php`
- `tests/Feature/ContextualHelpWorkbenchTest.php`

**Modify:**

- `resources/views/livewire/dashboard/recipe-workbench.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/header.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/formula-analysis.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/restrictions-preview.blade.php`
- `database/seeders/data/interface-translations.json`
- `tests/Feature/SoapWorkbenchLocalizationTest.php`
- `tests/Feature/CosmeticRecipeWorkbenchTest.php`

### Task 1: Define the shared and specialized topic inventories

**Files:** three new language files and `tests/Feature/ContextualHelpWorkbenchTest.php`

- [ ] **Step 1: Generate the failing feature test**

Run: `php artisan make:test --pest ContextualHelpWorkbenchTest --no-interaction`

- [ ] **Step 2: Assert exact topic ownership**

The shared group owns only:

```text
help_workbench.formula.entry_basis
help_workbench.ingredients.selection
help_workbench.product.application
help_workbench.compliance.regulatory_framework
help_workbench.product.saving_and_locking
help_workbench.costing.workspace_currency
help_workbench.packaging.output_basis
```

The Soap group owns only Soap concepts:

```text
help_soap_workbench.settings.lye_type
help_soap_workbench.settings.lye_purity
help_soap_workbench.saponification.water_mode
help_soap_workbench.saponification.superfat
help_soap_workbench.saponification.dilution_liquids
help_soap_workbench.saponification.balance
help_soap_workbench.analysis.fatty_acid_profile
help_soap_workbench.analysis.soap_qualities
help_soap_workbench.analysis.dos_risk
help_soap_workbench.output.cured_basis
help_soap_workbench.labeling.ingredient_order
```

The Cosmetic group owns only Cosmetic concepts:

```text
help_cosmetic_workbench.formula.total_basis
help_cosmetic_workbench.formula.phases
help_cosmetic_workbench.formula.ingredient_function
help_cosmetic_workbench.settings.application_context
help_cosmetic_workbench.compliance.preservation_and_ph
help_cosmetic_workbench.output.formula_basis
help_cosmetic_workbench.labeling.ingredient_order
```

Assert no specialized topic root appears in both family groups and every topic passes the foundation schema validator.

- [ ] **Step 3: Verify the red test**

Run: `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php`

Expected: FAIL because the groups are absent.

- [ ] **Step 4: Author approved English content**

Each topic includes a direct title and summary. Add `what_to_do` where the user can act and `why` where the topic explains a result or warning. Follow these non-negotiable content decisions:

- `entry_basis` distinguishes percentage entry from weight entry without implying that display rounding changes calculations.
- `water_mode` explains percent of oils, water-to-lye ratio, and lye concentration; it calls water and substitutes dilution liquids and the completed NaOH/KOH mixture the alkali solution.
- `superfat` states that negative values increase alkali relative to the theoretical requirement and require deliberate review.
- `soap_qualities` and `dos_risk` describe estimates and review signals, not guarantees.
- `cured_basis` distinguishes fresh and cured output.
- `total_basis` and `phases` state that Cosmetic percentages use the total formula and that phases organize processing rather than change the percentage basis.
- `preservation_and_ph` provides context only and never certifies preservation safety.
- both labeling topics explain their own family-specific declaration basis.

Run the Humanizer skill on the English draft after domain review. Preserve `Saponification`, `Formula additions`, `alkali solution`, `dilution liquid`, INCI, controlled identifiers, warnings, and numerical meaning.

- [ ] **Step 5: Synchronize and review all text translations**

Run `php artisan translations:sync`, draft every blank text field for `de`, `es`, `fr`, `it`, `nl`, and `pt_BR`, review the topics in the panel, then run `php artisan translations:catalogue:export`. Do not add `article_url` fields before the corresponding WordPress pages are published.

- [ ] **Step 6: Verify content and commit**

Run: `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php`

```bash
git add lang/en/help_workbench.php lang/en/help_soap_workbench.php lang/en/help_cosmetic_workbench.php database/seeders/data/interface-translations.json tests/Feature/ContextualHelpWorkbenchTest.php
git commit -m "feat: add workbench contextual help content"
```

### Task 2: Register the correct page-level topic subset

**Files:** workbench root, header partial, and workbench help test.

- [ ] **Step 1: Test family separation in rendered payloads**

Render Soap and Cosmetic workbenches and assert:

```php
expect($soapHtml)
    ->toContain('help_soap_workbench.saponification.water_mode')
    ->not->toContain('help_cosmetic_workbench.formula.phases');

expect($cosmeticHtml)
    ->toContain('help_cosmetic_workbench.formula.phases')
    ->not->toContain('help_soap_workbench.saponification.water_mode');
```

Both payloads must contain the shared topics and only one `data-contextual-help-topics` registry.

- [ ] **Step 2: Verify the red test**

Run: `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php --filter="page topic subset"`

- [ ] **Step 3: Add family-specific topic arrays**

In `recipe-workbench.blade.php`, derive `$pageHelpTopics` from `$isCosmeticWorkbench`. Merge the seven shared keys with only the current family keys, render `<x-contextual-help.topics :topics="$pageHelpTopics" />`, and pass the same ordered array to the header partial.

Add one visible text Help trigger to the workbench header:

```blade
<x-contextual-help.trigger :topics="$pageHelpTopics" variant="text" />
```

- [ ] **Step 4: Run and commit**

Run: `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php`

```bash
git add resources/views/livewire/dashboard/recipe-workbench.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/header.blade.php tests/Feature/ContextualHelpWorkbenchTest.php
git commit -m "feat: register workbench help topics"
```

### Task 3: Add selective Soap triggers and state explanations

**Files:** formula settings, ingredient browser, reaction core, fatty acids, analysis, output, restrictions, and help test.

- [ ] **Step 1: Add failing trigger-placement assertions**

Require direct trigger data attributes at these exact locations:

- Formula settings: lye type, KOH purity, water calculation mode, superfat, and alternative dilution liquids.
- Ingredient browser heading: shared ingredient selection.
- Saponification section heading: balance.
- Fatty-acid profile heading: fatty-acid profile.
- Soap qualities heading: soap qualities.
- DOS warning or review state: `analysis.dos_risk` using visible `Why?` text.
- Cured output heading: cured basis.
- Soap ingredient list heading: Soap labeling order.

Assert every trigger is `type="button"` and contains neither `wire:click` nor a form submission attribute.

- [ ] **Step 2: Verify red, add triggers, and preserve state**

Run: `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php --filter="Soap triggers"`

Add section-level text Help triggers, selective icon field triggers, and the state-level `Why?` trigger. Do not add a trigger beside every label. Do not change `x-model`, formula calculations, autosave, dirty-state registration, or workbench payload fields.

- [ ] **Step 3: Run regression tests and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
git add resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/fatty-acid-profile.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/formula-analysis.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/restrictions-preview.blade.php tests/Feature/ContextualHelpWorkbenchTest.php
git commit -m "feat: add Soap Workbench help triggers"
```

### Task 4: Add selective Cosmetic triggers

**Files:** formula settings, ingredient browser, cosmetic formula, output, restrictions, and tests.

- [ ] **Step 1: Add failing placement assertions**

Require triggers for total-formula basis, formula phases, ingredient function, application context, preservation and pH context, cosmetic output basis, and cosmetic ingredient order. Assert no Soap specialized key appears in a Cosmetic-only branch.

- [ ] **Step 2: Verify red and add triggers**

Run: `php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php --filter="Cosmetic triggers"`

Place triggers beside consequential headings or controls. Preservation and pH warnings remain inline; contextual help explains their meaning without replacing them.

- [ ] **Step 3: Run regression tests and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
git add resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/restrictions-preview.blade.php tests/Feature/ContextualHelpWorkbenchTest.php
git commit -m "feat: add Cosmetic Workbench help triggers"
```

### Task 5: Publish matching WordPress guides and finalize localization

**Files:** translation catalogue and localization tests. WordPress content is edited in WordPress, not copied into Laravel.

- [ ] **Step 1: Draft and publish the English WordPress article set**

Use these canonical article paths and stable anchors:

```text
/docs/workbenches/formula-basics/
/docs/soap/lye-and-purity/
/docs/soap/water-calculation-modes/
/docs/soap/superfat-and-formula-balance/
/docs/soap/fatty-acids-and-soap-qualities/
/docs/soap/cured-output-and-labeling/
/docs/cosmetics/formula-basis-and-phases/
/docs/cosmetics/application-preservation-and-ph/
/docs/cosmetics/output-and-labeling/
```

Each article covers complete methods and examples. Add a topic's English `article_url` only after the exact article or anchor returns a published page.

- [ ] **Step 2: Review and refine every configured locale**

Review the existing `de`, `es`, `fr`, `it`, `nl`, and `pt_BR` text in both direct and page-index flows. Use one glossary per locale, translate intent rather than English structure, and preserve every scientific caveat. Revise database values without replacing already reviewed text blindly.

- [ ] **Step 3: Publish localized articles and add URLs selectively**

For each locale, add an `article_url` value only after its localized WordPress article is published. Leave the URL blank when the article is missing; confirm the panel hides the link while content still renders.

- [ ] **Step 4: Export and verify**

```bash
php artisan translations:catalogue:export
php artisan test --compact tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

Expected: all workbench topics have six reviewed text locales, links exist only for published localized articles, tests pass, and the build is clean.

- [ ] **Step 5: Commit the completed workbench slice**

```bash
git add database/seeders/data/interface-translations.json tests/Feature/ContextualHelpWorkbenchTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "feat: translate workbench contextual help"
```
