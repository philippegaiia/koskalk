# Upload Original Filenames Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep random, safe object names for every persisted Filament image upload while retaining and displaying the user's original filename after reload.

**Architecture:** Add nullable display-name columns beside the existing path columns, sanitize them through one reusable Eloquent cast, and connect each Filament `FileUpload` with `storeFileNamesIn()`. Existing images without metadata display “Current image”; no migration attempts to reconstruct names from generated storage paths.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, PostgreSQL, Pest 4.

---

## Scope and invariants

This is plan 1 of 3 for the approved Instructions & media redesign in `docs/superpowers/specs/2026-07-22-instructions-media-redesign.md`.

Covered upload controls:

- recipe featured image;
- private ingredient featured image and icon;
- admin ingredient featured image and icon;
- private packaging-item featured image;
- admin product-type fallback image.

The private and admin ingredient controls share the same two `ingredients` columns. The database therefore needs five new columns, not seven. Rich-editor attachments are intentionally excluded because they are inline media references rather than named `FileUpload` records.

Security invariants:

- never call `preserveFilenames()`;
- never use an original filename as an object path;
- strip path components and Unicode control characters;
- retain at most 255 characters;
- render through Blade/Filament escaping only;
- never expose a generated ULID/hash as fallback UI copy.

Before each code task, use Laravel Boost `search-docs` for the API being changed. The relevant Filament 5 API is `storeFileNamesIn()`.

## File map

- Create `database/migrations/2026_07_22_120000_add_original_image_names_to_upload_models.php`.
- Create `app/Casts/OriginalFilename.php`.
- Create `app/Support/FilamentUploadMetadata.php`.
- Modify `app/Models/Recipe.php`, `Ingredient.php`, `UserPackagingItem.php`, and `ProductType.php`.
- Modify `app/Services/RecipeWorkbenchContentFormSchema.php` and `RecipeContentUpdater.php`.
- Modify `app/Livewire/Dashboard/RecipeWorkbench.php`, `IngredientEditor.php`, and `PackagingItemEditor.php`.
- Modify `app/Services/UserIngredientAuthoringService.php` and `UserPackagingItemAuthoringService.php`.
- Modify `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`.
- Modify `app/Filament/Resources/ProductTypes/Schemas/ProductTypeForm.php`.
- Create `lang/en/media.php` and add `media` to `config/interface-translations.php`.
- Create `tests/Unit/OriginalFilenameTest.php`.
- Modify `tests/Feature/RecipeWorkbenchPersistenceTest.php`, `PrivateMediaPreviewTest.php`, `UserIngredientAuthoringTest.php`, `UserIngredientAuthoringServiceDuplicationTest.php`, `PackagingItemsIndexTest.php`, `Filament/CatalogResourcesTest.php`, and `ProductTypeFoundationTest.php`.

### Task 1: Add nullable filename metadata safely

**Files:**
- Create: `database/migrations/2026_07_22_120000_add_original_image_names_to_upload_models.php`
- Test: `tests/Feature/UploadOriginalFilenameMigrationTest.php`

- [ ] **Step 1: Generate the migration and Pest test**

Run:

```bash
php artisan make:migration add_original_image_names_to_upload_models --no-interaction
php artisan make:test --pest UploadOriginalFilenameMigrationTest --no-interaction
```

Rename the migration to the timestamp shown in the file map if Artisan creates a different timestamp.

- [ ] **Step 2: Write the failing schema test**

```php
<?php

use Illuminate\Support\Facades\Schema;

it('stores original image names beside persisted upload paths', function () {
    expect(Schema::hasColumns('recipes', ['featured_image_original_name']))->toBeTrue()
        ->and(Schema::hasColumns('ingredients', [
            'featured_image_original_name',
            'icon_image_original_name',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('user_packaging_items', ['featured_image_original_name']))->toBeTrue()
        ->and(Schema::hasColumns('product_types', ['fallback_image_original_name']))->toBeTrue();
});
```

- [ ] **Step 3: Run the test and confirm it fails**

Run:

```bash
php artisan test --compact tests/Feature/UploadOriginalFilenameMigrationTest.php
```

Expected: failure because the five columns do not exist.

- [ ] **Step 4: Implement a reversible, nullable migration**

Use `Schema::table()` four times. In `up()` add:

```php
$table->string('featured_image_original_name')->nullable();
```

to `recipes` and `user_packaging_items`; add both `featured_image_original_name` and `icon_image_original_name` to `ingredients`; add `fallback_image_original_name` to `product_types`. In `down()`, drop exactly those columns. Do not add indexes and do not backfill from existing paths.

- [ ] **Step 5: Run the migration test**

```bash
php artisan test --compact tests/Feature/UploadOriginalFilenameMigrationTest.php
```

Expected: pass.

- [ ] **Step 6: Commit the schema**

```bash
git add database/migrations/2026_07_22_120000_add_original_image_names_to_upload_models.php tests/Feature/UploadOriginalFilenameMigrationTest.php
git commit -m "feat: store original image names"
```

