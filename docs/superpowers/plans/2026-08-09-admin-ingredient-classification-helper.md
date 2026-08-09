# Admin Ingredient Classification Helper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse one locale-aware, evidence-constrained ingredient classification prompt in the customer dashboard and the Filament admin ingredient Create/Edit form.

**Architecture:** Extract prompt composition into a focused builder receiving a readonly input object. The dashboard and admin pages map their own current unsaved form state into that input. Admin Create/Edit share a small Livewire concern and a schema-local Blade view for generate, preview, and direct-click copy behaviour.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Pest 4, Vite 8, Alpine.js, Laravel translation files, and the authoritative interface-translation catalogue.

---

## Working-Tree Safety

The taxonomy branch already contains broad uncommitted changes in most implementation files covered here. Do not stage or commit those implementation files independently: whole-file commits would absorb unrelated work. Use scoped diffs and tests for checkpoints. Only this plan document may be committed separately.

## File Structure

- Create `app/Data/IngredientClassificationPromptInput.php`: immutable prompt identity and locale input.
- Create `app/Services/IngredientClassificationPromptBuilder.php`: sole owner of taxonomy/function vocabulary and prompt wording.
- Create `tests/Feature/IngredientClassificationPromptBuilderTest.php`: complete prompt contract and locale/evidence rules.
- Modify `app/Livewire/Dashboard/IngredientEditor.php`: map customer form state into the shared builder.
- Modify `tests/Feature/UserIngredientAuthoringTest.php`: retain only customer state-mapping and guard assertions.
- Create `app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientClassificationPrompt.php`: shared Create/Edit prompt state and generate action.
- Modify `app/Filament/Resources/Ingredients/Pages/CreateIngredient.php`: use the admin prompt concern.
- Modify `app/Filament/Resources/Ingredients/Pages/EditIngredient.php`: use the admin prompt concern.
- Modify `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`: insert the helper after Material Identity.
- Create `resources/views/filament/resources/ingredients/classification-prompt.blade.php`: compact admin generate/preview/copy UI.
- Create `resources/js/filament/admin/classification-prompt.js`: expose only the shared clipboard component to Filament.
- Modify `app/Providers/Filament/AdminPanelProvider.php`: register the admin classification asset.
- Modify `vite.config.js`: build the small admin classification entry point.
- Modify `tests/Feature/Filament/CatalogResourcesTest.php`: cover Create/Edit unsaved state, blank guard, and persistence isolation.
- Modify `lang/en/ingredients.php`: admin helper copy and the beginner-facing preservation-booster label.
- Modify `database/seeders/data/interface-translations.json`: synchronized translations for all supported locales.
- Modify `tests/Feature/IngredientTaxonomyLocalizationTest.php`: assert the revised stable-value label.
- Modify `tests/Feature/InterfaceTranslationCatalogueTest.php`: assert new helper translations are catalogue-owned.

### Task 1: Extract the Shared Locale-Aware Prompt Builder

**Files:**
- Create: `app/Data/IngredientClassificationPromptInput.php`
- Create: `app/Services/IngredientClassificationPromptBuilder.php`
- Create: `tests/Feature/IngredientClassificationPromptBuilderTest.php`

- [ ] **Step 1: Write the failing builder contract test**

Create a Pest feature test that seeds an active `humectant` function, builds an input for vegetable glycerin with `responseLocale: 'fr'`, and asserts the prompt contains:

```php
expect($prompt)
    ->toContain('Answer in: French — français (fr).')
    ->toContain('Keep category, subcategory, and function backing values exactly as supplied.')
    ->toContain('"name": "Vegetable glycerin"', '"inci_name": "GLYCERIN"')
    ->toContain('humectants_polyols', 'glycerin_glycols', 'humectant')
    ->toContain('A catalogue category describes the primary practical material role')
    ->toContain('Practical formulation roles must not be returned as COSING assignments')
    ->toContain('does not establish authorization under EU Cosmetics Regulation Annex V')
    ->toContain('directly accessed official European Commission COSING source name and URL')
    ->toContain('Label mirrors and secondary sources as secondary evidence')
    ->toContain('Do not include a commercial product example unless')
    ->toContain('Do not provide a usage level unless')
    ->toContain('Do not infer natural origin')
    ->not->toContain('Respond in the user\'s language when it is evident');
```

Also assert that an English input produces `Answer in: English (en).` and that unknown regional input safely falls back to the locale code rather than throwing.

