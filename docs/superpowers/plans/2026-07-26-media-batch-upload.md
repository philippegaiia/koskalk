# Media Batch Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace native file controls with Soapkraft controls and add safe, localized, sequential five-file batch uploads to the full Media Library.

**Architecture:** Upload modals keep their existing single-file Livewire pipeline and receive only a branded chooser. The full Media Library uses a focused Alpine factory to hold a browser `File` list and call the existing single-file Livewire upload action sequentially, so validation, quota locking, media records, R2 storage, and the `media` queue remain unchanged.

**Tech Stack:** Laravel 13, Livewire 4 JavaScript upload API, Alpine.js, Tailwind CSS 4, Pest 4, deterministic interface translation catalogue.

---

## File Map

- Create `resources/js/media-library-uploader.js`: isolated client state and sequential upload orchestration for the full gallery.
- Create `tests/Unit/MediaLibraryUploaderTest.php`: executable Node contracts for limits, removal, progress, sequential transfer, and failure continuation.
- Modify `resources/js/app.js`: expose the uploader factory to Alpine markup.
- Modify `resources/js/media-asset-picker.js`: track the chosen single filename and reset it after modal upload.
- Modify `resources/views/forms/components/media-asset-picker.blade.php`: branded single-file chooser for modal and embedded pickers.
- Modify `resources/views/livewire/dashboard/media-library-index.blade.php`: branded five-file selector, removable rows, validation, and batch progress.
- Modify `lang/en/media_library.php`: canonical English batch and chooser keys.
- Modify `tests/Feature/MediaLibraryTest.php`: gallery markup, accessibility, localization, and catalogue ownership coverage.
- Modify `tests/Feature/MediaAssetConsumerIntegrationTest.php`: modal remains single-file and uses the branded chooser.
- Modify `tests/Unit/MediaAssetPickerAccessibilityContractTest.php`: modal filename and accessible chooser contract.
- Modify `database/seeders/data/interface-translations.json`: deterministic reviewed translations for `de`, `es`, `fr`, `it`, and `nl`.

### Task 1: Branded single-file chooser in upload modals

**Files:**
- Modify: `resources/js/media-asset-picker.js`
- Modify: `resources/views/forms/components/media-asset-picker.blade.php`
- Modify: `tests/Feature/MediaAssetConsumerIntegrationTest.php`
- Modify: `tests/Unit/MediaAssetPickerAccessibilityContractTest.php`

- [ ] **Step 1: Write failing modal chooser tests**

Add assertions to `tests/Feature/MediaAssetConsumerIntegrationTest.php`:

```php
expect($pickerView)
    ->toContain('data-media-picker-file-input')
    ->toContain('data-media-picker-file-trigger')
    ->toContain('x-on:change="selectUploadFile($event)"')
    ->not->toContain('multiple');
```

Add an executable JavaScript contract to `tests/Unit/MediaAssetPickerAccessibilityContractTest.php`:

```php
it('tracks and clears the single modal upload filename', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';

const moduleUrl = pathToFileURL(`${process.cwd()}/resources/js/media-asset-picker.js`).href;
const { createMediaAssetPicker } = await import(moduleUrl);
const picker = createMediaAssetPicker({
    embedded: false,
    assetsUrl: '/media',
    livewire: {},
    statePath: 'data.featured_media_asset_id',
    state: null,
    multiple: false,
    maximumItems: 1,
    preserveAspectRatio: false,
    messages: {},
});

picker.selectUploadFile({ target: { files: [{ name: 'soap-front.png' }] } });
assert.equal(picker.uploadFilename, 'soap-front.png');
picker.clearUploadFile();
assert.equal(picker.uploadFilename, '');
JS;

    $process = new Process(['node', '--input-type=module', '--eval', $script], base_path());
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/MediaAssetConsumerIntegrationTest.php --filter='lazy paginated picker'
php artisan test --compact tests/Unit/MediaAssetPickerAccessibilityContractTest.php --filter='tracks and clears'
```

Expected: both fail because the branded chooser markers and filename methods do not exist.

- [ ] **Step 3: Add single-file filename state**

In `resources/js/media-asset-picker.js`, add:

```js
uploadFilename: '',

selectUploadFile(event) {
    this.uploadFilename = event.target.files?.[0]?.name ?? '';
},

clearUploadFile() {
    this.uploadFilename = '';

    if (this.$refs.uploadInput) {
        this.$refs.uploadInput.value = '';
    }
},
```

Replace the existing successful-upload input reset with:

```js
this.clearUploadFile();
```

- [ ] **Step 4: Replace the native-looking modal control**

In `resources/views/forms/components/media-asset-picker.blade.php`, keep the native input for accessibility but visually hide it:

```blade
<input
    id="{{ $pickerId }}-upload"
    x-ref="uploadInput"
    x-on:change="selectUploadFile($event)"
    data-media-picker-file-input
    type="file"
    name="upload"
    accept=".jpg,.jpeg,.png,.webp,.heic,.heif,image/jpeg,image/png,image/webp,image/heic,image/heif"
    class="peer sr-only"
/>
<div class="flex min-h-12 flex-wrap items-center gap-3 rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] p-2">
    <label
        for="{{ $pickerId }}-upload"
        data-media-picker-file-trigger
        class="sk-btn cursor-pointer border border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--color-accent)]"
    >
        {{ __('media_library.picker.choose_file') }}
    </label>
    <span class="min-w-0 flex-1 truncate text-sm text-[var(--color-ink-soft)]" x-text="uploadFilename || '{{ __('media_library.picker.no_file_selected') }}'"></span>
</div>
```

- [ ] **Step 5: Run the focused tests and verify GREEN**

Run the two commands from Step 2.

Expected: PASS.

- [ ] **Step 6: Commit the modal chooser**

```bash
git add resources/js/media-asset-picker.js resources/views/forms/components/media-asset-picker.blade.php tests/Feature/MediaAssetConsumerIntegrationTest.php tests/Unit/MediaAssetPickerAccessibilityContractTest.php
git commit -m "fix: brand media picker file control"
```

### Task 2: Batch uploader state and sequential transfer

**Files:**
- Create: `resources/js/media-library-uploader.js`
- Create: `tests/Unit/MediaLibraryUploaderTest.php`
- Modify: `resources/js/app.js`

- [ ] **Step 1: Write the failing JavaScript contract**

Create `tests/Unit/MediaLibraryUploaderTest.php` with a Node process that imports the factory and verifies:

```js
const uploaded = [];
const started = [];
const livewire = {
    upload(property, file, finish, error, progress) {
        uploaded.push(file.name);
        progress({ detail: { progress: 60 } });
        file.name === 'bad.png' ? error() : finish();
    },
    async uploadAsset() {
        started.push(uploaded.at(-1));
    },
};

const uploader = createMediaLibraryUploader({
    livewire,
    maxFiles: 5,
    remaining: null,
    messages: {
        uploadFailed: ':name could not be uploaded.',
    },
});

uploader.selectFiles({
    target: {
        files: [
            { name: 'one.png' },
            { name: 'bad.png' },
            { name: 'three.png' },
        ],
    },
});

await uploader.uploadBatch();

assert.deepEqual(uploaded, ['one.png', 'bad.png', 'three.png']);
assert.deepEqual(started, ['one.png', 'three.png']);
assert.equal(uploader.files.find((entry) => entry.name === 'bad.png').status, 'failed');
assert.equal(uploader.currentProgress, 60);
```

