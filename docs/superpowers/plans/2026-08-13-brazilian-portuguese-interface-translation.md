# Brazilian Portuguese Interface Translation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a complete, reviewed Brazilian Portuguese (`pt_BR`) interface catalogue for Soapkraft while retaining English fallback for platform ingredient content and keeping Portuguese inactive until final approval.

**Architecture:** Maintain the existing English-source / database-backed interface translation design. Draft the complete locale in an ignored local JSON copy, validate every batch with the existing catalogue validator, then promote the reviewed values into `database/seeders/data/interface-translations.json` in one deterministic update. Portuguese framework files and number formatting already exist; no provider, API key, translation job, migration, or new application abstraction is introduced.

**Tech Stack:** Laravel 13, PHP 8.5, Pest 4, `spatie/laravel-translation-loader`, Laravel Lang, `jq`, GPT-5.6 Luna (medium) for routine drafting, GPT-5.6 Terra (high) for targeted editorial review.

---

## File Structure

- Create temporarily, never commit: `storage/app/translation-drafts/pt_BR-interface-translations.json` — a full six-locale working copy for Portuguese drafting and pre-promotion validation. `storage/app/.gitignore` excludes it.
- Modify: `database/seeders/data/interface-translations.json` — the authoritative, deterministic six-locale interface catalogue after every `pt_BR` value is reviewed.
- Modify: `config/interface-translations.php` — promote `pt_BR` into `catalogue_locales` only after the full catalogue is valid.
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php` — assert six-locale completeness and six-locale export behavior.
- Modify: `tests/Feature/InterfaceTranslationFoundationTest.php` — assert a completed database-backed Brazilian Portuguese value rather than English fallback.
- Modify: `tests/Feature/LanguagePreferenceTest.php` — retain the inactive guard and prove the deliberate post-review activation path.

## Batch Contract

Use this exact JSON line shape in every model request and response. It keeps the task bounded and makes merging mechanical:

```json
{"group":"ingredients","key":"editor.details.name","english":"Name","pt_BR":"Nome"}
```

Every request must include this instruction before its JSON lines:

```text
Translate only the supplied Soapkraft interface strings into natural Brazilian Portuguese.
Return JSON Lines only, one line per input item, preserving group and key exactly.
Keep Laravel placeholders such as :name and :count, HTML tags, escaped newlines, punctuation meaning, and all controlled identifiers unchanged.
Do not translate INCI, CAS, EC/EINECS, IFRA, COSING, SAP, units, codes, or brand names.
Use the approved glossary. Do not add explanations, Markdown, missing keys, or duplicate keys.
```

The committed catalogue contains only non-English values. Extract English source text through `EnglishTranslationSource`, never from `.text.en`:

```bash
php artisan tinker --execute '$groups = ["account", "auth", "dashboard", "navigation", "number_formats", "plans", "public", "settings", "table"]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && in_array($group, $groups, true)) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-foundation.jsonl
```

Change only the `$groups` or the explicit key-prefix predicate for later batches. The source extraction is read-only and the JSON Lines file is an ignored local artifact.

The approved initial glossary is:

| English source term | Brazilian Portuguese |
| --- | --- |
| workspace | espaço de trabalho |
| ingredient | ingrediente |
| formula | fórmula |
| product | produto |
| batch | lote |
| supplier | fornecedor |
| supplier listing | oferta do fornecedor |
| saponification | saponificação |
| formula additions | adições à fórmula |
| lye | soda cáustica |
| compliance | conformidade |
| allergen | alérgeno |
| preservation | conservação |
| preservative booster | potencializador de conservação |
| save | salvar |
| delete | excluir |

For every completed batch, place only the returned `pt_BR` values into the matching rows of the ignored draft file. Do not alter English or the existing `de`, `es`, `fr`, `it`, or `nl` values.

If the English source is intentionally blank, keep `pt_BR` blank and exclude that row from both model requests and completeness checks. For example, `plans.catalog.free-beta.price_label` is deliberately blank in every locale.

### Task 1: Create and Validate the Isolated Portuguese Draft

**Files:**
- Create temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`
- Test: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Copy the authoritative catalogue to the ignored draft path**

Run:

```bash
mkdir -p storage/app/translation-drafts
cp database/seeders/data/interface-translations.json storage/app/translation-drafts/pt_BR-interface-translations.json
```

