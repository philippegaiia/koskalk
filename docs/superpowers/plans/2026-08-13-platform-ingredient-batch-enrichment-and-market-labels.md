# Platform Ingredient Batch Enrichment and Market Labels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an Admin enter minimal English platform ingredients, localize the fixed COSING vocabulary once, maintain source-backed EU/US colour declaration names, and safely enrich many ingredients through deterministic JSONL export, dry-run review, and explicit import.

**Architecture:** Keep English ingredient records and `CI xxxxx` colour identities canonical. Put shared COSING translations in the existing interface catalogue, put regulatory colour declaration names in a normalized ingredient child table, and resolve printed names from the recipe's regulatory market. Implement enrichment as local console orchestration around a deterministic snapshot, strict result validator, field-level planner, and per-ingredient transactional applier that delegates relationship writes to the existing identity, function, translation, and data-entry services.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, PostgreSQL 17, SQLite test database, Pest 4, JSONL, Laravel translation loader.

---

## Implementation constraints

- The approved design is `docs/superpowers/specs/2026-08-13-platform-ingredient-batch-enrichment-and-vocabulary-localization-design.md`; implementation must not broaden its deferred specialist scope.
- Before each task, read `.ai/rules/index.md`, every rule matching the files in that task, and search `.ai/rules` for the task's domain terms. Use Laravel Boost `search-docs` before framework-facing changes.
- Preserve unrelated dirty-worktree changes. Stage only the exact files named by the current task and inspect `git diff --cached --stat` plus `git diff --cached --check` before committing.
- Do not add an application AI provider, queue, background job, or network call. Codex or another research model consumes the export and supplies JSONL outside the application.
- Do not reseed the platform ingredient catalogue or write private workspace ingredients. Every enrichment query and write must require `owner_type IS NULL` and `owner_id IS NULL`.
- Do not import SAP, iodine, INS, fatty-acid, allergen, IFRA, component, substance, authorization, permitted-use, purity, or restriction data.
- Keep `Ingredient::inci_name` equal to canonical `CI xxxxx` for colourants. A market label changes printed output only; it never records authorization.
- Support only colour-label market codes `eu` and `us`. Interface locale and regulatory market remain independent.
- Admin ingredient forms remain canonical English. Workspace paths localize categories, subcategories, and COSING functions.
- JSONL import is dry-run unless `--apply` is present. Existing non-empty values are preserved unless their field is named by a repeatable `--replace` option.
- Every applied result sets `requires_admin_review = true`. No import result is self-publishing.
- Use `DB::transaction($callback, attempts: 5)` and lock the ingredient before the final fingerprint check and write.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes, `vendor/bin/filacheck --fix` after `app/Filament` changes, and `graphify update .` after code changes are complete.

## Fixed JSONL contracts

The export writes one compact JSON object per line with these top-level keys in this order:

```json
{
  "format": "soapkraft-platform-ingredient-enrichment-input",
  "schema_version": 1,
  "catalog_key": "ADM-OLIVE-OIL",
  "source_fingerprint": "0000000000000000000000000000000000000000000000000000000000000000",
  "current": {},
  "vocabulary": {},
  "requested_output": {},
  "research_rules": {}
}
```

The research result accepted by the importer has this exact shape. Arrays may be empty, but unknown top-level or proposal fields fail validation so deferred data cannot leak into the core pass.

