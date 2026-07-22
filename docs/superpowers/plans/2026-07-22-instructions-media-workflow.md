# Instructions and Media Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the oversized Instructions & media form with a clear product-presentation/SOP workflow, non-destructive image handling, contextual interface translations, and reliable manual/autosave protection.

**Architecture:** Keep the existing Livewire workbench and Filament schema, but split the schema into a responsive presentation grid and a full-width manufacturing editor. Extend the existing `MediaStorage` conversion path instead of adding a second uploader. A small Alpine controller reports dirty/save/upload state to the workbench's single navigation guard and performs a deadline-based two-minute safety save.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, Alpine.js, Blade, Tailwind CSS 4, Pest 4, Vite 8.

---

## Preconditions and boundaries

Implement `docs/superpowers/plans/2026-07-22-upload-original-filenames.md` first. This plan assumes `recipes.featured_image_original_name` and the recipe upload's `storeFileNamesIn()` binding exist.

This plan does not snapshot SOP content into `recipe_versions`; that lifecycle is plan 3. Until plan 3 is complete, current recipe content still saves normally and the UI must not claim that history is retained.

The page remains **Instructions & media**. Generated INCI and label output remain in **Label & output**. User-authored description, SOP, and filenames are never machine-translated.

Before every Laravel/Filament code task, search version-specific documentation through Laravel Boost. No dependency change is required.

## File map

- Create `app/Rules/MinimumImageEdges.php`.
- Create `app/Rules/MaximumRichContentImages.php`.
- Create `app/Support/RichContentAttachmentPaths.php`.
- Modify `app/Models/Recipe.php` to use the shared extractor.
- Modify `config/media.php` and `app/Services/MediaStorage.php`.
- Modify `app/Services/RecipeWorkbenchContentFormSchema.php`.
- Modify `app/Services/RecipeContentUpdater.php`.
- Modify `app/Livewire/Dashboard/RecipeWorkbench.php`.
- Modify `resources/views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php`.
- Create `resources/js/recipe-content-autosave.js`.
- Create `resources/js/dirty-state-registry.js`.
- Modify `resources/js/app.js`, `resources/js/recipe-workbench/component.js`, and `resources/js/recipe-workbench/bridge.js`.
- Modify `lang/en/workbench.php` and `config/interface-translations.php` only if the existing wildcard ownership needs no change.
- Create `tests/Unit/MinimumImageEdgesTest.php`, `MaximumRichContentImagesTest.php`, and `RecipeContentAutosaveContractTest.php`.
- Modify `tests/Feature/MediaStorageTest.php`, `RecipeContentMediaContractTest.php`, `RecipeWorkbenchPersistenceTest.php`, `RecipeWorkbenchDesignPolishTest.php`, and `SoapWorkbenchLocalizationTest.php`.

### Task 1: Enforce the approved image dimensions and storage sizes

**Files:**
- Create: `app/Rules/MinimumImageEdges.php`
- Modify: `config/media.php`
- Modify: `app/Services/MediaStorage.php`
- Modify: `app/Services/RecipeWorkbenchContentFormSchema.php`
- Test: `tests/Unit/MinimumImageEdgesTest.php`
- Test: `tests/Feature/MediaStorageTest.php`

- [ ] **Step 1: Generate the rule and unit test**

```bash
php artisan make:rule MinimumImageEdges --no-interaction
php artisan make:test --pest --unit MinimumImageEdgesTest --no-interaction
```

- [ ] **Step 2: Write orientation-independent failing tests**

Use `UploadedFile::fake()->image()` and Laravel's validator. Cover:

```php
it('accepts portrait and landscape images with a 300 pixel short edge and 500 pixel long edge', function (int $width, int $height) {
    $validator = Validator::make(
        ['image' => UploadedFile::fake()->image('product.jpg', $width, $height)],
        ['image' => [new MinimumImageEdges(shortEdge: 300, longEdge: 500)]],
    );

    expect($validator->passes())->toBeTrue();
})->with([[300, 500], [500, 300], [800, 800]]);

it('rejects an image when either orientation-independent edge is too small', function (int $width, int $height) {
    $validator = Validator::make(
        ['image' => UploadedFile::fake()->image('product.jpg', $width, $height)],
        ['image' => [new MinimumImageEdges(shortEdge: 300, longEdge: 500)]],
    );

    expect($validator->fails())->toBeTrue();
})->with([[299, 800], [800, 299], [300, 499], [499, 300]]);
```

