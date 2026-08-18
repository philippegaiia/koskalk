# Workbench Composition and Labeling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the Product/Ingredient identity decision to early Formula settings, make Composition & labeling a formula-derived results tab, and remove production-only controls from the general Workbench without changing persistence or Production Bench snapshots.

**Architecture:** Keep `production_output_type` and `output_ingredient_id` as the existing recipe-level contract and keep all current production-run snapshot behavior. Replace the production-oriented output card with a small authenticated-only Formula settings partial, expose the current selection in the collapsed Formula settings summary, and leave legacy reference, nominal-content, and readiness fields in the backend payload so existing stored values round-trip without a schema migration.

**Tech Stack:** PHP 8.5, Laravel 13.25, Blade, Alpine.js, Vite 8, Tailwind CSS 4, Pest 4.7, spatie/laravel-translation-loader 2.8.

---

## File map

- Create `resources/views/livewire/dashboard/partials/recipe-workbench/formula-output-type.blade.php` as the single authenticated Workbench surface for choosing Product or Ingredient and selecting or creating the produced ingredient.
- Delete `resources/views/livewire/dashboard/partials/recipe-workbench/production-output-settings.blade.php`; its readiness, reference, nominal-content, and format-duplication controls must not move elsewhere in this change.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php` to include the new identity partial only for persisted/authenticated products.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php` so the tab contains only formula-derived composition and labeling sections.
- Modify `resources/js/recipe-workbench/sections/formula-section.js` to show the current Product/Ingredient identity in the collapsed settings summary for authenticated products.
- Modify `resources/js/recipe-workbench/component.js` so successful inline creation uses the concise Workbench translation rather than the production-oriented server message.
- Modify `lang/en/workbench.php` as the English source of truth for the new tab and identity wording.
- Modify `database/seeders/data/interface-translations.json` with the equivalent copy for all six supported non-English locales.
- Modify focused Workbench tests only; do not modify models, migrations, output enums, recipe-version mapping, Production Bench services, or production snapshot tests.

### Task 1: Re-home and simplify Product/Ingredient identity

**Files:**
- Create: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-output-type.blade.php`
- Delete: `resources/views/livewire/dashboard/partials/recipe-workbench/production-output-settings.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php:1-45`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php:1-10`
- Modify: `resources/js/recipe-workbench/sections/formula-section.js:98-162`
- Modify: `resources/js/recipe-workbench/component.js:356-394`
- Modify: `lang/en/workbench.php:26-33,101-139`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php:61-83`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php:1231-1279`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php:46-101`
- Test: `tests/Feature/SoapWorkbenchLocalizationTest.php:9-51`

- [ ] **Step 1: Replace the placement tests with the approved Workbench boundary**

In `tests/Feature/RecipeWorkbenchPersistenceTest.php`, replace the two tests beginning with `exposes the production output controls` and `places production output settings` with:

```php
it('exposes product or ingredient identity at the start of formula settings', function (): void {
    $user = User::factory()->create();
    ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    app(CreateManufacturedIngredient::class)->handle($user, 'Turmeric oil macerate');

    $this->actingAs($user)
        ->get(route('recipes.create', ['family' => 'soap']))
        ->assertSuccessful()
        ->assertSee('This formula produces')
        ->assertSee('Product')
        ->assertSee('Ingredient')
        ->assertSee('Turmeric oil macerate');
});