```json
{
  "format": "soapkraft-platform-ingredient-enrichment-result",
  "schema_version": 1,
  "catalog_key": "ADM-OLIVE-OIL",
  "source_fingerprint": "0000000000000000000000000000000000000000000000000000000000000000",
  "proposal": {
    "display_name": "Olive Oil",
    "inci_name": "OLEA EUROPAEA FRUIT OIL",
    "category": "lipids",
    "subcategory": "vegetable_oils",
    "saponification_name": "Saponified olive oil",
    "info_markdown": "## Overview\nOlive oil is a vegetable lipid used as an emollient.\n\n## Formulation use\nIt contributes slip and a rich skin feel in cosmetic formulas.\n\n## Soapmaking\nIn soap, it is commonly associated with conditioning and a milder lather profile.",
    "soapmaking_relevant": true,
    "identifiers": [
      {
        "scheme": "cas",
        "value": "8001-25-0",
        "is_primary": true,
        "source_name": "European Chemicals Agency",
        "source_url": "https://echa.europa.eu/",
        "checked_at": "2026-08-13"
      }
    ],
    "cosing_functions": [
      {
        "key": "emollient",
        "source_name": "European Commission CosIng",
        "source_url": "https://ec.europa.eu/growth/tools-databases/cosing/",
        "checked_at": "2026-08-13"
      }
    ],
    "translations": [
      {
        "locale": "de",
        "display_name": "Olivenöl",
        "saponification_name": "Verseiftes Olivenöl",
        "info_markdown": "## Überblick\nOlivenöl ist ein pflanzliches Lipid mit rückfettender Wirkung.\n\n## Verwendung in Formulierungen\nEs verleiht kosmetischen Formulierungen Gleitfähigkeit und ein reichhaltiges Hautgefühl.\n\n## Seifenherstellung\nIn Seife wird es häufig mit Pflege und einem milderen Schaumprofil verbunden."
      },
      {
        "locale": "es",
        "display_name": "Aceite de oliva",
        "saponification_name": "Aceite de oliva saponificado",
        "info_markdown": "## Descripción general\nEl aceite de oliva es un lípido vegetal emoliente.\n\n## Uso en formulación\nAporta deslizamiento y una sensación rica en fórmulas cosméticas.\n\n## Elaboración de jabón\nEn jabón suele asociarse con acondicionamiento y una espuma más suave."
      },
      {
        "locale": "fr",
        "display_name": "Huile d’olive",
        "saponification_name": "Huile d’olive saponifiée",
        "info_markdown": "## Vue d’ensemble\nL’huile d’olive est un lipide végétal émollient.\n\n## Utilisation en formulation\nElle apporte de la glisse et un toucher riche.\n\n## Savonnerie\nElle est souvent associée à un savon doux et conditionnant."
      },
      {
        "locale": "it",
        "display_name": "Olio d’oliva",
        "saponification_name": "Olio d’oliva saponificato",
        "info_markdown": "## Panoramica\nL’olio d’oliva è un lipide vegetale emolliente.\n\n## Uso in formulazione\nDona scorrevolezza e una sensazione ricca alle formule cosmetiche.\n\n## Saponificazione\nNel sapone è spesso associato a delicatezza e a una schiuma più morbida."
      },
      {
        "locale": "nl",
        "display_name": "Olijfolie",
        "saponification_name": "Verzeepte olijfolie",
        "info_markdown": "## Overzicht\nOlijfolie is een plantaardig lipide met een verzachtende werking.\n\n## Gebruik in formules\nHet geeft cosmetische formules glans en een rijk huidgevoel.\n\n## Zeepmaken\nIn zeep wordt het vaak geassocieerd met verzorging en een milder schuimprofiel."
      },
      {
        "locale": "pt_BR",
        "display_name": "Óleo de oliva",
        "saponification_name": "Óleo de oliva saponificado",
        "info_markdown": "## Visão geral\nO óleo de oliva é um lipídio vegetal emoliente.\n\n## Uso em formulações\nEle proporciona deslizamento e sensorial rico em fórmulas cosméticas.\n\n## Sabonete artesanal\nNo sabonete, costuma ser associado a condicionamento e a uma espuma mais suave."
      }
    ],
    "market_labels": []
  },
  "evidence": [
    {
      "field": "proposal.inci_name",
      "source_name": "European Commission CosIng",
      "source_url": "https://ec.europa.eu/growth/tools-databases/cosing/",
      "checked_at": "2026-08-13"
    }
  ],
  "confidence": "high",
  "warnings": [],
  "unresolved_questions": []
}
```

For colourants, `proposal.inci_name` must match `^CI [0-9]{5}$`, and `market_labels` contains both rows:

```json
[
  {
    "market_code": "eu",
    "declaration_name": "CI 77491",
    "source_name": "European Commission",
    "source_url": "https://single-market-economy.ec.europa.eu/sectors/cosmetics/cosmetic-ingredient-database/cosing-glossary-ingredients_en",
    "reviewed_at": "2026-08-13",
    "effective_from": null,
    "effective_until": null
  },
  {
    "market_code": "us",
    "declaration_name": "Iron Oxides",
    "source_name": "U.S. Food and Drug Administration",
        "source_url": "https://www.ecfr.gov/current/title-21/chapter-I/subchapter-A/part-73/subpart-C/section-73.2250",
    "reviewed_at": "2026-08-13",
    "effective_from": null,
    "effective_until": null
  }
]
```

The configured target locales are read from `config('interface-translations.catalogue_locales')`, currently `de`, `es`, `fr`, `it`, `nl`, and `pt_BR`. Every result must contain exactly one translation row for every configured target locale.

## Task 1: Localize the shared COSING function vocabulary

**Files:**

- Modify: `app/Enums/IngredientCategory.php`
- Modify: `app/Enums/IngredientSubcategory.php`
- Modify: `app/Models/IngredientFunction.php`
- Modify: `app/Services/IngredientClassificationPromptBuilder.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/IngredientTaxonomyLocalizationTest.php`
- Modify: `tests/Feature/IngredientClassificationPromptBuilderTest.php`
- Modify: `tests/Feature/IngredientDataEntryServiceTest.php`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Add failing vocabulary completeness and resolver tests**

Extend `IngredientTaxonomyLocalizationTest` to seed `IngredientFunctionSeeder` and assert that every active function key owns non-empty English keys:

```text
ingredients.functions.{key}.label
ingredients.functions.{key}.description
```

For each key, assert a non-empty row for all six configured locales in `interface-translations.json`. Add model tests proving `localizedName('fr')` and `localizedDescription('fr')` use the database-backed interface translation after the translation catalogue is seeded, and fall back to canonical `name`/`description` for an unsupported locale or missing key.

Add prompt tests proving a French `responseLocale` produces French taxonomy and function labels while retaining the exact category, subcategory, and function backing values. Add data-entry tests proving verified COSING names are localized in workspace state. Keep the Filament Admin schema's function options canonical English.

- [ ] **Step 2: Run the focused tests and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: failures for missing `ingredients.functions.*` keys and missing localized resolvers.

- [ ] **Step 3: Implement explicit locale-aware vocabulary resolvers**

Add `localizedLabel(?string $locale = null)` and `localizedDescription(?string $locale = null)` to both taxonomy enums; `getLabel()`/`getDescription()` delegate to those methods with the current locale. Add these methods to `IngredientFunction`:

```php
public function localizedName(?string $locale = null): string;
public function localizedDescription(?string $locale = null): string;
```

They resolve `ingredients.functions.{$this->key}.label` and `.description` using the requested locale, then fall back to canonical database values if the translator returns the key or a blank value.

Change `IngredientClassificationPromptBuilder` to pass `$input->responseLocale` to all three resolver types. Change `IngredientDataEntryService::formData()` to map verified functions through `localizedName()` instead of plucking `ingredient_functions.name`. Change the workspace `IngredientEditor` additional-function options to load active functions in stable `sort_order`, then map IDs to `localizedName()`.

- [ ] **Step 4: Add the reviewed English and six-locale vocabulary**

Copy each canonical English function `name` and `description` from `IngredientFunctionSeeder` into `lang/en/ingredients.php` under `functions.{key}.label` and `.description`. Add exactly 130 sorted `ingredients` rows to `interface-translations.json`, each containing non-empty `de`, `es`, `fr`, `it`, `nl`, and `pt_BR` text. Keep function keys, COSING evidence, and assignment pivots untranslated.

Run the catalogue test before accepting the data; it is the completeness gate for all 780 non-English values.

- [ ] **Step 5: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientCategory.php app/Enums/IngredientSubcategory.php app/Models/IngredientFunction.php app/Services/IngredientClassificationPromptBuilder.php app/Services/IngredientDataEntryService.php app/Livewire/Dashboard/IngredientEditor.php lang/en/ingredients.php database/seeders/data/interface-translations.json tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git diff --cached --check
git commit -m "feat: localize COSING function vocabulary"
```

## Task 2: Add normalized EU/US ingredient market labels

**Files:**

- Create: `app/Enums/IngredientLabelMarket.php`
- Create: `app/Models/IngredientMarketLabel.php`
- Create: `database/factories/IngredientMarketLabelFactory.php`
- Create: `database/migrations/2026_08_13_120000_create_ingredient_market_labels_table.php`
- Create: `app/Services/IngredientMarketLabelService.php`
- Modify: `app/Models/Ingredient.php`
- Create: `tests/Feature/IngredientMarketLabelTest.php`

- [ ] **Step 1: Add failing schema and service-contract tests**

Test `Eu = 'eu'` and `Us = 'us'`, casts, the `Ingredient::marketLabels()` relationship, one row per ingredient/market, source and declaration requirements, date ordering, platform-only enforcement, colourant-only enforcement, cascade deletion, and `reviewed_by_user_id` becoming null when its Admin is deleted.

Test that neither the table nor model contains `is_authorized`, `is_permitted`, usage conditions, or restriction fields. This relation records a printed name and source only.

- [ ] **Step 2: Run the focused test and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientMarketLabelTest.php
```

- [ ] **Step 3: Generate and implement the model foundation**

```bash
php artisan make:model IngredientMarketLabel --factory --no-interaction
php artisan make:migration create_ingredient_market_labels_table --no-interaction
php artisan make:class Services/IngredientMarketLabelService --no-interaction
```

Rename the migration to the exact path above and implement:

```php
Schema::create('ingredient_market_labels', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->string('market_code', 16);
    $table->string('declaration_name');
    $table->string('source_name');
    $table->text('source_url');
    $table->date('effective_from')->nullable();
    $table->date('effective_until')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->unique(['ingredient_id', 'market_code']);
    $table->index('market_code');
});
```

The migration `down()` drops only `ingredient_market_labels`. Use `#[Fillable]`, enum/date casts, `HasFactory`, and explicit `BelongsTo` relations. Add an ordered `HasMany` relationship to `Ingredient`.

- [ ] **Step 4: Implement one validation and persistence boundary**

Expose:

```php
/** @return array<int, array<string, mixed>> */
public function formData(Ingredient $ingredient): array;

/** @param array<int, array<string, mixed>> $rows */
public function replaceReviewed(Ingredient $ingredient, array $rows, User $actor): void;

/** @param array<int, array<string, mixed>> $rows */
public function mergeImported(Ingredient $ingredient, array $rows): void;

/** @param array<int, array<string, mixed>> $rows */
public function replaceImported(Ingredient $ingredient, array $rows): void;
```

All three write methods validate a platform-owned `IngredientCategory::Colourants` record, only `eu`/`us`, distinct markets, non-blank exact declaration, HTTP(S) source URL, source name, valid nullable dates, and `effective_until >= effective_from`. `replaceReviewed()` reconciles the complete submitted EU/US set and stamps actor/review time. `mergeImported()` uses `updateOrCreate()` for only supplied markets and never deletes an omitted row. `replaceImported()` reconciles only the supported EU/US rows, leaving any future-market rows untouched. Reject a US row whose normalized declaration matches `^CI\s*[0-9]{5}$`.

- [ ] **Step 5: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/IngredientMarketLabelTest.php
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientLabelMarket.php app/Models/IngredientMarketLabel.php app/Models/Ingredient.php app/Services/IngredientMarketLabelService.php database/factories/IngredientMarketLabelFactory.php database/migrations/2026_08_13_120000_create_ingredient_market_labels_table.php tests/Feature/IngredientMarketLabelTest.php
git diff --cached --check
git commit -m "feat: add ingredient market label records"
```

## Task 3: Add the Admin Market colour labels action

**Files:**

- Modify: `app/Filament/Resources/Ingredients/Pages/EditIngredient.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`

- [ ] **Step 1: Add failing Filament action tests**

Test that `marketColourLabels` is visible only for editable platform colourants, loads existing EU/US rows, rejects duplicate markets or source-less values, saves through `IngredientMarketLabelService`, and leaves `inci_name = 'CI 77491'` unchanged after the US declaration becomes `Iron Oxides`. Assert the action is hidden for non-colourants and private ingredients.

- [ ] **Step 2: Run the focused test and confirm RED**

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter="market colour labels"
```

- [ ] **Step 3: Implement the header action**

Add `Action::make('marketColourLabels')` before delete. Use a modal `Repeater::make('market_labels')` with a required `Select` backed by `IngredientLabelMarket`, plus `TextInput` fields for declaration/source name/source URL and nullable `DatePicker` fields for effective dates. Pre-fill through `IngredientMarketLabelService::formData()` and save through `replaceReviewed()` with the authenticated Admin.

Use the heading **Market colour labels** and helper copy stating that the action changes printed names only and does not establish authorization. Do not add these rows to the main ingredient form state.

- [ ] **Step 4: Re-run, check Filament, format, and commit**

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php --filter="market colour labels"
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
git add app/Filament/Resources/Ingredients/Pages/EditIngredient.php tests/Feature/Filament/CatalogResourcesTest.php
git diff --cached --check
git commit -m "feat: manage colour labels by market"
```

## Task 4: Resolve printed colour names from the recipe market

**Files:**

- Create: `app/Services/IngredientDeclarationNameResolver.php`
- Modify: `app/Services/InciGenerationService.php`
- Modify: `tests/Feature/InciGenerationPreviewTest.php`

- [ ] **Step 1: Add failing market-resolution tests**

Add focused tests proving:

- non-colourants print canonical `inci_name` in EU and US;
- EU colourants use an effective explicit EU row when present;
- EU colourants without a row fall back to canonical `CI xxxxx`;
- US colourants print the effective source-backed US declaration;
- US colourants with no effective row throw a `ValidationException` naming the ingredient and `market_labels.us`;
- a bare-CI US row cannot reach output;
- switching `regulatory_regime` from `eu` to `us_mocra_preview` changes only printed output, not canonical `inci_name`;
- market labels are used for both standard and incorporated-ingredient variants;
- the existing batched ingredient graph query test does not add an N+1 query.

- [ ] **Step 2: Run the focused test and confirm RED**

```bash
php artisan test --compact tests/Feature/InciGenerationPreviewTest.php --filter="colour|market label|ingredient graph"
```

- [ ] **Step 3: Implement the resolver**

Generate the service:

```bash
php artisan make:class Services/IngredientDeclarationNameResolver --no-interaction
```

Expose:

```php
public function resolve(Ingredient $ingredient, string $marketCode, ?CarbonImmutable $onDate = null): ?string;
```

Return canonical `inci_name` for non-colourants. For colourants, choose the one row whose `market_code` matches and whose effective dates contain `$onDate` (default today). EU falls back to canonical `CI xxxxx`; US without an effective, non-bare-CI row throws a field-addressable `ValidationException`. Do not query inside the resolver: require the `marketLabels` relation to be eager loaded and read it from the model collection.

- [ ] **Step 4: Integrate the resolver into every printed ingredient path**

Inject the resolver into `InciGenerationService`. Eager-load `marketLabels` in the existing `IngredientFormulaContextResolver::resolve()` relation list supplied by `generate()`.

Add `market_code` to both branches of `declarationRuleState()`: use `RegulatoryRegime::market_code` when a regime resolves, otherwise use `eu` for the default EU regime and the normalized regime code for other unresolved values. Pass the market code into `ingredientListLabel()` and `incorporatedIngredientLabel()`, replacing their direct reads of `$ingredient->inci_name`. Preserve soap-specific names for saponified oils; only their regular fallback uses the market resolver.

- [ ] **Step 5: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/InciGenerationPreviewTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientDeclarationNameResolver.php app/Services/InciGenerationService.php tests/Feature/InciGenerationPreviewTest.php
git diff --cached --check
git commit -m "feat: resolve colour declarations by recipe market"
```