Add invalid/non-image coverage and assert the translated validation message mentions both minimum edges.

- [ ] **Step 3: Run the unit test and confirm failure**

```bash
php artisan test --compact tests/Unit/MinimumImageEdgesTest.php
```

Expected: failure until the rule inspects real image dimensions.

- [ ] **Step 4: Implement the validation rule**

Use `getimagesize()` on the `UploadedFile::getRealPath()`. Sort width and height so the smaller value is compared with `shortEdge` and the larger with `longEdge`. Fail invalid images through the provided `$fail` callback. Keep constructor-promoted integer properties and explicit return types.

- [ ] **Step 5: Update media configuration**

Set:

```php
'recipe_featured_images' => [
    'max_size_kb' => 3072,
    'max_width' => 800,
    'max_height' => 800,
    'quality' => 82,
],
'recipe_rich_content_images' => [
    'max_size_kb' => 1536,
    'max_width' => 680,
    'max_height' => 680,
    'quality' => 80,
],
```

Continue using `MediaStorage::storeRecipeResizedWebp()` with `fit: false`; that path preserves aspect ratio and does not upscale. Do not add a crop or a second stored derivative.

- [ ] **Step 6: Remove destructive crop settings and accept PNG**

In the recipe featured upload:

- remove `panelAspectRatio('4:3')`, `imageAspectRatio('4:3')`, `imageEditorAspectRatioOptions()`, the fixed viewport, and automatic aspect-ratio editor opening;
- accept `image/jpeg`, `image/png`, and `image/webp`;
- add `new MinimumImageEdges(300, 500)`;
- retain the private disk, path-tampering protection, randomized WebP callback, and filename metadata binding;
- use a compact preview height around `14rem` and a neutral contain-style panel.

For both rich editors, add PNG to accepted types. Their upload provider automatically reads the new 680/80 config.

- [ ] **Step 7: Update storage tests**

Assert that:

- a 1200 × 600 rich image stores at no more than 680 × 340;
- a 600 × 1200 featured image stores at no more than 400 × 800;
- a valid 500 × 300 image is never upscaled;
- stored output is WebP for JPEG, PNG, and WebP inputs.

Run:

```bash
php artisan test --compact tests/Unit/MinimumImageEdgesTest.php tests/Feature/MediaStorageTest.php
```

Expected: pass.

- [ ] **Step 8: Commit image policy**

```bash
git add app/Rules/MinimumImageEdges.php config/media.php app/Services/MediaStorage.php app/Services/RecipeWorkbenchContentFormSchema.php tests/Unit/MinimumImageEdgesTest.php tests/Feature/MediaStorageTest.php
git commit -m "feat: preserve recipe image proportions"
```

### Task 2: Limit embedded images by saved field content

**Files:**
- Create: `app/Support/RichContentAttachmentPaths.php`
- Create: `app/Rules/MaximumRichContentImages.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Services/RecipeWorkbenchContentFormSchema.php`
- Modify: `app/Services/RecipeContentUpdater.php`
- Test: `tests/Unit/MaximumRichContentImagesTest.php`
- Test: `tests/Feature/RecipeContentMediaContractTest.php`

- [ ] **Step 1: Generate the rule and test**

```bash
php artisan make:rule MaximumRichContentImages --no-interaction
php artisan make:test --pest --unit MaximumRichContentImagesTest --no-interaction
```

- [ ] **Step 2: Write failing count tests**

Test HTML containing `data-id` paths, URL `src` fallbacks, repeated references, and non-image links. Required assertions:

- two distinct description images pass and three fail;
- eight distinct SOP images pass and nine fail;
- the same attachment repeated in a field counts once;
- a path moved from description to SOP is one object and is not deleted;
- a validation failure does not mutate the recipe or delete files.

- [ ] **Step 3: Extract path parsing from `Recipe`**