### Task 2: Sanitize original filenames at the model boundary

**Files:**
- Create: `app/Casts/OriginalFilename.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `app/Models/UserPackagingItem.php`
- Modify: `app/Models/ProductType.php`
- Test: `tests/Unit/OriginalFilenameTest.php`

- [ ] **Step 1: Generate the cast and unit test**

```bash
php artisan make:cast OriginalFilename --no-interaction
php artisan make:test --pest --unit OriginalFilenameTest --no-interaction
```

- [ ] **Step 2: Write failing cast tests**

```php
<?php

use App\Casts\OriginalFilename;
use App\Models\Recipe;

it('keeps a harmless original filename', function () {
    $recipe = new Recipe;
    $recipe->featured_image_original_name = 'Sérum visage 01.png';

    expect($recipe->featured_image_original_name)->toBe('Sérum visage 01.png');
});

it('removes path components and control characters', function () {
    $recipe = new Recipe;
    $recipe->featured_image_original_name = "../../private/serum\0\nphoto.png";

    expect($recipe->featured_image_original_name)->toBe('serumphoto.png');
});

it('stores blank names as null and limits names to 255 characters', function () {
    $cast = new OriginalFilename;
    $recipe = new Recipe;

    expect($cast->set($recipe, 'name', '   ', []))->toBeNull()
        ->and(mb_strlen((string) $cast->set($recipe, 'name', str_repeat('é', 300), [])))->toBe(255);
});
```

- [ ] **Step 3: Run the test and confirm it fails**

```bash
php artisan test --compact tests/Unit/OriginalFilenameTest.php
```

Expected: failure because the cast and model mappings are incomplete.

- [ ] **Step 4: Implement `OriginalFilename`**

Implement `CastsAttributes` with explicit `get()` and `set()` return types. `set()` must:

1. return `null` for non-string or blank values;
2. replace backslashes with forward slashes and call `basename()`;
3. remove `\p{C}` Unicode control characters with `preg_replace('/\p{C}+/u', '', $name)`;
4. trim whitespace;
5. return `null` if empty;
6. return `Str::limit($name, 255, '')`.

`get()` returns the stored string or `null`; it must not invent a fallback.

- [ ] **Step 5: Map the cast and fillable attributes on all four models**

Add the new column names to each model's `#[Fillable]` array. Add the cast to each model's existing `casts()` method, or create the method if absent:

```php
'featured_image_original_name' => OriginalFilename::class,
```

Use the equivalent key for ingredient icons and product-type fallbacks. Preserve every existing cast.

- [ ] **Step 6: Run the unit test**

```bash
php artisan test --compact tests/Unit/OriginalFilenameTest.php
```

Expected: pass.

- [ ] **Step 7: Commit the model boundary**

```bash
git add app/Casts/OriginalFilename.php app/Models/Recipe.php app/Models/Ingredient.php app/Models/UserPackagingItem.php app/Models/ProductType.php tests/Unit/OriginalFilenameTest.php
git commit -m "feat: sanitize upload display names"
```

### Task 3: Connect filename state to public authoring services

**Files:**
- Modify: `app/Services/RecipeContentUpdater.php`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `app/Services/UserPackagingItemAuthoringService.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/UserIngredientAuthoringTest.php`
- Test: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
- Test: `tests/Feature/PackagingItemsIndexTest.php`

- [ ] **Step 1: Extend the existing persistence tests**

Add assertions that:

- saving recipe content writes `featured_image_original_name`;
- clearing the featured image clears its original name;
- private ingredient create/update persists both names;
- duplicating an ingredient clears both paths and both names;
- private packaging create/update persists the name and removal clears it.

Use human-readable fixtures such as `Olive oil portrait.jpg`, and assert database values rather than only Livewire state.

- [ ] **Step 2: Run the focused tests and confirm failure**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/PackagingItemsIndexTest.php
```

Expected: new metadata assertions fail.

- [ ] **Step 3: Carry metadata through each state array**

Add the new fields to:

- `RecipeWorkbench::recipeContentFormState()`, pending content state, and PHPDoc shapes;
- `RecipeContentUpdater::update()` fill data and PHPDoc;
- `UserIngredientAuthoringService::blankState()`, `formData()`, `fillIngredient()`, and duplication/reset lists;
- `UserPackagingItemAuthoringService::blankState()`, `formData()`, and `persist()`.

When a submitted path is blank, force its corresponding original-name value to `null`. Do this in the persistence service even though Filament normally clears the companion state; it protects non-UI callers.

- [ ] **Step 4: Run the focused tests**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/PackagingItemsIndexTest.php
```

Expected: pass.

- [ ] **Step 5: Commit public persistence**

```bash
git add app/Services/RecipeContentUpdater.php app/Livewire/Dashboard/RecipeWorkbench.php app/Services/UserIngredientAuthoringService.php app/Services/UserPackagingItemAuthoringService.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/PackagingItemsIndexTest.php
git commit -m "feat: persist upload display names"
```

### Task 4: Display the original name on every Filament upload