## Task 5: Define deterministic enrichment snapshots and strict result validation

**Files:**

- Create: `config/ingredient-enrichment.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentSnapshotBuilder.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentJsonl.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- Create: `tests/Feature/IngredientEnrichmentValidationTest.php`

- [ ] **Step 1: Add failing snapshot and validator tests**

Test that the same ingredient state serializes identically and produces the same SHA-256 fingerprint regardless of relationship retrieval order. Changing any supported canonical field, identifier, verified COSING assignment, translation, or market label changes the fingerprint; changing deferred SAP/allergen/fatty-acid data does not.

Test line-numbered malformed JSON, unsupported format/version, unknown fields, duplicate `catalog_key`, private/nonexistent ingredients, stale fingerprints, unknown categories/subcategories/functions/identifier schemes/locales/markets, incompatible subcategories, missing locales, invalid evidence URLs/dates, duplicate rows, missing guidance headings, Soapmaking heading mismatch, colour/non-colour CI violations, missing EU/US colour rows, bare-CI US declarations, and any deferred proposal field.

- [ ] **Step 2: Run the test and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentValidationTest.php
```

- [ ] **Step 3: Add the bounded configuration and deterministic snapshot**

`config/ingredient-enrichment.php` contains:

```php
return [
    'input_format' => 'soapkraft-platform-ingredient-enrichment-input',
    'result_format' => 'soapkraft-platform-ingredient-enrichment-result',
    'schema_version' => 1,
    'default_export_path' => 'storage/app/private/ingredient-enrichment/platform-ingredients.jsonl',
    'market_codes' => ['eu', 'us'],
    'guidance' => [
        'minimum_words' => 80,
        'maximum_words' => 220,
        'required_headings' => ['Overview', 'Formulation use'],
        'soapmaking_heading' => 'Soapmaking',
    ],
];
```

`IngredientEnrichmentSnapshotBuilder::build(Ingredient $ingredient)` returns normalized arrays for supported canonical fields, identifiers, aliases as read-only context, COSING assignments with evidence, translations, and market labels. Sort every child collection by its stable key, recursively sort associative keys before encoding, encode with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, and hash that canonical JSON with SHA-256.

Read target locales directly from `config('interface-translations.catalogue_locales')` so the enrichment pipeline cannot drift from the authoritative interface catalogue. `isIncomplete()` returns true when INCI, compatible subcategory, guidance, any target locale's display name/guidance, or a required colour market row is missing. When `source_data.enrichment.core.result_fingerprint` exists, it also returns true if the current snapshot differs from that value. A missing enrichment fingerprint alone does not make a structurally complete ingredient incomplete, and a non-colourant can be complete when it legitimately has no verified COSING function.

- [ ] **Step 4: Implement strict JSONL parsing and result validation**

`IngredientEnrichmentJsonl` reads line-by-line, ignores only blank lines, and returns line number plus decoded object or a line error. It never silently drops malformed lines.

`IngredientEnrichmentResultValidator` validates the fixed contract at the top of this plan. Use exact allow-lists for object keys. Require all configured locales exactly once, evidence for INCI and every identifier/function/market row, official COSING source URLs on `ec.europa.eu` or `single-market-economy.ec.europa.eu`, HTTP(S) sources, ISO dates, recognized enum values, and valid category/subcategory pairs. Validate guidance section order; word counts outside 80–220 are warnings, while missing headings are errors. Require `## Soapmaking` exactly when `soapmaking_relevant` is true. Unsupported claims remain an explicit Admin editorial-review responsibility rather than being guessed by a phrase filter.

Accept the source fingerprint when it equals the current snapshot. Also recognize an idempotent replay when it equals `source_data.enrichment.core.source_fingerprint` and the current snapshot equals `source_data.enrichment.core.result_fingerprint`; all other mismatches are stale.

