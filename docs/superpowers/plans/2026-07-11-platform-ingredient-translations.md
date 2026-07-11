# Platform Ingredient Translations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add editable non-English names and guidance for platform ingredients with English fallback across the catalog and recipe workbench.

**Architecture:** Preserve canonical English fields on `ingredients` and store non-English values in a constrained `ingredient_translations` relation. An `IngredientTranslationService` owns admin form hydration, validation, normalization, and synchronization; `Ingredient` owns runtime resolution. User-facing catalog builders eager-load the current locale and expose localized values without changing private ingredients or historical snapshots.

**Tech Stack:** Laravel 13, Eloquent, PostgreSQL/SQLite, Filament 5, Livewire 4, Pest 4.

---

### Task 1: Translation persistence

**Files:**
- Create: `database/migrations/*_create_ingredient_translations_table.php`
- Create: `app/Models/IngredientTranslation.php`
- Create: `database/factories/IngredientTranslationFactory.php`
- Create: `tests/Feature/IngredientTranslationTest.php`
- Modify: `app/Models/Ingredient.php`

- [x] **Step 1: Generate the model, migration, factory, and Pest feature test**

Run:

```shell
php artisan make:model IngredientTranslation --migration --factory --no-interaction
php artisan make:test --pest IngredientTranslationTest --no-interaction
```

- [x] **Step 2: Write failing persistence tests**

Cover the table columns, ingredient cascade delete, locale foreign key, and unique `ingredient_id + locale` constraint. Add a relationship assertion through `$ingredient->translations`.

- [x] **Step 3: Run the tests and verify RED**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php
```

Expected: failure because the migration and model relationships are incomplete.

- [x] **Step 4: Implement persistence**

The migration must create:

```php
Schema::create('ingredient_translations', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->string('locale', 16);
    $table->string('display_name')->nullable();
    $table->text('info_markdown')->nullable();
    $table->timestamps();

    $table->unique(['ingredient_id', 'locale']);
    $table->index(['locale', 'display_name']);
    $table->foreign('locale')
        ->references('code')
        ->on('supported_locales')
        ->cascadeOnUpdate()
        ->restrictOnDelete();
});
```

`IngredientTranslation` must use `HasFactory`, define fillable attributes, and return a typed `BelongsTo` relationship. `Ingredient` must expose a typed `HasMany translations()` relationship.

- [x] **Step 5: Run the tests and verify GREEN**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php
```

### Task 2: Translation synchronization and runtime fallback

**Files:**
- Create: `app/Services/IngredientTranslationService.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `tests/Feature/IngredientTranslationTest.php`

- [x] **Step 1: Write failing behavior tests**

Test these cases independently:

```php
expect($ingredient->localizedDisplayName('fr'))->toBe('Huile d’olive');
expect($ingredient->localizedDisplayName('de'))->toBe('Olive Oil');
expect($privateIngredient->localizedDisplayName('fr'))->toBe('Mon huile');
```

Also test empty translation fallback, localized guidance, exact locale plus base-language fallback, service normalization of empty strings, rejection of `en`, rejection of unknown locales, duplicate locale rows, and rejection for private ingredients.

- [x] **Step 2: Run the behavior tests and verify RED**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php
```

- [x] **Step 3: Implement explicit model resolution**

Add methods with nullable string return types:

```php
public function localizedDisplayName(?string $locale = null): ?string;
public function localizedInfoMarkdown(?string $locale = null): ?string;
```

Resolution must return canonical fields for English and private ingredients, prefer the exact requested locale, optionally try its base language, and then return the canonical English value. It must use an already-loaded `translations` relation when available and otherwise perform one constrained query.

- [x] **Step 4: Implement the synchronization service**

`IngredientTranslationService` must provide:

```php
/** @return array<int, array{locale: string, display_name: ?string, info_markdown: ?string}> */
public function formData(Ingredient $ingredient): array;

/** @param array<int, array<string, mixed>> $rows */
public function sync(Ingredient $ingredient, array $rows): void;
```