Move the current `data-id` and `src|href` parsing into `RichContentAttachmentPaths::extract(mixed $content): Collection`. Keep recipe-private path filtering. `Recipe::richContentAttachmentPaths()` delegates to it so existing media cleanup behavior does not diverge.

- [ ] **Step 4: Implement and apply the maximum rule**

`MaximumRichContentImages` takes an integer maximum and uses the shared extractor. The error message receives `:max`. Apply `new MaximumRichContentImages(2)` to description and `new MaximumRichContentImages(8)` to manufacturing procedure in the Filament schema.

Also validate both counts inside `RecipeContentUpdater` before the transaction. This service-level validation prevents Livewire or direct service callers from bypassing the schema.

- [ ] **Step 5: Run focused tests**

```bash
php artisan test --compact tests/Unit/MaximumRichContentImagesTest.php tests/Feature/RecipeContentMediaContractTest.php
```

Expected: pass.

- [ ] **Step 6: Commit content limits**

```bash
git add app/Support/RichContentAttachmentPaths.php app/Rules/MaximumRichContentImages.php app/Models/Recipe.php app/Services/RecipeWorkbenchContentFormSchema.php app/Services/RecipeContentUpdater.php tests/Unit/MaximumRichContentImagesTest.php tests/Feature/RecipeContentMediaContractTest.php
git commit -m "feat: limit recipe editor images"
```

### Task 3: Build the approved responsive page layout

**Files:**
- Modify: `app/Services/RecipeWorkbenchContentFormSchema.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Test: `tests/Feature/SoapWorkbenchLocalizationTest.php`

- [ ] **Step 1: Add failing rendered-copy and structure tests**

Render the partial and assert it contains:

- `Instructions & media`;
- the Product page/SOP supporting sentence;
- `Product description` before `Featured product image` before `Manufacturing procedure`;
- a desktop two-column presentation grid and a full-width procedure section;
- a sticky save bar;
- no `Content & Media`, `Recipe content`, fixed `4:3`, or old 20rem image panel copy.

Test that an unsaved formula shows text editing guidance while image upload actions are disabled.

- [ ] **Step 2: Run the focused tests and confirm failure**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
```

- [ ] **Step 3: Add reviewed English keys**

Add this `instructions` subtree to `lang/en/workbench.php`:

```php
'instructions' => [
    'title' => 'Instructions & media',
    'intro' => 'Add the product description and image used on the Product page, then record the manufacturing procedure used at the bench.',
    'presentation_title' => 'Product presentation',
    'description_label' => 'Product description',
    'description_help' => 'Describe the finished product for its Product page. You can include up to two images.',
    'featured_label' => 'Featured product image',
    'featured_help' => 'JPG, PNG or WebP up to 3 MB. Minimum edges: 300 px and 500 px. The image keeps its original proportions.',
    'procedure_label' => 'Manufacturing procedure',
    'procedure_help' => 'Record the process steps, temperatures, timings, checks and cautions used at the bench. You can include up to eight images.',
    'draft_text_help' => 'You can start writing now. Save the formula before attaching images.',
    'save_changes' => 'Save changes',
    'all_saved' => 'All changes saved',
    'unsaved' => 'Unsaved changes',
    'saving' => 'Saving…',
    'saved_at' => 'Saved at :time',
    'save_failed' => 'Save failed',
    'leave_warning' => 'You have unsaved changes. Leave without saving?',
    'description_image_limit' => 'The product description may contain up to :max images.',
    'procedure_image_limit' => 'The manufacturing procedure may contain up to :max images.',
    'minimum_image_edges' => 'The image must have a short edge of at least :short pixels and a long edge of at least :long pixels.',
],
```

- [ ] **Step 4: Restructure the schema and Blade**

Use Filament `Grid` and `Section` components:

- Product presentation: `lg` 12-column grid, description `lg:8`, featured image `lg:4`;
- Manufacturing procedure: `lg:12` below;
- description editor initial content height approximately 12rem;
- SOP editor initial content height approximately 22rem;
- all spacing through Tailwind `gap-*`, not margins between grid children;
- mobile naturally stacks description, image, procedure.

Remove the enclosing giant card treatment. Keep one concise header, softly bounded form sections, and a bottom `sticky` save bar with enough bottom offset/padding not to cover editor controls.