- [ ] **Step 2: Run the new test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php
```

Expected: FAIL because the input and builder classes do not exist.

- [ ] **Step 3: Create the readonly input**

Create a final readonly class with explicit nullable identity values:

```php
final readonly class IngredientClassificationPromptInput
{
    public function __construct(
        public ?string $name,
        public ?string $inciName,
        public ?string $casNumber,
        public ?string $ecNumber,
        public ?string $supplierNotes,
        public string $responseLocale,
    ) {}
}
```

- [ ] **Step 4: Create the builder and move the complete prompt contract**

The builder must:

```php
public function build(IngredientClassificationPromptInput $input): string
```

Inside `build()`:

- construct taxonomy from `IngredientCategory::cases()` and compatible subcategories
- query active `IngredientFunction` rows in stable sort order
- serialize identity with `JSON_PRETTY_PRINT`, Unicode, slash, and exception flags
- resolve a human-readable language from `SupportedLocaleCatalog`, falling back to the normalized locale code
- include the approved clarification gate and readable response headings
- state that only exact official COSING assignments belong under verified functions
- move non-COSING practical roles into overview or professional notes without a backing key
- state that catalogue placement and Annex V authorization are independent
- enforce direct official URLs, secondary-source labelling, verified product composition, source-specific usage levels, and no inferred natural origin

The `Functions` response section becomes:

```text
Functions
- Verified COSING functions: list only functions officially assigned to this exact ingredient, each with its exact backing key and the directly accessed official European Commission COSING source URL; otherwise write Not verified
- Practical formulation roles: describe useful non-COSING roles in plain text only, or None. Do not return function backing keys here.
```

- [ ] **Step 5: Run the builder test and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php
```

Expected: all builder tests pass.

- [ ] **Step 6: Check the scoped diff**

Run:

```bash
git diff --check -- app/Data/IngredientClassificationPromptInput.php app/Services/IngredientClassificationPromptBuilder.php tests/Feature/IngredientClassificationPromptBuilderTest.php
```

Expected: no output.

### Task 2: Delegate the Customer Dashboard to the Shared Builder

**Files:**
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`

- [ ] **Step 1: Rewrite the customer tests around mapping rather than prompt ownership**

Remove the direct call to `IngredientEditor::classificationPrompt()`. Keep the generation test and extend its generated-prompt predicate to require the current locale instruction. Preserve the blank name/INCI notification test.

- [ ] **Step 2: Run the customer prompt tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php --filter='classification prompt'
```

Expected: FAIL because the component still owns the previous prompt and vague locale instruction.

- [ ] **Step 3: Inject and call the builder from the Livewire action**

Change the action signature to:

```php
public function generateClassificationPrompt(IngredientClassificationPromptBuilder $builder): void
```

After the existing blank guard, map `data.name`, `data.inci_name`, `data.cas_number`, `data.ec_number`, and `data.notes` into `IngredientClassificationPromptInput`, pass `app()->getLocale()`, and assign the builder result to `generatedClassificationPrompt`.

Remove the former `classificationPrompt()` and `prettyJson()` methods so prompt wording has one owner.

- [ ] **Step 4: Run shared-builder and customer tests**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/UserIngredientAuthoringTest.php --filter='prompt'
```

Expected: all selected tests pass.

### Task 3: Clarify the Preservation Subcategory Without Changing Its Backing Value

**Files:**
- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/IngredientTaxonomyLocalizationTest.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Write failing localization assertions**

Assert that `IngredientSubcategory::Preservatives->value` remains `preservatives`, while its English label is `Preservatives & preservation boosters`. Assert the authoritative catalogue owns non-empty German, Spanish, French, Italian, and Dutch translations for `subcategories.preservatives.label`.

- [ ] **Step 2: Run localization tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: FAIL on the old `Preservatives` label.

- [ ] **Step 3: Update the label and description translations**

Keep the backing value unchanged. Update English to:

```php
'preservatives' => [
    'label' => 'Preservatives & preservation boosters',
    'description' => 'Ingredients used as permitted preservatives or to support a formula’s preservation system; catalogue placement does not establish regulatory authorization.',
],
```

Update the corresponding catalogue entries for every supported locale, keeping catalogue key order and locale order intact.

- [ ] **Step 4: Validate catalogue structure and tests**

Run:

```bash
php artisan test --compact tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: all selected tests pass and the backing value remains unchanged.

### Task 4: Add the Manual Helper to Admin Create and Edit

**Files:**
- Create: `app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientClassificationPrompt.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/CreateIngredient.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/EditIngredient.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Create: `resources/views/filament/resources/ingredients/classification-prompt.blade.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`

- [ ] **Step 1: Write failing Create/Edit feature tests**

For Create, set unsaved nested state and call the page method:

```php
Livewire::test(CreateIngredient::class)
    ->set('data.current_version.display_name', 'Sodium levulinate')
    ->set('data.current_version.inci_name', 'SODIUM LEVULINATE')
    ->set('data.current_version.cas_number', '19856-23-6')
    ->call('generateIngredientClassificationPrompt')
    ->assertSet('generatedIngredientClassificationPrompt', fn (?string $prompt): bool =>
        str_contains((string) $prompt, 'Sodium levulinate')
        && str_contains((string) $prompt, '19856-23-6')
        && str_contains((string) $prompt, 'Answer in: English (en).'));