- [ ] **Step 2: Add `pt_BR` to the draft locale list and add an empty `pt_BR` value to every non-empty translation map**

Run the following `jq` transformation, which preserves the original key ordering and creates a deliberately incomplete draft:

```bash
jq '
  .locales = ["de", "es", "fr", "it", "nl", "pt_BR"]
  | .translations |= map(
      if (.text | length) == 0 then . else .text.pt_BR = "" end
    )
' database/seeders/data/interface-translations.json > storage/app/translation-drafts/pt_BR-interface-translations.json
```

- [ ] **Step 3: Run the completeness guard against the incomplete draft and verify RED**

Run:

```bash
test -z "$(jq -r '.translations[] | select((.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json)"
```

Expected: the command exits non-zero because the empty `pt_BR` draft values are detected. Do not invoke `translations:catalogue:import` on the incomplete draft; the authoritative catalogue and database must remain unchanged.

- [ ] **Step 4: Confirm the draft is ignored and the authoritative file is unchanged**

Run:

```bash
git status --short storage/app/translation-drafts/pt_BR-interface-translations.json database/seeders/data/interface-translations.json
```

Expected: no draft file appears in Git status and no authoritative catalogue change appears.

### Task 2: Confirm the Glossary with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`
- Reference: `docs/developer/translation-handoff.md`

- [ ] **Step 1: Send the Batch Contract glossary to GPT-5.6 Luna at medium reasoning**

Ask Luna to return exactly one Markdown table with the English source term, recommended `pt_BR` term, and one short note only when the term must remain controlled. Include the glossary above and these existing project decisions: `Saponification` is the soap-and-lye stage, `Formula additions` is the later additive stage, platform ingredient names are not part of this work, and interface language is independent from number formatting.

- [ ] **Step 2: Compare Luna’s output against the fixed glossary and resolve only genuine Brazilian Portuguese wording conflicts**

Keep the fixed forms for `INCI`, `CAS`, `EC/EINECS`, `COSING`, `IFRA`, and `SAP`. Retain `espaço de trabalho`, `saponificação`, and `adições à fórmula` unless Terra later identifies a stronger cosmetics-industry alternative.

- [ ] **Step 3: Record the approved glossary at the top of the local translation-session notes, not in application source**

Use the exact table from the Batch Contract, amended only with the selected wording from Step 2. Do not create a new runtime glossary file or translation provider configuration.

### Task 3: Draft Foundation and General Interface Batches with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract the 139 foundation strings**

Run:

```bash
php artisan tinker --execute '$groups = ["account", "auth", "dashboard", "navigation", "number_formats", "plans", "public", "settings", "table"]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && in_array($group, $groups, true)) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-foundation.jsonl
```

Expected groups and counts: account 43, auth 14, dashboard 8, navigation 18, number_formats 10, plans 7, public 7, settings 23, and table 9.

- [ ] **Step 2: Translate the extracted JSON Lines with GPT-5.6 Luna at medium reasoning**

Send no more than 80 lines per request with the Batch Contract and approved glossary. Preserve placeholders, especially account plan usage labels and authentication throttling values.

- [ ] **Step 3: Merge only returned `pt_BR` values into matching draft rows**

For each returned object, find the row by both `group` and `key`, insert `text.pt_BR`, then sort the `text` map keys as `de`, `es`, `fr`, `it`, `nl`, `pt_BR`. Reject a response line if it omits a requested key, changes a key, or returns empty text.

- [ ] **Step 4: Run a batch completeness check**

Run:

```bash
jq -r '.translations[] | select((.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: this prints untranslated rows from later batches, but none from the nine foundation groups.

### Task 4: Draft Product, Packaging, and Media Batches with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract the product and media strings**

Run:

```bash
php artisan tinker --execute '$groups = ["products", "packaging", "media", "media_library"]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && in_array($group, $groups, true)) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-product-media.jsonl
```

Expected groups and counts: products 62, packaging 71, media 1, and media_library 181.

- [ ] **Step 2: Translate in four requests of at most 80 lines using Luna medium**

Use the Batch Contract. Treat file type names, accepted extensions, MIME labels, sizes, and media asset role names as controlled technical text when they appear inside otherwise translated sentences.

- [ ] **Step 3: Merge, sort locale maps, and verify the four groups contain no blank `pt_BR` value**

Run:

```bash
jq -r '.translations[] | select((.group == "products" or .group == "packaging" or .group == "media" or .group == "media_library") and (.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: no output.