- [ ] **Step 5: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/IngredientEnrichmentValidationTest.php
vendor/bin/pint --dirty --format agent
git add config/ingredient-enrichment.php app/Services/IngredientEnrichment/IngredientEnrichmentSnapshotBuilder.php app/Services/IngredientEnrichment/IngredientEnrichmentJsonl.php app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php tests/Feature/IngredientEnrichmentValidationTest.php
git diff --cached --check
git commit -m "feat: validate ingredient enrichment results"
```

## Task 6: Export incomplete platform ingredients for external research

**Files:**

- Create: `app/Services/IngredientEnrichment/ExportPlatformIngredientEnrichment.php`
- Create: `app/Console/Commands/ExportPlatformIngredientEnrichmentCommand.php`
- Create: `tests/Feature/PlatformIngredientEnrichmentExportTest.php`

- [ ] **Step 1: Add failing export service and command tests**

Cover deterministic `catalog_key` ordering, unchanged byte-for-byte output, stable fingerprints, incomplete-only default selection, `--include-complete`, repeatable `--catalog-key`, explicit keys being exported even when complete, unknown keys failing clearly, private ingredient exclusion even when explicitly named, parent directory creation, custom path support, and the command's record count/SHA-256 output.

Assert each export line includes current state, existing translations and market labels, allowed category plus only compatible subcategories, all active COSING keys, identifier schemes, configured locales, EU/US markets, requested result fields, guidance contract, primary-source rules, and the fixed result format/version.

- [ ] **Step 2: Run the export test and confirm RED**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentExportTest.php
```

- [ ] **Step 3: Implement the exporter and command**

Generate the command:

```bash
php artisan make:command ExportPlatformIngredientEnrichmentCommand --no-interaction
```

Use:

```php
#[Signature('ingredients:enrichment:export
    {--path= : Output path relative to the project root, or an absolute path}
    {--catalog-key=* : Export these platform catalog keys}
    {--include-complete : Include structurally complete platform ingredients}')]
```

Default to `config('ingredient-enrichment.default_export_path')`. Query only platform ingredients and eager-load every snapshot relation. Sort by `catalog_key`, encode each line compactly with a single trailing newline, write atomically through a temporary file in the target directory, then rename over the target. Return record count, absolute path, and file SHA-256 from the service; keep console formatting in the command.

- [ ] **Step 4: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentExportTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/ExportPlatformIngredientEnrichment.php app/Console/Commands/ExportPlatformIngredientEnrichmentCommand.php tests/Feature/PlatformIngredientEnrichmentExportTest.php
git diff --cached --check
git commit -m "feat: export platform ingredients for enrichment"
```

## Task 7: Build the field-level dry-run planner

**Files:**

- Create: `app/Enums/IngredientEnrichmentReplaceField.php`
- Create: `app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php`
- Create: `app/Console/Commands/ImportPlatformIngredientEnrichmentCommand.php`
- Create: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`

- [ ] **Step 1: Add failing dry-run and conflict-policy tests**

Assert the import command is dry-run by default and produces no database changes. Its table must show `catalog_key`, field path, decision (`new`, `preserved`, `replace`, `unchanged`, `warning`, `error`), current value, and proposed value.

Test all repeatable replacement values:

```text
display_name
inci_name
category
subcategory
saponification_name
info_markdown
identifiers
cosing_functions
translations
market_labels
```

Unknown replacement values fail before reading records. Existing non-empty scalar values and per-locale translated fields are preserved by default. Identifiers, verified COSING functions, translations, and market labels merge without deletion by default. Their explicit replacement mode reconciles only that collection. If a proposed category correction is preserved, a proposed subcategory incompatible with the effective stored category is also preserved and reported with a warning; applying both requires both `--replace=category` and `--replace=subcategory`.

Test that malformed, duplicate, stale, private, and invalid rows appear as errors with line/catalog context, valid unrelated rows still receive a plan, and any error makes the command exit unsuccessfully.

- [ ] **Step 2: Run the import test and confirm RED**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php --filter="dry run|preserve|replace|invalid"
```

- [ ] **Step 3: Implement replacement parsing and planning**

Create a string-backed PascalCase enum for the ten values above. `IngredientEnrichmentPlanner` accepts a validated result plus a set of enum cases and returns a serializable plan containing the locked target ID, expected fingerprint, effective proposed state, field decisions, warnings, and errors. It must not write.

Scalar decisions are field-by-field. Translation decisions are locale-and-field specific even though `--replace=translations` enables all translated field replacements. Collection comparisons use normalized stable keys, not database IDs or input order. Preserve source-backed rows exactly when no replacement was requested.

- [ ] **Step 4: Implement the dry-run command shell**

Generate the command and use:

```php
#[Signature('ingredients:enrichment:import
    {path : Research-result JSONL path relative to the project root, or an absolute path}
    {--apply : Apply valid plans; omission is a read-only dry run}
    {--replace=* : Explicit field or collection allowed to replace reviewed data}')]