```

Add the equivalent Edit test after replacing the hydrated identity with unsaved values. Add a blank identity test that asserts a danger Filament notification and a null preview. Assert the helper state is absent from the persisted ingredient after normal create/save.

- [ ] **Step 2: Run the new admin tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter='classification prompt'
```

Expected: FAIL because the shared concern, method, property, and view do not exist.

- [ ] **Step 3: Create the focused admin concern**

The concern exposes:

```php
public ?string $generatedIngredientClassificationPrompt = null;

public function generateIngredientClassificationPrompt(
    IngredientClassificationPromptBuilder $builder,
): void
```

It reads `data.current_version.display_name`, `inci_name`, `cas_number`, and `ec_number`. When both name and INCI are blank, send a translated danger `Notification` and return. Otherwise build with `supplierNotes: null` and `responseLocale: app()->getLocale()`.

Use the concern in both `CreateIngredient` and `EditIngredient`.

- [ ] **Step 4: Insert the schema-local view after Material Identity**

Add:

```php
View::make('filament.resources.ingredients.classification-prompt')
    ->columnSpanFull(),
```

immediately after the Material Identity section in the shared `IngredientForm`. The view renders a compact translated heading and description, a `wire:click="generateIngredientClassificationPrompt"` button, and only reveals the readonly preview and direct-click Copy button once the public prompt property is non-null.

The view uses `x-data="classificationPrompt()"`, a textarea `x-ref`, and the same `copy()` state contract as the dashboard. No LLM response importer or automatic form mutation is added.

- [ ] **Step 5: Add synchronized helper translations**

Reuse `ingredients.editor.classification_prompt.*` for identical button, preview, copied, failure, and identity-required text. Add only admin-specific heading/description keys under `ingredients.editor.admin.classification_prompt.*`, with all supported catalogue translations.

- [ ] **Step 6: Run the admin tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter='classification prompt'
```

Expected: Create, Edit, blank guard, and persistence-isolation tests pass.

### Task 5: Load the Existing Clipboard Component in Filament

**Files:**
- Create: `resources/js/filament/admin/classification-prompt.js`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `vite.config.js`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Extend the existing JavaScript contract test**

Assert the admin entry point imports `createClassificationPrompt`, assigns it to `window.classificationPrompt`, and therefore reuses the tested `copyText()` secure and `document.execCommand('copy')` fallback paths.

- [ ] **Step 2: Run the JavaScript contract test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php --filter='clipboard'
```

Expected: FAIL because the admin entry point does not exist.

- [ ] **Step 3: Add the small admin entry point and register it**

Create:

```js
import { createClassificationPrompt } from '../../classification-prompt';

window.classificationPrompt = createClassificationPrompt;
```

Add the file to the Vite input list. Register its Vite-built module through `AdminPanelProvider::assets()` using a unique `Js` asset ID. Do not load the complete customer `app.js` bundle in Filament.

- [ ] **Step 4: Build assets and rerun the clipboard contract**

Run:

```bash
npm run build
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php --filter='clipboard'
```

Expected: Vite builds the admin module and all clipboard contract tests pass.

### Task 6: Verification and Repository Checks

**Files:**
- Verify all files changed by Tasks 1–5.

- [ ] **Step 1: Run focused feature suites**

Run:

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: all focused tests pass.

- [ ] **Step 2: Run Filament and PHP formatting checks**

Run:

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
```

Expected: Filacheck reports no unresolved deprecated Filament usage and Pint passes.

- [ ] **Step 3: Validate the real translation catalogue and diff**

Run the existing catalogue test, then:

```bash
git diff --check
git diff -- app/Data/IngredientClassificationPromptInput.php app/Services/IngredientClassificationPromptBuilder.php app/Livewire/Dashboard/IngredientEditor.php app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientClassificationPrompt.php app/Filament/Resources/Ingredients/Pages/CreateIngredient.php app/Filament/Resources/Ingredients/Pages/EditIngredient.php app/Filament/Resources/Ingredients/Schemas/IngredientForm.php app/Providers/Filament/AdminPanelProvider.php resources/views/filament/resources/ingredients/classification-prompt.blade.php resources/js/filament/admin/classification-prompt.js vite.config.js lang/en/ingredients.php database/seeders/data/interface-translations.json tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: no whitespace errors; review confirms only intended hunks inside already-dirty files.

- [ ] **Step 4: Refresh the knowledge graph**

Run:

```bash
graphify update .
```

Expected: graph refresh completes successfully.

- [ ] **Step 5: Report without staging implementation files**

Summarize focused test counts, frontend build, Filacheck, Pint, translation validation, and any unrelated pre-existing full-suite failure. Leave implementation changes unstaged unless the user explicitly requests a combined branch commit.