All visible public copy uses `__('workbench.instructions.*')`. Do not translate user-authored values or the Filament admin.

- [ ] **Step 5: Run rendered tests and build assets**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
npm run build
```

Expected: tests pass and Vite builds without Tailwind errors.

- [ ] **Step 6: Commit the page layout**

```bash
git add app/Services/RecipeWorkbenchContentFormSchema.php resources/views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php lang/en/workbench.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/SoapWorkbenchLocalizationTest.php public/build
git commit -m "feat: redesign instructions and media"
```

Only stage `public/build` if this repository already tracks built assets.

### Task 4: Add deadline-based autosave and one navigation guard

**Files:**
- Create: `resources/js/dirty-state-registry.js`
- Create: `resources/js/recipe-content-autosave.js`
- Modify: `resources/js/app.js`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/bridge.js`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php`
- Modify: `app/Livewire/Dashboard/RecipeWorkbench.php`
- Test: `tests/Unit/RecipeContentAutosaveContractTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Generate and write the failing contract test**

```bash
php artisan make:test --pest --unit RecipeContentAutosaveContractTest --no-interaction
```

The test reads the JS modules and executes a small Node harness, following the existing JavaScript-contract pattern in `RecipeWorkbenchPersistenceTest`. Assert:

- the first dirty event establishes a fixed deadline of `now + 120000`;
- more edits do not move that deadline;
- a successful save clears dirty/failed state and starts a fresh interval on the next edit;
- an expired deadline waits while upload count is positive and saves immediately after the last upload finishes;
- manual and automatic saves share one in-flight promise and cannot submit concurrently;
- failures remain blocking until a later successful save;
- the navigation registry blocks on `dirty`, `saving`, or `failed` and allows `saved`.

- [ ] **Step 2: Run the test and confirm failure**

```bash
php artisan test --compact tests/Unit/RecipeContentAutosaveContractTest.php
```

- [ ] **Step 3: Implement reusable state primitives**

`dirty-state-registry.js` exports a registry backed by `Map`. It exposes `set(key, state)`, `remove(key)`, and `blocksNavigation()`. Only `dirty`, `saving`, and `failed` block.

`recipe-content-autosave.js` exports an Alpine-compatible controller. It receives the two-minute interval, translated labels, and a `save` callback. It listens to form `input` and `change` capture events plus Livewire upload start/finish/error/cancel events. It owns:

- `state`: `saved|dirty|saving|failed`;
- `dirtySince` and an immutable `saveDeadline` for the current dirty interval;
- `activeUploads`;
- a single timer and a single in-flight save promise;
- `savedAt` formatted with the browser locale.

Do not debounce the deadline on every keystroke. A successful save resets the interval. Upload completion calls the overdue save immediately.

- [ ] **Step 4: Return a machine-readable Livewire save response**

Change `RecipeWorkbench::saveRecipeContent()` to return:

```php
array{ok: bool, message: string, saved_at?: string}
```

On success return translated message and `now()->toISOString()`. On missing recipe return `ok: false` without throwing. Validation exceptions continue through Livewire so field errors render. Unexpected failures set the controller to failed and keep navigation protection active.

- [ ] **Step 5: Unify formula and content navigation protection**

The workbench creates one dirty-state registry. Its existing `beforeunload` and `livewire:navigate` handlers block when either:

- the serialized formula differs from its baseline;
- formula persistence is in progress or failed; or
- the registry reports recipe content as dirty, saving, or failed.

Remove the current `|| this.isSaving` bypass. Use `workbench.instructions.leave_warning` instead of hard-coded English. The nested content controller updates the parent registry rather than installing a second browser prompt.

- [ ] **Step 6: Bind the sticky bar**

Use the controller state to render the approved labels and disable Save changes while saving or uploading. Keep the button available when saved so the user may explicitly save. Display validation/server errors adjacent to the bar and preserve Filament field errors.

- [ ] **Step 7: Run tests and build**

```bash
php artisan test --compact tests/Unit/RecipeContentAutosaveContractTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
npm run build
```

Expected: contract and persistence tests pass; Vite builds.