it('keeps product identity in formula settings and composition output in its own tab', function (): void {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $formulaOutputType = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-output-type.blade.php'));
    $outputTab = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php'));
    $publicFormulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings', [
        'isPublicCalculator' => true,
    ])->render();

    expect($formulaSettings)
        ->toContain('recipe-workbench.formula-output-type')
        ->and($formulaOutputType)
        ->toContain('data-formula-output-type')
        ->toContain('role="radiogroup"')
        ->toContain(':aria-checked="productionOutputType === \'finished_product\'"')
        ->toContain(':aria-checked="productionOutputType === \'manufactured_ingredient\'"')
        ->not->toContain('readyDelayDays')
        ->not->toContain('productReference')
        ->not->toContain('nominalContentValue')
        ->not->toContain('duplicateFormula()')
        ->and($outputTab)
        ->not->toContain('production-output-settings')
        ->not->toContain('formula-output-type')
        ->and($publicFormulaSettings)
        ->not->toContain('data-formula-output-type');
});
```

- [ ] **Step 2: Update navigation terminology assertions and summary expectations**

In `tests/Feature/CosmeticRecipeWorkbenchTest.php`, replace both `->assertSee('Product output')` assertions with:

```php
->assertSee('Composition &amp; labeling', false)
```

In `tests/Feature/SoapWorkbenchLocalizationTest.php`, replace the navigation assertion:

```php
->toContain('Product output')
```

with:

```php
->toContain('Composition &amp; labeling')
```

In the `collapses formula settings into a setup summary` test in `tests/Feature/RecipeWorkbenchDesignPolishTest.php`, extend the `$formulaSectionSource` assertions with:

```php
->toContain('if (this.canPersist)')
->toContain("label: this.t('settings.production_output')")
->toContain("this.t('settings.manufactured_ingredient')")
->toContain("this.t('settings.finished_product')")
```

and extend both `$soapSettings` and `$cosmeticSettings` assertions with:

```php
->toContain('data-formula-output-type')
```

- [ ] **Step 3: Run the focused tests and verify the old UI fails the new contract**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
```

Expected: FAIL because `formula-output-type.blade.php` does not exist, the navigation still says `Product output`, and output settings still live in the output tab.

- [ ] **Step 4: Create the focused Formula settings identity partial**

Create `resources/views/livewire/dashboard/partials/recipe-workbench/formula-output-type.blade.php` with exactly this markup:

```blade
<div class="sk-inset sk-tone-info mb-4 p-4" data-formula-output-type aria-labelledby="setting-formula-output-type">
    <p id="setting-formula-output-type" class="sk-eyebrow">{{ __('workbench.settings.production_output') }}</p>

    <div role="radiogroup" aria-labelledby="setting-formula-output-type" class="mt-3 flex flex-wrap gap-2">
        <button
            type="button"
            role="radio"
            :aria-checked="productionOutputType === 'finished_product'"
            @click="productionOutputType = 'finished_product'; outputIngredientId = ''"
            :class="productionOutputType === 'finished_product' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
            class="rounded-full px-4 py-2.5 text-xs font-medium transition"
        >
            {{ __('workbench.settings.finished_product') }}
        </button>
        <button
            type="button"
            role="radio"
            :aria-checked="productionOutputType === 'manufactured_ingredient'"
            @click="productionOutputType = 'manufactured_ingredient'"
            :class="productionOutputType === 'manufactured_ingredient' ? 'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm' : 'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'"
            class="rounded-full px-4 py-2.5 text-xs font-medium transition"
        >
            {{ __('workbench.settings.manufactured_ingredient') }}
        </button>
    </div>

    <div x-show="productionOutputType === 'manufactured_ingredient'" x-cloak class="mt-4 grid gap-4 lg:grid-cols-2">
        <div>
            <label for="formula-output-ingredient" class="sk-eyebrow">{{ __('workbench.settings.choose_manufactured_ingredient') }}</label>
            <select id="formula-output-ingredient" x-model="outputIngredientId" class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] transition">
                <option value="">{{ __('workbench.common.choose_later') }}</option>
                <template x-for="ingredient in manufacturedIngredients" :key="ingredient.id">
                    <option :value="String(ingredient.id)" x-text="ingredient.name"></option>
                </template>
            </select>
        </div>

        <div>
            <label for="new-formula-output-ingredient-name" class="sk-eyebrow">{{ __('workbench.settings.new_manufactured_ingredient_name') }}</label>
            <div class="mt-3 flex gap-2">
                <input id="new-formula-output-ingredient-name" x-model="manufacturedIngredientName" @keydown.enter.prevent="createManufacturedIngredient()" type="text" maxlength="255" class="min-w-0 flex-1 rounded-lg bg-[var(--color-field)] px-4 py-2.5 text-sm text-[var(--color-ink-strong)] transition" />
                <button type="button" @click="createManufacturedIngredient()" :disabled="manufacturedIngredientStatus === 'saving'" class="sk-btn shrink-0 bg-[var(--color-active)] text-[var(--color-on-active)] hover:opacity-90 disabled:cursor-wait disabled:opacity-60">
                    {{ __('workbench.settings.create_manufactured_ingredient') }}
                </button>
            </div>
            <p
                x-show="manufacturedIngredientMessage"
                x-cloak
                role="status"
                aria-live="polite"
                class="mt-2 text-xs leading-5"
                :class="manufacturedIngredientStatus === 'error' ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-soft)]'"
                x-text="manufacturedIngredientMessage"
            ></p>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Include identity early and remove it from Composition & labeling**

At the top of `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`, extend the existing PHP block to define the calculator context:

```php
$isPublicCalculator = $isPublicCalculator ?? false;
```

Immediately after the opening `formula-settings-panel` div, add:

```blade
@unless ($isPublicCalculator)
    @include('livewire.dashboard.partials.recipe-workbench.formula-output-type')