Validate registered non-English locales, distinct rows, name length, platform ownership, and at least one translated field. Normalize blank fields to `null`, delete omitted rows, and update-or-create submitted rows inside a transaction.

- [x] **Step 5: Run the behavior tests and verify GREEN**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php
```

### Task 3: Native Filament ingredient editor

**Files:**
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientDataEntry.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/EditIngredient.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`

- [x] **Step 1: Write failing Filament tests**

Assert that a platform ingredient edit page renders a `Translations` section and registered non-English locale options. Use Livewire to fill and save translation rows, then assert the related database records. Assert that a private ingredient does not render the section and cannot persist injected translation state.

- [x] **Step 2: Run the focused Filament tests and verify RED**

```shell
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter=translation
```

- [x] **Step 3: Add the translation form**

Add a native `Repeater::make('translations')` section visible only for platform ingredients. It must use registered non-English locales, `distinct()`, `disableOptionsWhenSelectedInSiblingRepeaterItems()`, a translated display-name input, guidance markdown editor, no reordering, and zero default rows. Show the canonical English name and guidance as reference text.

- [x] **Step 4: Connect the existing save pipeline**

Extend `InteractsWithIngredientDataEntry` to extract `translations`, remove it from direct ingredient attributes, and call `IngredientTranslationService::sync()` after the normal ingredient data sync. Extend edit-page hydration with `IngredientTranslationService::formData()`.

- [x] **Step 5: Run the Filament tests and verify GREEN**

```shell
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter=translation
vendor/bin/filacheck --fix
```

### Task 4: Localized user-facing catalog delivery

**Files:**
- Modify: `app/Services/RecipeWorkbenchIngredientCatalogBuilder.php`
- Modify: `app/Http/Controllers/IngredientController.php`
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Modify: `tests/Feature/IngredientTranslationTest.php`
- Modify: relevant existing workbench feature test if needed

- [x] **Step 1: Write failing delivery tests**

Set the application locale to French and assert that:

- workbench ingredient payloads use the French display name;
- private ingredients continue using their authored name;
- platform search matches both French and canonical English names and returns French;
- the dashboard catalog displays the localized platform name;
- missing translations fall back to English;
- the workbench builder does not issue one translation query per ingredient.

- [x] **Step 2: Run delivery tests and verify RED**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php
```

- [x] **Step 3: Localize the workbench catalog**

Constrain eager loading of `translations` to the requested locale candidates and map `name` through `localizedDisplayName()`. Keep INCI and calculation fields canonical.

- [x] **Step 4: Localize search and the ingredient dashboard**

Platform search must query canonical `display_name`, current-locale translation `display_name`, and `inci_name`, eager-load translations, map localized names, and sort the final small result set by localized name. The dashboard table must render a localized state while retaining existing private-ingredient behavior.

- [x] **Step 5: Run delivery tests and verify GREEN**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/PublicCalculatorTest.php
```

### Task 5: Documentation and full verification

**Files:**
- Modify: `docs/developer/localization.md`
- Modify: `docs/developer/catalog-and-admin.md`
- Modify: `docs/developer/current-state.md`

- [x] **Step 1: Update project documentation**

Record the implemented `ingredient_translations` boundary, English fallback, Filament editing location, private-ingredient behavior, and separation from `language_lines` and regulatory nomenclature.

- [x] **Step 2: Run migration and formatters**

```shell
php artisan migrate --no-interaction
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
```

- [x] **Step 3: Run affected tests**

```shell
php artisan test --compact tests/Feature/IngredientTranslationTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/PublicCalculatorTest.php tests/Feature/InterfaceTranslationFoundationTest.php
```

- [x] **Step 4: Build frontend assets and check the diff**

```shell
npm run build
git diff --check
```

- [x] **Step 5: Refresh the project graph**

```shell
graphify update .
```