Add assertions that six selected files set `overBatchLimit === true`, removal reduces the count, and a finite `remaining` allowance disables upload when exceeded.

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test --compact tests/Unit/MediaLibraryUploaderTest.php
```

Expected: FAIL because `resources/js/media-library-uploader.js` does not exist.

- [ ] **Step 3: Implement the isolated factory**

Create `resources/js/media-library-uploader.js` exporting:

```js
export function createMediaLibraryUploader(options) {
    return {
        files: [],
        uploading: false,
        currentIndex: 0,
        currentProgress: 0,
        livewire: options.livewire,
        maxFiles: options.maxFiles,
        remaining: options.remaining,
        messages: options.messages,

        get overBatchLimit() {
            return this.files.length > this.maxFiles;
        },

        get overQuotaLimit() {
            return this.remaining !== null && this.files.length > this.remaining;
        },

        get canUpload() {
            return this.files.length > 0
                && !this.uploading
                && !this.overBatchLimit
                && !this.overQuotaLimit;
        },

        selectFiles(event) {
            this.files = Array.from(event.target.files ?? []).map((file, index) => ({
                id: `${file.name}-${file.size ?? 0}-${file.lastModified ?? 0}-${index}`,
                file,
                name: file.name,
                status: 'selected',
                progress: 0,
                error: null,
            }));
        },

        removeFile(index) {
            if (!this.uploading) {
                this.files.splice(index, 1);
            }
        },

        async uploadBatch() {
            if (!this.canUpload) {
                return;
            }

            this.uploading = true;

            for (const [index, entry] of this.files.entries()) {
                this.currentIndex = index;
                this.currentProgress = 0;
                await this.uploadFile(entry);
            }

            this.uploading = false;
            this.files = this.files.filter((entry) => entry.status === 'failed');
        },

        uploadFile(entry) {
            entry.status = 'uploading';

            return new Promise((resolve) => {
                this.livewire.upload(
                    'upload',
                    entry.file,
                    async () => {
                        try {
                            await this.livewire.uploadAsset();
                            entry.status = 'queued';
                        } catch {
                            entry.status = 'failed';
                            entry.error = this.message('uploadFailed', { name: entry.name });
                        }

                        resolve();
                    },
                    () => {
                        entry.status = 'failed';
                        entry.error = this.message('uploadFailed', { name: entry.name });
                        resolve();
                    },
                    (event) => {
                        entry.progress = event.detail.progress;
                        this.currentProgress = event.detail.progress;
                    },
                );
            });
        },

        message(key, replacements = {}) {
            return Object.entries(replacements).reduce(
                (message, [name, value]) => message.replace(`:${name}`, value),
                this.messages[key],
            );
        },
    };
}
```

- [ ] **Step 4: Register the factory**

In `resources/js/app.js`:

```js
import { createMediaLibraryUploader } from './media-library-uploader';

window.mediaLibraryUploader = createMediaLibraryUploader;
```

- [ ] **Step 5: Run the JavaScript contract and verify GREEN**

Run:

```bash
php artisan test --compact tests/Unit/MediaLibraryUploaderTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the uploader engine**

```bash
git add resources/js/app.js resources/js/media-library-uploader.js tests/Unit/MediaLibraryUploaderTest.php
git commit -m "feat: add sequential media batch uploader"
```

### Task 3: Full Media Library batch interface

**Files:**
- Modify: `resources/views/livewire/dashboard/media-library-index.blade.php`
- Modify: `tests/Feature/MediaLibraryTest.php`

- [ ] **Step 1: Write failing gallery interface tests**

Extend the existing upload feedback test in `tests/Feature/MediaLibraryTest.php`:

```php
->assertSeeHtml('x-data="mediaLibraryUploader(')
->assertSeeHtml('data-media-library-file-input')
->assertSeeHtml('multiple')
->assertSeeHtml('data-media-library-selected-files')
->assertSeeHtml('data-media-library-remove-file')
->assertSeeHtml('data-media-library-batch-limit')
->assertSeeHtml('data-media-library-batch-progress')
->assertSeeHtml('x-bind:disabled="! canUpload"');
```

Keep the existing accent progress and accessible `role="progressbar"` assertions.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/MediaLibraryTest.php --filter='temporarily uploading'
```

Expected: FAIL because the gallery is still a single native file control.

- [ ] **Step 3: Bind the gallery to the uploader factory**

Replace the current upload form root with:

```blade
<form
    x-data="mediaLibraryUploader({
        livewire: $wire,
        maxFiles: 5,
        remaining: @js($usage['remaining']),
        messages: {
            uploadFailed: @js(__('media_library.batch_file_failed')),
        },
    })"
    x-on:submit.prevent="uploadBatch()"
    class="flex w-full max-w-md flex-col gap-3 lg:items-end"
>
```

Use a hidden multiple input and branded label:

```blade
<input
    x-ref="fileInput"
    x-on:change="selectFiles($event)"
    data-media-library-file-input
    type="file"
    multiple
    accept=".jpg,.jpeg,.png,.webp,.heic,.heif,image/jpeg,image/png,image/webp,image/heic,image/heif"
    class="sr-only"