@endunless
```

In `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php`, remove:

```blade
@unless ($isPublicCalculator)
    @include('livewire.dashboard.partials.recipe-workbench.production-output-settings')
@endunless
```

Delete `resources/views/livewire/dashboard/partials/recipe-workbench/production-output-settings.blade.php`. Do not copy its readiness delay, reference, nominal content, or duplicate-format sections into another view.

- [ ] **Step 6: Add the authenticated identity to the collapsed Formula settings summary**

In `resources/js/recipe-workbench/sections/formula-section.js`, add this block after the initial `cards` array and before the soap/cosmetic branch:

```js
if (this.canPersist) {
    cards.unshift({
        id: 'formula-output-type',
        label: this.t('settings.production_output'),
        value: this.productionOutputType === 'manufactured_ingredient'
            ? this.t('settings.manufactured_ingredient')
            : this.t('settings.finished_product'),
        tone: 'info',
    });
}
```

In `resources/js/recipe-workbench/component.js`, replace the successful inline-creation message assignment:

```js
this.manufacturedIngredientMessage = response.message ?? this.t('settings.manufactured_ingredient_created');
```

with:

```js
this.manufacturedIngredientMessage = this.t('settings.manufactured_ingredient_created');
```

This prevents the production-oriented server message from leaking into the simplified Workbench without changing the shared creation action.

- [ ] **Step 7: Change the English source copy without changing internal keys**

In `lang/en/workbench.php`, replace these values:

```php
'output' => 'Composition & labeling',
```

and:

```php
'production_output' => 'This formula produces',
'finished_product' => 'Product',
'manufactured_ingredient' => 'Ingredient',
'choose_manufactured_ingredient' => 'Ingredient produced',
'new_manufactured_ingredient_name' => 'Create an ingredient',
'manufactured_ingredient_name_required' => 'Enter an ingredient name.',
'manufactured_ingredient_created' => 'Ingredient created.',
'manufactured_ingredient_create_failed' => 'The ingredient could not be created.',
```

Keep `production_output_help`, `finished_product_help`, `manufactured_ingredient_help`, readiness, product-reference, nominal-content, and duplication keys in place because backend or historical translation contracts may still reference them. They are no longer rendered by the Workbench partial.

- [ ] **Step 8: Format and run the focused tests**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
```

Expected: all focused tests PASS.

- [ ] **Step 9: Commit the English UI boundary**

```bash
git add -A resources/views/livewire/dashboard/partials/recipe-workbench resources/js/recipe-workbench lang/en/workbench.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
git commit -m "feat: separate workbench identity from labeling output"
```

### Task 2: Localize Composition & labeling and Product/Ingredient copy

**Files:**
- Modify: `database/seeders/data/interface-translations.json`
- Test: `tests/Feature/SoapWorkbenchLocalizationTest.php`

- [ ] **Step 1: Add a failing catalogue test for every visible identity key**

Append this test to `tests/Feature/SoapWorkbenchLocalizationTest.php`:

```php
it('commits composition and product identity copy for every supported locale', function (): void {
    $catalogue = File::json(database_path('seeders/data/interface-translations.json'));
    $rows = collect($catalogue['translations'])
        ->where('group', 'workbench')
        ->whereIn('key', [
            'tabs.output',
            'settings.production_output',
            'settings.finished_product',
            'settings.manufactured_ingredient',
            'settings.choose_manufactured_ingredient',
            'settings.new_manufactured_ingredient_name',
            'settings.manufactured_ingredient_name_required',
            'settings.manufactured_ingredient_created',
            'settings.manufactured_ingredient_create_failed',
        ])
        ->keyBy('key');
    $expectedFrench = [
        'tabs.output' => 'Composition et étiquetage',
        'settings.production_output' => 'Cette formule produit',
        'settings.finished_product' => 'Produit',
        'settings.manufactured_ingredient' => 'Ingrédient',
        'settings.choose_manufactured_ingredient' => 'Ingrédient produit',
        'settings.new_manufactured_ingredient_name' => 'Créer un ingrédient',
        'settings.manufactured_ingredient_name_required' => 'Saisissez un nom d’ingrédient.',
        'settings.manufactured_ingredient_created' => 'Ingrédient créé.',
        'settings.manufactured_ingredient_create_failed' => 'L’ingrédient n’a pas pu être créé.',
    ];

    foreach ($expectedFrench as $key => $translation) {
        expect($rows)->toHaveKey($key)
            ->and(array_keys($rows[$key]['text']))->toBe(['de', 'es', 'fr', 'it', 'nl', 'pt_BR'])
            ->and($rows[$key]['text']['fr'])->toBe($translation);
    }
});
```

- [ ] **Step 2: Run the localization test and verify it fails on the previous copy**

Run:

```bash
php artisan test --compact tests/Feature/SoapWorkbenchLocalizationTest.php --filter="commits composition and product identity copy"
```

Expected: FAIL because `tabs.output` still contains `Sortie produit` and the identity keys still contain production-oriented French copy.

- [ ] **Step 3: Replace the nine catalogue entries for all supported locales**

In `database/seeders/data/interface-translations.json`, keep each existing `group` and `key` and replace its `text` object with the matching object below.

For `tabs.output`:

```json
{
  "de": "Zusammensetzung & Kennzeichnung",
  "es": "Composición y etiquetado",
  "fr": "Composition et étiquetage",
  "it": "Composizione ed etichettatura",
  "nl": "Samenstelling en etikettering",
  "pt_BR": "Composição e rotulagem"
}
```

For `settings.production_output`:

```json
{
  "de": "Diese Rezeptur erzeugt",
  "es": "Esta fórmula produce",
  "fr": "Cette formule produit",
  "it": "Questa formula produce",
  "nl": "Deze formule produceert",
  "pt_BR": "Esta fórmula produz"
}
```

For `settings.finished_product`:

```json
{
  "de": "Produkt",
  "es": "Producto",
  "fr": "Produit",
  "it": "Prodotto",
  "nl": "Product",
  "pt_BR": "Produto"
}
```

For `settings.manufactured_ingredient`:

```json
{
  "de": "Ingrediens",
  "es": "Ingrediente",
  "fr": "Ingrédient",
  "it": "Ingrediente",
  "nl": "Ingrediënt",
  "pt_BR": "Ingrediente"
}
```

For `settings.choose_manufactured_ingredient`:

```json
{
  "de": "Erzeugtes Ingrediens",
  "es": "Ingrediente producido",
  "fr": "Ingrédient produit",
  "it": "Ingrediente prodotto",
  "nl": "Geproduceerd ingrediënt",
  "pt_BR": "Ingrediente produzido"
}
```

For `settings.new_manufactured_ingredient_name`:

```json
{
  "de": "Ingrediens erstellen",
  "es": "Crear un ingrediente",
  "fr": "Créer un ingrédient",
  "it": "Crea un ingrediente",
  "nl": "Ingrediënt aanmaken",
  "pt_BR": "Criar um ingrediente"
}
```

For `settings.manufactured_ingredient_name_required`:

```json
{
  "de": "Geben Sie einen Ingrediensnamen ein.",
  "es": "Introduce un nombre de ingrediente.",
  "fr": "Saisissez un nom d’ingrédient.",
  "it": "Inserisci un nome per l’ingrediente.",
  "nl": "Voer een ingrediëntnaam in.",
  "pt_BR": "Informe um nome de ingrediente."
}
```

For `settings.manufactured_ingredient_created`:

```json
{
  "de": "Ingrediens erstellt.",
  "es": "Ingrediente creado.",
  "fr": "Ingrédient créé.",
  "it": "Ingrediente creato.",
  "nl": "Ingrediënt aangemaakt.",
  "pt_BR": "Ingrediente criado."
}
```

For `settings.manufactured_ingredient_create_failed`:

```json
{
  "de": "Das Ingrediens konnte nicht erstellt werden.",
  "es": "No se pudo crear el ingrediente.",
  "fr": "L’ingrédient n’a pas pu être créé.",
  "it": "Non è stato possibile creare l’ingrediente.",
  "nl": "Het ingrediënt kon niet worden aangemaakt.",
  "pt_BR": "Não foi possível criar o ingrediente."
}
```

- [ ] **Step 4: Validate JSON and run localization coverage**

Run:

```bash
php -r "json_decode(file_get_contents('database/seeders/data/interface-translations.json'), true, 512, JSON_THROW_ON_ERROR);"
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: JSON validation exits successfully and both test files PASS.

- [ ] **Step 5: Commit the localized copy**

```bash
git add database/seeders/data/interface-translations.json tests/Feature/SoapWorkbenchLocalizationTest.php
git commit -m "feat: localize composition and product identity copy"
```

### Task 3: Verify persistence and Production Bench snapshot boundaries

**Files:**
- Verify unchanged: `app/Services/RecipeVersionRecordService.php`
- Verify unchanged: `app/Actions/Production/CreateProductionDraft.php`
- Verify unchanged: `app/Services/Production/ProductionReadyDateService.php`
- Verify unchanged: `database/migrations/2026_08_10_231100_add_output_configuration_to_recipes_runs_and_lots.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/ProductionPlanningTest.php`
- Test: `tests/Feature/ProductionExecutionTest.php`

- [ ] **Step 1: Confirm the implementation did not broaden into persistence or schema changes**

Run:

```bash
git diff HEAD~2 -- app/Services/RecipeVersionRecordService.php app/Actions/Production/CreateProductionDraft.php app/Services/Production/ProductionReadyDateService.php database/migrations
```

Expected: no output. Product-level persistence and production-run snapshot creation remain unchanged.

- [ ] **Step 2: Run the existing legacy metadata and output-identity round-trip tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php --filter="/(persists manufactured ingredient output configuration|persists optional finished product reference and nominal content|allows finished product reference and nominal content to be omitted|duplicates a product for another size while clearing its unique reference)/"
```

Expected: PASS. Hidden reference, nominal-content, and readiness state still round-trips through the existing payload, and Product/Ingredient identity still persists.

- [ ] **Step 3: Run the existing production snapshot tests unchanged**

Run:

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php --filter="snapshots configured output identity and ready dates when planning"
php artisan test --compact tests/Feature/ProductionExecutionTest.php --filter="completes configured manufactured output from the production snapshot"
```

Expected: PASS. Existing runs retain their frozen output identity and delay after the product configuration changes.

- [ ] **Step 4: Build frontend assets and run the complete focused regression set**

Run:

```bash
npm run build
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionExecutionTest.php
```

Expected: Vite build succeeds and all focused tests PASS.

- [ ] **Step 5: Run final repository checks**

Run:

```bash
vendor/bin/pint --dirty --format agent
graphify update .
git diff --check
git status --short
```

Expected: Pint completes successfully, Graphify refreshes the local graph, `git diff --check` reports no whitespace errors, and `git status --short` shows no uncommitted implementation changes.

## Acceptance checklist

- [ ] Formula settings asks `This formula produces` with Product and Ingredient choices for authenticated products.
- [ ] New authenticated products expose the choice immediately because Formula settings starts open.
- [ ] Saved products show the identity in the collapsed Formula settings summary.
- [ ] The public calculator does not show product identity controls or summary metadata.
- [ ] Ingredient selection and inline ingredient creation remain functional.
- [ ] The tab is named Composition & labeling in English and Composition et étiquetage in French.
- [ ] The renamed tab contains no product reference, nominal content, readiness delay, output identity selector, or format-duplication action.
- [ ] Packaging remains a separate unchanged tab.
- [ ] Existing recipe-level fields and production-run snapshots remain unchanged.
- [ ] Existing production runs remain frozen while future runs use the product’s current Product/Ingredient configuration.