### Task 5: Draft Formula Documents and Workbench Batches with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract formula-document and workbench strings**

Run:

```bash
php artisan tinker --execute '$groups = ["formula_documents", "workbench"]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && in_array($group, $groups, true)) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-workbench.jsonl
```

Expected counts: formula_documents 108 and workbench 293.

- [ ] **Step 2: Translate in six requests of at most 70 lines using Luna medium**

Apply the fixed terms `saponificação`, `adições à fórmula`, `fórmula`, and `produto`. Never translate INCI rows, fatty-acid abbreviations, units, SAP values, or IFRA/COSING identifiers.

- [ ] **Step 3: Merge and run the workbench-specific completeness check**

Run:

```bash
jq -r '.translations[] | select((.group == "formula_documents" or .group == "workbench") and (.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: no output.

### Task 6: Draft Ingredient Taxonomy and General Ingredient Batches with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract the 323 taxonomy and general ingredient strings**

Run:

```bash
php artisan tinker --execute '$prefixes = ["categories.", "subcategories.", "accessibility.", "actions.", "auth.", "catalog.", "duplicate.", "empty.", "filters.", "limits.", "page.", "price.", "removal.", "search.", "sort.", "status.", "table.", "usage.", "validation."]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && $group === "ingredients" && collect($prefixes)->contains(fn (string $prefix): bool => str_starts_with($key, $prefix))) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-ingredients-general.jsonl
```

The batch includes 68 category labels, 160 subcategory labels, 24 removal messages, and the remaining general ingredient UI strings.

- [ ] **Step 2: Translate in five requests of at most 70 lines using Luna medium**

Preserve every taxonomy backing value and controlled scheme label. Translate labels for beginners and professionals naturally; do not replace regulatory function names with a different technical claim.

- [ ] **Step 3: Run a first Terra-high review only on taxonomy, preservation, regulatory, and removal text**

Send the 68 `categories.*`, 160 `subcategories.*`, all `removal.*`, and all `validation.*` JSON Lines to Terra. Ask it to return only changed `pt_BR` values where the Luna value is not natural, technically accurate, or aligned with the glossary.

- [ ] **Step 4: Apply Terra corrections and verify there are no blanks in the reviewed general ingredient subset**

Run:

```bash
jq -r '.translations[] | select(.group == "ingredients" and (.key | startswith("categories.") or startswith("subcategories.") or startswith("removal.") or startswith("validation.")) and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: no output.

### Task 7: Draft Ingredient Editor Batches and Perform Terra-High Review

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract the 235 `ingredients.editor.*` strings**

Run:

```bash
php artisan tinker --execute 'foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && $group === "ingredients" && str_starts_with($key, "editor.")) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-ingredients-editor.jsonl
```

- [ ] **Step 2: Translate in four requests of at most 60 lines using Luna medium**

Keep `INCI`, `CAS`, `EC / EINECS`, `COSING`, `IFRA`, `SAP`, `InChIKey`, and `PubChem CID` unchanged. Translate user-facing explanation around them. Do not turn a suggestion into a verified regulatory statement.

- [ ] **Step 3: Send the entire completed `ingredients.editor.*` subset to Terra high for editorial review**

Ask Terra to check soap chemistry, aromatic compliance, regulatory-review wording, restricted/prohibited substance language, identity labels, classification-helper instructions, alerts, and destructive actions. It must preserve controlled identifiers, backing values, and placeholders.

- [ ] **Step 4: Apply corrections and prove the full 558-key Ingredients group is complete**

Run:

```bash
jq -r '.translations[] | select(.group == "ingredients" and (.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: no output.

### Task 8: Draft Production Bench Operations Batches with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract operations and procurement strings**

Run:

```bash
php artisan tinker --execute '$prefixes = ["access.", "calendar.", "common.", "filters.", "flash.", "home.", "inventory.", "listing.", "navigation.", "procurement.", "supplier."]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && $group === "production_bench" && ($key === "title" || collect($prefixes)->contains(fn (string $prefix): bool => str_starts_with($key, $prefix)))) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-production-operations.jsonl
```

Expected count: 268 strings.

- [ ] **Step 2: Translate in four requests of at most 70 lines using Luna medium**

Use `lote` for batch, `fornecedor` for supplier, and `oferta do fornecedor` for supplier listing. Preserve price, currency, unit, and percentage placeholders exactly.

- [ ] **Step 3: Merge and confirm the operations subset is complete**

Run:

```bash
jq -r '.translations[] | select(.group == "production_bench" and (.key == "title" or (.key | startswith("access.") or startswith("calendar.") or startswith("common.") or startswith("filters.") or startswith("flash.") or startswith("home.") or startswith("inventory.") or startswith("listing.") or startswith("navigation.") or startswith("procurement.") or startswith("supplier."))) and (.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: no output.