**Files:**
- Create: `app/Support/FilamentUploadMetadata.php`
- Modify: `app/Services/RecipeWorkbenchContentFormSchema.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `app/Livewire/Dashboard/PackagingItemEditor.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `app/Filament/Resources/ProductTypes/Schemas/ProductTypeForm.php`
- Create: `lang/en/media.php`
- Modify: `config/interface-translations.php`
- Test: `tests/Feature/PrivateMediaPreviewTest.php`
- Test: `tests/Feature/Filament/CatalogResourcesTest.php`
- Test: `tests/Feature/ProductTypeFoundationTest.php`

- [ ] **Step 1: Add failing component-contract tests**

For every upload control, resolve the schema component and assert:

```php
expect($component->getFileNamesStatePath())->toBe('featured_image_original_name');
```

Use `icon_image_original_name` and `fallback_image_original_name` where appropriate. For an existing record with no metadata, invoke the existing-upload callback and assert the returned `name` is `Current image`, not the basename of its stored path. Add one case proving `Sérum visage.png` survives reload as the returned display name.

- [ ] **Step 2: Run the tests and confirm failure**

```bash
php artisan test --compact tests/Feature/PrivateMediaPreviewTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/ProductTypeFoundationTest.php
```

Expected: filename state paths and safe fallback are absent.

- [ ] **Step 3: Create the metadata helper**

`FilamentUploadMetadata::applyDisplayName()` accepts `?array $metadata`, `string|array|null $storedFileNames`, and `string $fallback`. It returns `null` for null metadata. Otherwise it uses the scalar stored original name when filled and assigns the fallback when absent. It must never derive the name from `$metadata['name']` or the object path.

- [ ] **Step 4: Wire all seven controls**

Add `->storeFileNamesIn('<matching_original_name_column>')` to every control in scope. Preserve the existing random storage callback and authorization-aware URL callback. Where a control has `getUploadedFileUsing()`, pass its metadata through `FilamentUploadMetadata::applyDisplayName()`. Add an equivalent callback to the admin controls so legacy records also use the neutral fallback.

Add `lang/en/media.php`:

```php
<?php

return [
    'current_image' => 'Current image',
];
```

Add `'media' => ['*']` to the application-owned source manifest. Public controls use `__('media.current_image')`; the Filament admin remains English-only through its existing locale policy.

- [ ] **Step 5: Run component tests and Filament compatibility checks**

```bash
php artisan test --compact tests/Feature/PrivateMediaPreviewTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/ProductTypeFoundationTest.php
vendor/bin/filacheck --fix
```

Expected: tests pass and Filacheck reports no unresolved deprecations.

- [ ] **Step 6: Commit the upload UI**

```bash
git add app/Support/FilamentUploadMetadata.php app/Services/RecipeWorkbenchContentFormSchema.php app/Livewire/Dashboard/IngredientEditor.php app/Livewire/Dashboard/PackagingItemEditor.php app/Filament/Resources/Ingredients/Schemas/IngredientForm.php app/Filament/Resources/ProductTypes/Schemas/ProductTypeForm.php lang/en/media.php config/interface-translations.php tests/Feature/PrivateMediaPreviewTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/ProductTypeFoundationTest.php
git commit -m "feat: show original upload names"
```

### Task 5: Sync the English key and verify the full change

**Files:**
- Modify only local `language_lines` content through the existing command/import workflow.

- [ ] **Step 1: Sync the new English-owned key locally**

```bash
php artisan translations:sync
```

Expected: `media.current_image` exists without overwriting any reviewed translation.

Populate only blank database values with the approved contextual translations:

```php
[
    'fr' => 'Image actuelle',
    'es' => 'Imagen actual',
    'de' => 'Aktuelles Bild',
    'it' => 'Immagine attuale',
    'nl' => 'Huidige afbeelding',
]
```

Extend `InterfaceTranslationFoundationTest` or the nearest upload-localization test to load `media.current_image` for all five locales and assert these exact values.

- [ ] **Step 2: Run all affected tests**

```bash
php artisan test --compact tests/Unit/OriginalFilenameTest.php tests/Feature/UploadOriginalFilenameMigrationTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/PrivateMediaPreviewTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/PackagingItemsIndexTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/ProductTypeFoundationTest.php
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
graphify update .
```

Expected: all tests pass, formatting is clean, Filacheck has no unresolved findings, and the graph updates successfully.

- [ ] **Step 3: Perform a rendered smoke test**

For a recipe, private ingredient, private packaging item, admin ingredient, and admin product type:

1. upload an image named `Sérum étroit portrait.png`;
2. save and reload;
3. confirm the original name is visible;
4. confirm the stored object path remains random and ends in `.webp` where conversion applies;
5. remove the image, save, reload, and confirm both path and metadata are null;
6. load one legacy record and confirm it says `Current image` rather than showing its hash.

- [ ] **Step 4: Commit any formatter-only changes**

```bash
git add app database config lang tests
git commit -m "test: verify upload filename metadata"
```

Skip the commit if there are no remaining changes.