```

At this task, `--apply` reports that applying is not yet available and exits unsuccessfully; the next task supplies it. Dry run parses every line, detects duplicate catalog keys across the file, validates, plans, prints the field-level table, then prints totals for `planned`, `unchanged`, `warned`, and `failed`.

- [ ] **Step 5: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php --filter="dry run|preserve|replace|invalid"
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientEnrichmentReplaceField.php app/Services/IngredientEnrichment/IngredientEnrichmentPlanner.php app/Console/Commands/ImportPlatformIngredientEnrichmentCommand.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
git diff --cached --check
git commit -m "feat: preview ingredient enrichment imports"
```

## Task 8: Preserve per-function COSING evidence during batch merges

**Files:**

- Modify: `app/Services/IngredientFunctionAssignmentService.php`
- Modify: `tests/Feature/IngredientFunctionAssignmentTest.php`

- [ ] **Step 1: Add failing evidence merge and replacement tests**

Test that a proposed new function can merge into existing COSING assignments without changing the existing function's source reference/date, that a manual assignment promoted to verified COSING receives the new official evidence, that replace mode removes only omitted COSING assignments while preserving manual/inherited rows, and that each proposed function retains its own source URL and checked date.

- [ ] **Step 2: Run the service test and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientFunctionAssignmentTest.php
```

- [ ] **Step 3: Add row-aware COSING APIs without breaking callers**

Expose:

```php
/** @param array<int, array{key:string, source_reference:string, source_checked_at:CarbonImmutable}> $rows */
public function mergeCosIng(Ingredient $ingredient, array $rows): void;

/** @param array<int, array{key:string, source_reference:string, source_checked_at:CarbonImmutable}> $rows */
public function replaceCosIng(Ingredient $ingredient, array $rows): void;
```

Resolve all active keys in one query. Preserve every non-COSING pivot in both modes. Merge mode preserves existing COSING rows and their evidence unless the same key is explicitly proposed. Replace mode removes omitted COSING rows. Keep the existing `syncCosIng()` method as a compatibility wrapper around `replaceCosIng()` using its shared reference/date.

- [ ] **Step 4: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/IngredientFunctionAssignmentTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientFunctionAssignmentService.php tests/Feature/IngredientFunctionAssignmentTest.php
git diff --cached --check
git commit -m "feat: preserve COSING assignment evidence"
```

## Task 9: Apply valid enrichment records atomically and idempotently

**Files:**

- Create: `app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php`
- Modify: `app/Console/Commands/ImportPlatformIngredientEnrichmentCommand.php`
- Modify: `app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php`
- Modify: `tests/Feature/PlatformIngredientEnrichmentImportTest.php`

- [ ] **Step 1: Add failing apply, atomicity, and idempotency tests**

Test a full valid apply, explicit scalar/collection replacement, default merge/preservation, `requires_admin_review = true`, `taxonomy_source = 'external_research_pending_review'` only when taxonomy actually changes, and persisted enrichment metadata under `source_data.enrichment.core`:

```php
[
    'schema_version' => 1,
    'confidence' => 'high',
    'warnings' => [],
    'unresolved_questions' => [],
    'source_fingerprint' => 'd66f6f0cc650e7c96ecbe6b16d4939fe53af9127a211d5973ad51c39dd5718f4',
    'result_fingerprint' => '77fa7d702701a693d25b70a36e60836607ff9f9ab17b786cbecabf96d0afde13',
    'applied_at' => '2026-08-13T14:30:00+02:00',
]
```

Prove that identity writes use `IngredientDataEntryService`/`IngredientIdentitySynchronizer`, COSING writes use the row-aware assignment service, translations use `IngredientTranslationService` with a complete merged locale set, and market rows use `IngredientMarketLabelService`.

Add tests for a fingerprint becoming stale between planning and locked apply, a relationship failure rolling back the ingredient's scalar changes, one invalid record not preventing another valid record from applying, repeated application producing `unchanged`, and command totals/exit status for `applied`, `unchanged`, `skipped`, `warned`, and `failed`.