### Task 9: Draft Production, Receiving, and Bench Settings Batches with Luna Medium

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`

- [ ] **Step 1: Extract production, receipt, and settings strings**

Run:

```bash
php artisan tinker --execute '$prefixes = ["production.", "receipt.", "settings."]; foreach (app(App\Services\Translations\EnglishTranslationSource::class)->all() as $fullKey => $english) { [$group, $key] = explode(".", $fullKey, 2); if (filled($english) && $group === "production_bench" && collect($prefixes)->contains(fn (string $prefix): bool => str_starts_with($key, $prefix))) { echo json_encode(["group" => $group, "key" => $key, "english" => $english], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL; } }' > storage/app/translation-drafts/pt_BR-production-lifecycle.jsonl
```

Expected counts: production 334, receipt 136, settings 135, for 605 strings total.

- [ ] **Step 2: Translate in nine requests of at most 70 lines using Luna medium**

Treat production lifecycle states, stock, lot, quarantine, release, receipt, and reversal text as operational language. Preserve all placeholders, whole-number requirements, units, and status identifiers.

- [ ] **Step 3: Send all lifecycle, receipt validation, and destructive/reversal strings to Terra high**

Review the `production.validation.*`, `receipt.*`, `production.abort*`, `production.cancel*`, `production.delete*`, `production.release*`, `production.start_blocked*`, and `settings.delete_*` subsets. Terra returns only corrections required for safety, clarity, or accurate manufacturing terminology.

- [ ] **Step 4: Prove the whole production bench group is complete**

Run:

```bash
jq -r '.translations[] | select(.group == "production_bench" and (.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: no output.

### Task 10: Validate the Complete Draft Before Promotion

**Files:**
- Modify temporarily: `storage/app/translation-drafts/pt_BR-interface-translations.json`
- Test: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Check the draft has exactly six sorted locale codes and 2,286 translation rows**

Run:

```bash
jq '{locales, rows: (.translations | length), incomplete: [.translations[] | select((.text | length) > 0 and (.text.pt_BR | type != "string" or .text.pt_BR == "")) | "\(.group).\(.key)"]}' storage/app/translation-drafts/pt_BR-interface-translations.json
```

Expected: `locales` is `["de","es","fr","it","nl","pt_BR"]`, `rows` is `2286`, and `incomplete` is an empty array.

- [ ] **Step 2: Validate the full draft through the application catalogue reader without importing it**

Run:

```bash
php artisan tinker --execute '$catalogue = app(App\Services\Translations\InterfaceTranslationCatalogue::class)->read(base_path("storage/app/translation-drafts/pt_BR-interface-translations.json")); dump(["locales" => $catalogue["locales"], "rows" => count($catalogue["translations"])]);'
```

Expected: it returns `locales` as `['de', 'es', 'fr', 'it', 'nl', 'pt_BR']` and `rows` as `2286` without a validation exception. The command is read-only; do not run `translations:catalogue:import` until Task 12.

- [ ] **Step 3: Review a random cross-section with Terra high**

Review 10 strings from each group plus every high-risk subset already listed. Ask Terra to identify only mistranslation, unsafe ambiguity, placeholder loss, or terminology drift. Apply only concrete corrections.

### Task 11: Promote the Complete Catalogue with Test-First Coverage

**Files:**
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php:26-65, 154-226, 650-710`
- Modify: `tests/Feature/InterfaceTranslationFoundationTest.php:87-98`
- Modify: `tests/Feature/LanguagePreferenceTest.php:104-112`
- Modify: `config/interface-translations.php:4`
- Modify: `database/seeders/data/interface-translations.json`

- [ ] **Step 1: Write failing six-locale assertions**

Replace the five-locale arrays with six locales and add these assertions:

```php
$catalogueLocales = ['de', 'es', 'fr', 'it', 'nl', 'pt_BR'];