- [ ] **Step 8: Commit save protection**

```bash
git add resources/js/dirty-state-registry.js resources/js/recipe-content-autosave.js resources/js/app.js resources/js/recipe-workbench/component.js resources/js/recipe-workbench/bridge.js resources/views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php app/Livewire/Dashboard/RecipeWorkbench.php tests/Unit/RecipeContentAutosaveContractTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
git commit -m "feat: autosave recipe content safely"
```

### Task 5: Populate and verify contextual translations

**Files:**
- Local database: `language_lines` only for non-English values.
- Modify: `tests/Feature/SoapWorkbenchLocalizationTest.php`.

- [ ] **Step 1: Sync the reviewed English keys**

```bash
php artisan translations:sync
```

Expected: missing `workbench.instructions.*` rows are inserted with blank locale maps and no existing translations are overwritten.

- [ ] **Step 2: Import contextual French, Spanish, German, Italian, and Dutch values**

Use the existing temporary validated import approach. Save only blank locale values. The terminology direction is:

Use this complete reviewed map; the import script converts each locale-first entry into the Spatie `text` JSON shape and writes only blank cells:

```php
$translations = [
    'fr' => [
        'title' => 'Instructions et médias',
        'intro' => 'Ajoutez la description et l’image utilisées sur la fiche produit, puis indiquez le mode opératoire de fabrication suivi à l’atelier.',
        'presentation_title' => 'Présentation du produit',
        'description_label' => 'Description du produit',
        'description_help' => 'Présentez le produit fini pour sa fiche produit. Vous pouvez ajouter jusqu’à deux images.',
        'featured_label' => 'Image principale du produit',
        'featured_help' => 'JPG, PNG ou WebP jusqu’à 3 Mo. Dimensions minimales : 300 px pour le petit côté et 500 px pour le grand. Les proportions d’origine sont conservées.',
        'procedure_label' => 'Mode opératoire de fabrication',
        'procedure_help' => 'Consignez les étapes, températures, durées, contrôles et précautions appliqués à l’atelier. Vous pouvez ajouter jusqu’à huit images.',
        'draft_text_help' => 'Vous pouvez commencer à rédiger. Enregistrez d’abord la formule pour joindre des images.',
        'save_changes' => 'Enregistrer les modifications',
        'all_saved' => 'Toutes les modifications sont enregistrées',
        'unsaved' => 'Modifications non enregistrées',
        'saving' => 'Enregistrement…',
        'saved_at' => 'Enregistré à :time',
        'save_failed' => 'Échec de l’enregistrement',
        'leave_warning' => 'Des modifications ne sont pas enregistrées. Quitter sans les enregistrer ?',
        'description_image_limit' => 'La description du produit peut contenir jusqu’à :max images.',
        'procedure_image_limit' => 'Le mode opératoire de fabrication peut contenir jusqu’à :max images.',
        'minimum_image_edges' => 'Le petit côté de l’image doit mesurer au moins :short pixels et le grand côté au moins :long pixels.',
    ],
    'es' => [
        'title' => 'Instrucciones y contenido multimedia',
        'intro' => 'Añade la descripción y la imagen que se mostrarán en la ficha del producto y, a continuación, detalla el procedimiento de fabricación utilizado en el taller.',
        'presentation_title' => 'Presentación del producto',
        'description_label' => 'Descripción del producto',
        'description_help' => 'Describe el producto terminado para su ficha. Puedes incluir hasta dos imágenes.',
        'featured_label' => 'Imagen principal del producto',
        'featured_help' => 'JPG, PNG o WebP de hasta 3 MB. Dimensiones mínimas: 300 px en el lado corto y 500 px en el largo. Se conservan las proporciones originales.',
        'procedure_label' => 'Procedimiento de fabricación',
        'procedure_help' => 'Anota los pasos, las temperaturas, los tiempos, los controles y las precauciones utilizados en el taller. Puedes incluir hasta ocho imágenes.',
        'draft_text_help' => 'Puedes empezar a escribir. Guarda primero la fórmula para adjuntar imágenes.',
        'save_changes' => 'Guardar cambios',
        'all_saved' => 'Todos los cambios están guardados',
        'unsaved' => 'Cambios sin guardar',
        'saving' => 'Guardando…',
        'saved_at' => 'Guardado a las :time',
        'save_failed' => 'No se han podido guardar los cambios',
        'leave_warning' => 'Hay cambios sin guardar. ¿Quieres salir sin guardarlos?',
        'description_image_limit' => 'La descripción del producto puede contener hasta :max imágenes.',
        'procedure_image_limit' => 'El procedimiento de fabricación puede contener hasta :max imágenes.',
        'minimum_image_edges' => 'El lado corto de la imagen debe medir al menos :short píxeles y el lado largo al menos :long píxeles.',
    ],
    'de' => [
        'title' => 'Anleitung und Medien',
        'intro' => 'Fügen Sie die Produktbeschreibung und das Bild für die Produktseite hinzu und dokumentieren Sie anschließend das Herstellungsverfahren für die praktische Arbeit.',
        'presentation_title' => 'Produktdarstellung',
        'description_label' => 'Produktbeschreibung',
        'description_help' => 'Beschreiben Sie das fertige Produkt für seine Produktseite. Sie können bis zu zwei Bilder einfügen.',
        'featured_label' => 'Hauptbild des Produkts',
        'featured_help' => 'JPG, PNG oder WebP bis 3 MB. Mindestmaße: 300 px an der kurzen und 500 px an der langen Seite. Das ursprüngliche Seitenverhältnis bleibt erhalten.',
        'procedure_label' => 'Herstellungsverfahren',
        'procedure_help' => 'Dokumentieren Sie Arbeitsschritte, Temperaturen, Zeiten, Kontrollen und Vorsichtsmaßnahmen. Sie können bis zu acht Bilder einfügen.',
        'draft_text_help' => 'Sie können bereits mit dem Schreiben beginnen. Speichern Sie zuerst die Rezeptur, bevor Sie Bilder anhängen.',
        'save_changes' => 'Änderungen speichern',
        'all_saved' => 'Alle Änderungen gespeichert',
        'unsaved' => 'Nicht gespeicherte Änderungen',
        'saving' => 'Wird gespeichert…',
        'saved_at' => 'Gespeichert um :time',
        'save_failed' => 'Speichern fehlgeschlagen',
        'leave_warning' => 'Es gibt nicht gespeicherte Änderungen. Seite ohne Speichern verlassen?',
        'description_image_limit' => 'Die Produktbeschreibung darf bis zu :max Bilder enthalten.',
        'procedure_image_limit' => 'Das Herstellungsverfahren darf bis zu :max Bilder enthalten.',
        'minimum_image_edges' => 'Die kurze Bildseite muss mindestens :short Pixel und die lange mindestens :long Pixel groß sein.',
    ],
    'it' => [
        'title' => 'Istruzioni e contenuti multimediali',
        'intro' => 'Aggiungi la descrizione e l’immagine usate nella scheda prodotto, quindi registra la procedura di fabbricazione seguita in laboratorio.',
        'presentation_title' => 'Presentazione del prodotto',
        'description_label' => 'Descrizione del prodotto',
        'description_help' => 'Descrivi il prodotto finito per la sua scheda. Puoi includere fino a due immagini.',
        'featured_label' => 'Immagine principale del prodotto',
        'featured_help' => 'JPG, PNG o WebP fino a 3 MB. Dimensioni minime: 300 px sul lato corto e 500 px sul lato lungo. Le proporzioni originali vengono mantenute.',
        'procedure_label' => 'Procedura di fabbricazione',
        'procedure_help' => 'Registra fasi, temperature, tempi, controlli e precauzioni seguiti in laboratorio. Puoi includere fino a otto immagini.',
        'draft_text_help' => 'Puoi iniziare a scrivere. Salva prima la formula per allegare immagini.',
        'save_changes' => 'Salva le modifiche',
        'all_saved' => 'Tutte le modifiche sono state salvate',
        'unsaved' => 'Modifiche non salvate',
        'saving' => 'Salvataggio…',
        'saved_at' => 'Salvato alle :time',
        'save_failed' => 'Salvataggio non riuscito',
        'leave_warning' => 'Sono presenti modifiche non salvate. Uscire senza salvarle?',
        'description_image_limit' => 'La descrizione del prodotto può contenere fino a :max immagini.',
        'procedure_image_limit' => 'La procedura di fabbricazione può contenere fino a :max immagini.',
        'minimum_image_edges' => 'Il lato corto dell’immagine deve misurare almeno :short pixel e quello lungo almeno :long pixel.',
    ],
    'nl' => [
        'title' => 'Instructies en media',
        'intro' => 'Voeg de productbeschrijving en afbeelding voor de productpagina toe en leg daarna de productiewijze voor aan de werkbank vast.',
        'presentation_title' => 'Productpresentatie',
        'description_label' => 'Productbeschrijving',
        'description_help' => 'Beschrijf het afgewerkte product voor de productpagina. Je kunt maximaal twee afbeeldingen toevoegen.',
        'featured_label' => 'Hoofdafbeelding van het product',
        'featured_help' => 'JPG, PNG of WebP tot 3 MB. Minimale afmetingen: 300 px aan de korte zijde en 500 px aan de lange zijde. De oorspronkelijke verhoudingen blijven behouden.',
        'procedure_label' => 'Productiewijze',
        'procedure_help' => 'Leg de stappen, temperaturen, tijden, controles en voorzorgsmaatregelen aan de werkbank vast. Je kunt maximaal acht afbeeldingen toevoegen.',
        'draft_text_help' => 'Je kunt alvast beginnen met schrijven. Sla eerst de formule op voordat je afbeeldingen toevoegt.',
        'save_changes' => 'Wijzigingen opslaan',
        'all_saved' => 'Alle wijzigingen zijn opgeslagen',
        'unsaved' => 'Niet-opgeslagen wijzigingen',
        'saving' => 'Bezig met opslaan…',
        'saved_at' => 'Opgeslagen om :time',
        'save_failed' => 'Opslaan mislukt',
        'leave_warning' => 'Er zijn niet-opgeslagen wijzigingen. Wil je de pagina verlaten zonder op te slaan?',
        'description_image_limit' => 'De productbeschrijving mag maximaal :max afbeeldingen bevatten.',
        'procedure_image_limit' => 'De productiewijze mag maximaal :max afbeeldingen bevatten.',
        'minimum_image_edges' => 'De korte zijde van de afbeelding moet minimaal :short pixels zijn en de lange zijde minimaal :long pixels.',
    ],
];
```