/>
```

Render the count and rows from `files`, with a remove button whose accessible label is built from the translated `remove_file` template. Render the batch-limit message when `overBatchLimit`, the quota message when `overQuotaLimit`, and bind the submit button to `! canUpload`.

Render active transfer feedback with:

```blade
<div data-media-library-batch-progress x-show="uploading" role="status" aria-live="polite">
    <span x-text="message('batchPosition', { current: currentIndex + 1, total: files.length })"></span>
    <div role="progressbar" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="currentProgress" class="h-1.5 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
        <div class="h-full rounded-full bg-[var(--color-accent)] transition-[width] duration-150" x-bind:style="`width: ${currentProgress}%`"></div>
    </div>
</div>
```

- [ ] **Step 4: Run focused and complete media tests**

Run:

```bash
php artisan test --compact tests/Feature/MediaLibraryTest.php
php artisan test --compact tests/Unit/MediaLibraryUploaderTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit the gallery interface**

```bash
git add resources/views/livewire/dashboard/media-library-index.blade.php tests/Feature/MediaLibraryTest.php
git commit -m "feat: add gallery batch upload interface"
```

### Task 4: Complete six-locale copy and durable catalogue

**Files:**
- Modify: `lang/en/media_library.php`
- Modify: `tests/Feature/MediaLibraryTest.php`
- Modify: `database/seeders/data/interface-translations.json`

- [ ] **Step 1: Add failing localization assertions**

Extend the existing locale dataset test so each non-English locale must translate:

```php
expect(__('media_library.choose_files'))->not->toBe('Choose images')
    ->and(__('media_library.upload_selected'))->not->toBe('Upload selected images')
    ->and(__('media_library.batch_limit', ['max' => 5, 'count' => 1]))
    ->not->toContain('batch_limit')
    ->and(__('media_library.picker.choose_file'))->not->toBe('Choose image')
    ->and(__('media_library.picker.no_file_selected'))->not->toBe('No image selected');
```

Add a catalogue assertion that the exported JSON contains every new `media_library` key with all five catalogue locales.

- [ ] **Step 2: Run localization tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/MediaLibraryTest.php --filter='localized media picker strings'
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: FAIL because the new keys and catalogue rows are absent.

- [ ] **Step 3: Add canonical English keys**

Add these keys to `lang/en/media_library.php`:

```php
'choose_files' => 'Choose images',
'selected_files' => '{1} :count image selected|[2,*] :count images selected',
'upload_selected' => 'Upload selected images',
'batch_limit' => 'Maximum :max images per upload. Reduce the selection by :count to continue.',
'batch_position' => 'Uploading :current of :total',
'remove_file' => 'Remove :name',
'batch_file_failed' => ':name could not be uploaded. Try again or remove it.',
'batch_quota' => 'Remaining media capacity for your plan: :count.',
```

Under `picker` add:

```php
'choose_file' => 'Choose image',
'no_file_selected' => 'No image selected',
```

- [ ] **Step 4: Synchronize keys and save reviewed translations**

Run:

```bash
php artisan translations:sync
```

Update only the new blank `media_library` rows in the local `language_lines` table with these reviewed translations:

| Key | German | Spanish | French | Italian | Dutch |
|---|---|---|---|---|---|
| `choose_files` | Bilder auswählen | Elegir imágenes | Choisir des images | Scegli immagini | Afbeeldingen kiezen |
| `selected_files` | `{1} :count Bild ausgewählt\|[2,*] :count Bilder ausgewählt` | `{1} :count imagen seleccionada\|[2,*] :count imágenes seleccionadas` | `{1} :count image sélectionnée\|[2,*] :count images sélectionnées` | `{1} :count immagine selezionata\|[2,*] :count immagini selezionate` | `{1} :count afbeelding geselecteerd\|[2,*] :count afbeeldingen geselecteerd` |
| `upload_selected` | Ausgewählte Bilder hochladen | Subir imágenes seleccionadas | Importer les images sélectionnées | Carica le immagini selezionate | Geselecteerde afbeeldingen uploaden |
| `batch_limit` | Maximal :max Bilder pro Upload. Reduzieren Sie die Auswahl um :count, um fortzufahren. | Máximo :max imágenes por carga. Reduce la selección en :count para continuar. | Limite de :max images par import. Réduisez la sélection de :count pour continuer. | Massimo :max immagini per caricamento. Riduci la selezione di :count per continuare. | Maximaal :max afbeeldingen per upload. Verminder de selectie met :count om door te gaan. |
| `batch_position` | Bild :current von :total wird hochgeladen | Subiendo :current de :total | Importation de :current sur :total | Caricamento :current di :total | :current van :total uploaden |
| `remove_file` | :name entfernen | Eliminar :name | Retirer :name | Rimuovi :name | :name verwijderen |
| `batch_file_failed` | :name konnte nicht hochgeladen werden. Versuchen Sie es erneut oder entfernen Sie die Datei. | No se pudo subir :name. Inténtalo de nuevo o elimina el archivo. | Impossible d’importer :name. Réessayez ou retirez le fichier. | Impossibile caricare :name. Riprova o rimuovi il file. | :name kon niet worden geüpload. Probeer opnieuw of verwijder het bestand. |
| `batch_quota` | Verbleibende Medienkapazität Ihres Tarifs: :count. | Capacidad multimedia restante de tu plan: :count. | Capacité restante de votre forfait : :count médias. | Capacità multimediale rimanente del piano: :count. | Resterende mediacapaciteit van je abonnement: :count. |
| `picker.choose_file` | Bild auswählen | Elegir imagen | Choisir une image | Scegli immagine | Afbeelding kiezen |
| `picker.no_file_selected` | Kein Bild ausgewählt | Ninguna imagen seleccionada | Aucune image sélectionnée | Nessuna immagine selezionata | Geen afbeelding geselecteerd |

Use the existing `InterfaceTranslation` model and update only when the locale value is blank, preserving any reviewed text.

- [ ] **Step 5: Export and verify the deterministic catalogue**

Run:

```bash
php artisan translations:catalogue:export
cp database/seeders/data/interface-translations.json /tmp/soapkraft-interface-translations-first.json
php artisan translations:catalogue:export
cmp /tmp/soapkraft-interface-translations-first.json database/seeders/data/interface-translations.json
```

Expected: `cmp` returns no output, proving the repeated export is byte-for-byte identical, and every new row includes `de`, `es`, `fr`, `it`, and `nl`.

- [ ] **Step 6: Run localization and catalogue tests**

Run:

```bash
php artisan test --compact tests/Feature/MediaLibraryTest.php --filter='localized media picker strings'
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit localized copy and catalogue**

```bash
git add lang/en/media_library.php tests/Feature/MediaLibraryTest.php database/seeders/data/interface-translations.json
git commit -m "feat: localize media batch uploads"
```

### Task 5: Final verification and release-ready commit

**Files:**
- Verify all files changed by Tasks 1–4.

- [ ] **Step 1: Run focused media suites**

```bash
php artisan test --compact tests/Feature/MediaLibraryTest.php tests/Feature/MediaAssetConsumerIntegrationTest.php tests/Unit/MediaAssetPickerAccessibilityContractTest.php tests/Unit/MediaLibraryUploaderTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: all tests PASS with no warnings or errors.

- [ ] **Step 2: Run formatting and frontend build**

```bash
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
```

Expected: Pint completes cleanly, Vite builds production assets, and `git diff --check` returns no output.

- [ ] **Step 3: Refresh the code graph**

```bash
graphify update .
```

Expected: graph extraction completes successfully.

- [ ] **Step 4: Perform local acceptance**

With `php artisan queue:work database --queue=media,default --timeout=300 --memory=512` running:

1. Confirm modal upload uses the branded single-file chooser and automatically selects the ready image.
2. Select six gallery images and verify submission is blocked until one is removed.
3. Upload five valid images and verify sequential progress, five processing cards, and eventual ready thumbnails.
4. Include one invalid file and verify valid files continue while the failed row remains actionable.
5. Repeat the visual check in each supported locale.

- [ ] **Step 5: Review repository state**

```bash
git status --short
git log -6 --oneline
```

Expected: only intentional media upload changes remain and all implementation commits are present.