expect(config('interface-translations.catalogue_locales'))->toBe($catalogueLocales)
    ->and($catalogue['locales'])->toBe($catalogueLocales);

if (filled($english)) {
    foreach ($catalogueLocales as $locale) {
        expect(trim((string) data_get($rows[$fullKey], "text.{$locale}")))
            ->not->toBe('', "Missing {$locale} translation for {$fullKey} ({$english})");
    }
}
```

Replace the infrastructure fallback assertion with a database-backed Portuguese assertion:

```php
InterfaceTranslation::query()->create([
    'group' => 'public',
    'key' => 'navigation.product',
    'text' => ['pt_BR' => 'Produto'],
]);

App::setLocale('pt_BR');

expect(__('public.navigation.product'))->toBe('Produto');
```

Add an activation-path test that creates `pt_BR` inactive, confirms the language update route rejects it, updates only that locale to `is_active => true`, and then asserts the route stores `pt_BR` in the session and cookie.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/LanguagePreferenceTest.php --filter='complete reviewed translation|inactive untranslated|Brazilian Portuguese|inactive language'
```

Expected: failure because the authoritative catalogue and configured locale list still omit `pt_BR`.

- [ ] **Step 3: Replace the authoritative catalogue with the validated draft and promote `pt_BR` in configuration**

Run:

```bash
cp storage/app/translation-drafts/pt_BR-interface-translations.json database/seeders/data/interface-translations.json
```

Then change the configuration to:

```php
'catalogue_locales' => ['de', 'es', 'fr', 'it', 'nl', 'pt_BR'],
```

Keep each `text` map sorted as `de`, `es`, `fr`, `it`, `nl`, `pt_BR`. Do not activate the seeded locale.

- [ ] **Step 4: Run the promotion tests and verify GREEN**

Run:

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/LanguagePreferenceTest.php
```

Expected: all selected tests pass, including six-locale completeness, catalogue read/import validation, inactive selector blocking, and intentional activation behavior.

### Task 12: Rendered Review, Formatting, and Commit

**Files:**
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `config/interface-translations.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`
- Modify: `tests/Feature/InterfaceTranslationFoundationTest.php`
- Modify: `tests/Feature/LanguagePreferenceTest.php`

- [ ] **Step 1: Import the completed catalogue locally without activating Portuguese**

Run:

```bash
php artisan translations:sync
php artisan translations:catalogue:import --mode=authoritative
```

Expected: all 2,286 catalogue rows import, `pt_BR` is populated in `language_lines`, and `supported_locales.pt_BR.is_active` remains false.

- [ ] **Step 2: Temporarily activate `pt_BR` only in the local Admin Languages resource and review representative screens**

Review the language selector, account, settings, ingredients list/editor, workbench, production bench, media library, an authentication error, and a destructive confirmation. Verify no `:placeholder` renders literally, no English source is mixed into the interface, and platform ingredient names/guidance remain English by design.

- [ ] **Step 3: Return `pt_BR` to inactive after local review**

The seeded production default remains inactive. Record corrections by editing the draft/catalogue only; never rely on an unexported local `language_lines` edit.

- [ ] **Step 4: Run final automated verification**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/LanguagePreferenceTest.php tests/Feature/NumberFormatPreferenceTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/AccountLocalizationTest.php tests/Feature/SettingsLocalizationTest.php tests/Feature/IngredientsIndexLocalizationTest.php tests/Feature/PackagingItemsIndexLocalizationTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/RecipesIndexLocalizationTest.php tests/Feature/ProductionBenchLocalizationTest.php
graphify update .
git diff --check
```

Expected: each command exits successfully.

- [ ] **Step 5: Review and commit the promoted Portuguese catalogue**

Run:

```bash
git diff -- database/seeders/data/interface-translations.json config/interface-translations.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/LanguagePreferenceTest.php
git add database/seeders/data/interface-translations.json config/interface-translations.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/InterfaceTranslationFoundationTest.php tests/Feature/LanguagePreferenceTest.php
git commit -m "feat: add Brazilian Portuguese interface catalogue"
```

Expected: the commit contains the complete reviewed `pt_BR` interface catalogue and promotion coverage, but no private draft, platform ingredient translation, API configuration, or locale activation.