French deliberately uses **mode opératoire de fabrication**. Dutch uses **Productiewijze**, a bench-facing term rather than software/process architecture language.

- [ ] **Step 3: Add database-backed localization coverage**

Extend `SoapWorkbenchLocalizationTest` with one key from each content group and all five locales. Include the full manufacturing-procedure help sentence so tests prove contextual translations, not only headings.

- [ ] **Step 4: Run the localization and interface tests**

```bash
php artisan test --compact tests/Feature/SoapWorkbenchLocalizationTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php
```

- [ ] **Step 5: Review every locale in the rendered page**

For `fr`, `es`, `de`, `it`, and `nl`:

1. switch locale through the application selector;
2. reload the Instructions & media tab;
3. verify heading, description help, featured-image help, manufacturing help, save states, errors, and leave warning;
4. confirm uploaded filenames and authored text remain unchanged;
5. confirm the Filament admin remains English-only.

- [ ] **Step 6: Run final verification**

```bash
php artisan test --compact tests/Unit/MinimumImageEdgesTest.php tests/Unit/MaximumRichContentImagesTest.php tests/Unit/RecipeContentAutosaveContractTest.php tests/Feature/MediaStorageTest.php tests/Feature/RecipeContentMediaContractTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/SoapWorkbenchLocalizationTest.php
vendor/bin/pint --dirty --format agent
npm run build
graphify update .
```

Expected: all tests pass, formatting is clean, the frontend builds, and the graph refreshes successfully.

- [ ] **Step 7: Commit localization and final adjustments**

```bash
git add lang/en/workbench.php tests/Feature/SoapWorkbenchLocalizationTest.php app resources config tests
git commit -m "feat: localize instructions and media"
```

Skip the commit if no tracked changes remain after the earlier task commits.