- [ ] **Step 2: Run apply tests and confirm RED**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php --filter="apply|atomic|stale|idempotent"
```

- [ ] **Step 3: Implement the transactional applier**

Generate the class:

```bash
php artisan make:class Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment --no-interaction
```

For each valid plan:

1. Begin `DB::transaction($callback, attempts: 5)`.
2. Query the platform ingredient `lockForUpdate()` by target ID.
3. Rebuild and compare its fingerprint; throw a record-level stale exception on mismatch.
4. Start from `IngredientDataEntryService::formData()`, extract only `current_version`, CAS/EC, `additional_identifiers`, and `aliases`, merge the planned canonical/identity values, and pass only those keys to `syncCurrentData()`. Omitting SAP, fatty-acid, allergen, manual-function, component, and substance keys guarantees those specialist collections are not resynchronized; retaining aliases guarantees identifier replacement cannot erase them.
5. Save category, compatible subcategory, guidance, review flags, and supported canonical fields.
6. Call `mergeCosIng()` or `replaceCosIng()` according to the plan.
7. Merge existing translation rows field-by-field, then pass the complete set of existing and proposed locales to `IngredientTranslationService::sync()`. In explicit translation replacement mode, replace the three fields for configured target locales while retaining rows for any other existing locale, so the service's reconciliation behavior cannot delete a future locale.
8. Call `IngredientMarketLabelService::mergeImported()` by default or `replaceImported()` in explicit replacement mode.
9. Preserve unrelated `source_data`, write the enrichment metadata shown above, recompute `result_fingerprint`, and commit.

Catch record-level exceptions in the command, print the error, continue with unrelated records, and return failure when any record failed. Do not apply a result with unresolved validation errors; non-empty `unresolved_questions` is a warning and still leaves `requires_admin_review = true`.

- [ ] **Step 4: Wire explicit apply and verify reruns**

Remove the temporary `--apply` rejection from Task 7. The same validated plan must drive dry-run and apply, so printed replacement decisions match writes exactly. On a second import, allow the original research fingerprint only when `source_data.enrichment.core.source_fingerprint` matches it and the current fingerprint equals the stored `result_fingerprint`; classify that record as `unchanged` instead of stale. Any Admin edit after apply changes the current fingerprint and requires a new export.

- [ ] **Step 5: Re-run, format, and commit**

```bash
php artisan test --compact tests/Feature/PlatformIngredientEnrichmentImportTest.php
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php app/Services/IngredientEnrichment/IngredientEnrichmentResultValidator.php app/Console/Commands/ImportPlatformIngredientEnrichmentCommand.php tests/Feature/PlatformIngredientEnrichmentImportTest.php
git diff --cached --check
git commit -m "feat: apply platform ingredient enrichment batches"
```

## Task 10: Verify the complete workflow and repository graph

**Files:**

- Modify only if a failure requires a scoped correction: files already listed in Tasks 1–9

- [ ] **Step 1: Run all focused feature tests together**

```bash
php artisan test --compact tests/Feature/IngredientTaxonomyLocalizationTest.php tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/IngredientMarketLabelTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InciGenerationPreviewTest.php tests/Feature/IngredientEnrichmentValidationTest.php tests/Feature/PlatformIngredientEnrichmentExportTest.php tests/Feature/PlatformIngredientEnrichmentImportTest.php tests/Feature/IngredientFunctionAssignmentTest.php
```

- [ ] **Step 2: Run static framework checks and formatting**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
```

If either formatter changes files, rerun the focused tests.

- [ ] **Step 3: Exercise deterministic export against the development database**

```bash
php artisan ingredients:enrichment:export --path=storage/app/private/ingredient-enrichment/verification-input.jsonl
```

Run the command twice and compare its reported SHA-256 value. An unchanged development database must produce the same hash. Do not add the generated JSONL file to Git. Dry-run import behavior is already exercised with complete schema-valid fixtures in `PlatformIngredientEnrichmentImportTest`; a real manual import starts only after a researched result exists.

- [ ] **Step 4: Refresh the graph and inspect the final diff**

```bash
graphify update .
git status --short
git diff --check
```

Confirm the graph shows `InciGenerationService` depending on the declaration resolver and the importer depending on existing ingredient domain services, rather than direct relationship-table writes.

- [ ] **Step 5: Confirm the branch is ready for review**

Run `git status --short` once more. Never stage generated JSONL or unrelated dirty files. If verification exposes a defect, return to its owning task, add a regression assertion to that task's named test file, implement the smallest correction, rerun the focused suite, and commit only those exact files with a message describing the corrected behavior.

## Operational handoff after implementation

The repeatable development workflow is:

```bash
php artisan ingredients:enrichment:export
php artisan ingredients:enrichment:import storage/app/private/ingredient-enrichment/researched-results.jsonl
php artisan ingredients:enrichment:import storage/app/private/ingredient-enrichment/researched-results.jsonl --apply
```

For a reviewed field correction, repeat the exact allowed field option, for example:

```bash
php artisan ingredients:enrichment:import storage/app/private/ingredient-enrichment/researched-results.jsonl --replace=info_markdown --replace=translations
php artisan ingredients:enrichment:import storage/app/private/ingredient-enrichment/researched-results.jsonl --apply --replace=info_markdown --replace=translations
```

For a newly entered ingredient, rerun export; the incomplete-only default emits the new or edited platform record. Codex can research several exported lines at once and return one result JSON object per line. No per-ingredient copy/paste is required.
