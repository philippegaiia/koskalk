# Ingredient Identifiers, Aliases, and Substances Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace ingredient-level scalar CAS and EC columns with bounded typed identifiers, add localized searchable aliases, let workspaces maintain their copied ingredient substances, and make platform duplication produce a localized independent workspace ingredient with all agreed child data.

**Architecture:** Keep `Ingredient` as Koskalk's identity and store external identifiers and aliases as child records that inherit parent ownership. A single `IngredientIdentitySynchronizer` validates, normalizes, limits, selects primaries, and atomically replaces both collections. Existing admin and dashboard application services remain the authorization boundaries and pass plain form state into the synchronizer. Search uses a dedicated query/search-term service so platform and workspace tenancy rules remain consistent across HTTP, Livewire, and browser filtering.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, PostgreSQL 17, SQLite test database, Pest 4, Blade, Alpine.js, Laravel translation loader.

---

## Implementation constraints

- Read `.ai/rules/index.md`, every matching rule, and search `.ai/rules` before each task whose file scope expands.
- Use Laravel Boost `search-docs` before every framework-facing task. Confirm installed package versions before relying on an API.
- Preserve the existing dirty files outside this plan. Stage only the exact files listed in each commit step and inspect `git diff --cached --stat` before committing.
- Do not reseed the ingredient catalogue to migrate production data. The migration must backfill the catalogue and workspace ingredients already present.
- Do not correct questionable legacy CAS or EC digits. Split only obvious comma- or semicolon-separated lists, trim them, discard empty/normalized duplicates, and preserve every remaining value.
- Do not add `source_ingredient_id`, live inheritance, a global chemical registry, external API integration, or workspace overrides on shared platform ingredients.
- Do not alter CAS/EC fields that belong to `allergens` or `substance_catalog`; this plan removes the scalar columns only from `ingredients`.
- Keep the simple form fields **CAS number** and **EC number** as transient primary-identifier inputs. They are form state, not columns on `ingredients`.
- Workspace-owned ingredients allow 10 identifiers total and 5 aliases total. Platform ingredients allow 10 identifiers total and 5 aliases per locale, including `und`.
- `und` is the stored locale for language-neutral botanical aliases. Alias values are not globally unique and never auto-select an ingredient.
- New workspace substance rows use `concentration_source = supplier`; editing preserves existing `source_notes` and `source_data` that are not exposed by the simple workspace form.
- All new interface strings belong in `lang/en/ingredients.php` and the five-locale authoritative catalogue `database/seeders/data/interface-translations.json`.

## Task 1: Add typed identity tables and preserve legacy CAS/EC data

**Files:**

- Create: `app/Enums/IngredientIdentifierScheme.php`
- Create: `app/Enums/IngredientAliasKind.php`
- Create: `app/Models/IngredientIdentifier.php`
- Create: `app/Models/IngredientAlias.php`
- Create: `database/factories/IngredientIdentifierFactory.php`
- Create: `database/factories/IngredientAliasFactory.php`
- Create: `database/migrations/2026_08_12_120000_create_ingredient_identity_tables_and_backfill_identifiers.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `database/factories/IngredientFactory.php`
- Create: `tests/Feature/IngredientIdentitySchemaTest.php`
- Create: `tests/Feature/IngredientIdentityMigrationTest.php`

- [ ] **Step 1: Add failing enum, schema, relationship, and backfill tests**

Cover all four schemes and all four alias kinds, relationship casts, database uniqueness, and cascade deletion in `IngredientIdentitySchemaTest`.

In `IngredientIdentityMigrationTest`, start from the Task 1 schema, create an ordinary ingredient, explicitly run the new identity migration backward, populate the still-present scalar columns with whitespace, comma-separated duplicates, and questionable digits, then run the identity migration forward and assert its child rows. Restore the identity tables in a `finally` block so later tests always receive the expected schema.

```php
$ingredient = Ingredient::factory()->create();
$createIdentity = require database_path('migrations/2026_08_12_120000_create_ingredient_identity_tables_and_backfill_identifiers.php');

$createIdentity->down();

try {
    DB::table('ingredients')->where('id', $ingredient->id)->update([
        'cas_number' => ' 19856-23-6, 19856-23-6 ; 8001-25-00 ',
        'ec_number' => '243-378-4',
    ]);

    $createIdentity->up();

    expect(DB::table('ingredient_identifiers')->where('ingredient_id', $ingredient->id)->count())
        ->toBe(3);
} finally {
    if (! Schema::hasTable('ingredient_identifiers')) {
        $createIdentity->up();
    }
}
```

- [ ] **Step 2: Run the focused test and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientIdentitySchemaTest.php tests/Feature/IngredientIdentityMigrationTest.php
```

Expected: failures because the enums, models, relationships, and tables do not exist.

- [ ] **Step 3: Generate the models, factories, and migration, then implement the schema**

```bash
php artisan make:model IngredientIdentifier --factory --no-interaction
php artisan make:model IngredientAlias --factory --no-interaction
php artisan make:migration create_ingredient_identity_tables_and_backfill_identifiers --no-interaction
```

Rename the generated migration to the exact path listed above. Use these table invariants:

```php
Schema::create('ingredient_identifiers', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->string('scheme', 32);
    $table->string('value', 64);
    $table->string('normalized_value', 64);
    $table->boolean('is_primary')->default(false);
    $table->timestamps();

    $table->unique(['ingredient_id', 'scheme', 'normalized_value']);
    $table->index(['scheme', 'normalized_value']);
});

Schema::create('ingredient_aliases', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
    $table->string('locale', 16)->default('und');
    $table->string('name', 150);
    $table->string('normalized_name', 150);
    $table->string('kind', 32);
    $table->timestamps();

    $table->unique(['ingredient_id', 'locale', 'normalized_name']);
    $table->index(['locale', 'normalized_name']);
});
```

Backfill with `DB::table()` and migration-local normalization so the migration remains executable after application classes evolve. For each legacy field, split on `/[,;]+/u`, trim, preserve digits exactly, compare normalized values case-insensitively, and mark the first value per scheme primary. The `down()` method drops both tables.

Implement `IngredientIdentifierScheme` values `cas`, `ec`, `unii`, `echa_list` and `IngredientAliasKind` values `common`, `botanical`, `spelling`, `former`. Models use `#[Fillable]`, enum casts, `HasFactory`, explicit `BelongsTo`, and no duplicated workspace/owner fields. Add ordered `identifiers()` and `aliases()` `HasMany` relations to `Ingredient`.

- [ ] **Step 4: Re-run the schema test**

```bash
php artisan test --compact tests/Feature/IngredientIdentitySchemaTest.php tests/Feature/IngredientIdentityMigrationTest.php
```

Expected: pass on the project test database, including exact preservation of `8001-25-00`.

- [ ] **Step 5: Format and commit the identity foundation**

```bash
vendor/bin/pint --dirty --format agent
git add app/Enums/IngredientIdentifierScheme.php app/Enums/IngredientAliasKind.php app/Models/IngredientIdentifier.php app/Models/IngredientAlias.php app/Models/Ingredient.php database/factories/IngredientIdentifierFactory.php database/factories/IngredientAliasFactory.php database/factories/IngredientFactory.php database/migrations/2026_08_12_120000_create_ingredient_identity_tables_and_backfill_identifiers.php tests/Feature/IngredientIdentitySchemaTest.php tests/Feature/IngredientIdentityMigrationTest.php
git diff --cached --check
git commit -m "feat: add ingredient identifiers and aliases"
```

## Task 2: Centralize identity normalization, limits, and primary selection

**Files:**

- Create: `app/Services/IngredientIdentitySynchronizer.php`
- Create: `tests/Feature/IngredientIdentitySynchronizerTest.php`

- [ ] **Step 1: Add failing service tests for the complete write contract**

Test:

- normalization trims identifiers without changing digits;
- aliases collapse internal whitespace and compare case-insensitively;
- duplicate normalized rows fail validation before counting;
- an invalid scheme, kind, locale, unsafe character, or overlong value fails validation;
- workspace ingredients reject more than 10 identifiers or 5 aliases total;
- platform ingredients reject more than 10 identifiers or 5 aliases in one locale but accept 5 in each of two locales;
- the first value becomes primary when none is selected;
- two explicit primaries in one scheme fail;
- replacing/deleting a primary promotes the first submitted remaining value;
- a failed replacement leaves the old child rows unchanged.

```php
expect(fn () => $service->sync($workspaceIngredient, [
    'cas_number' => '19856-23-6',
    'ec_number' => null,
    'additional_identifiers' => [],
    'aliases' => collect(range(1, 6))->map(fn (int $number): array => [
        'locale' => 'en',
        'name' => "Alias {$number}",
        'kind' => 'common',
    ])->all(),
]))->toThrow(ValidationException::class, 'at most 5 alternative names');
```

- [ ] **Step 2: Run the service test and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientIdentitySynchronizerTest.php
```

- [ ] **Step 3: Implement one transactional synchronizer**

Generate the class with Artisan:

```bash
php artisan make:class Services/IngredientIdentitySynchronizer --no-interaction
```

Expose these concrete methods:

```php
/** @return array{cas_number:?string, ec_number:?string, additional_identifiers:array<int, array<string, mixed>>, aliases:array<int, array<string, mixed>>} */
public function formState(Ingredient $ingredient): array;

/** @param array<string, mixed> $state */
public function sync(Ingredient $ingredient, array $state): void;
```

`formState()` returns the primary CAS/EC values in the simple fields and every other value in `additional_identifiers`. `sync()` combines those fields, treats the simple CAS/EC value as primary unless an additional row for that scheme explicitly has `is_primary = true`, validates all state with Laravel's validator, then runs:

```php
DB::transaction(function () use ($ingredient, $identifiers, $aliases): void {
    $lockedIngredient = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
    $this->assertLimits($lockedIngredient, $identifiers, $aliases);
    $lockedIngredient->identifiers()->delete();
    $lockedIngredient->identifiers()->createMany($identifiers);
    $lockedIngredient->aliases()->delete();
    $lockedIngredient->aliases()->createMany($aliases);
}, attempts: 5);
```

Use `Str::of($value)->trim()` and `->squish()` for aliases. Identifier normalization is trim plus uppercase for UNII; it may normalize surrounding whitespace and Unicode dash variants to `-`, but it must never remove, add, or change digits. Validate `locale` as `und` or an existing `supported_locales.code`. Keep authorization out of this service: admin resource authorization and `UserIngredientAuthoringService` own that responsibility.

- [ ] **Step 4: Re-run the service test**

```bash
php artisan test --compact tests/Feature/IngredientIdentitySynchronizerTest.php
```

- [ ] **Step 5: Format and commit the synchronizer**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientIdentitySynchronizer.php tests/Feature/IngredientIdentitySynchronizerTest.php
git diff --cached --check
git commit -m "feat: synchronize bounded ingredient identity data"
```

## Task 3: Integrate identifiers, aliases, and substances into the shared data-entry service

**Files:**

- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `tests/Feature/IngredientDataEntryServiceTest.php`

- [ ] **Step 1: Add failing round-trip and preservation tests**

Add tests proving `formData()` returns `cas_number`, `ec_number`, `additional_identifiers`, `aliases`, and `substance_entries`; `syncCurrentData()` persists them; omitting one collection leaves it untouched; passing an empty collection clears it; and editing a simple substance row preserves hidden `source_notes`/`source_data` while a new row receives `concentration_source = supplier`.

```php
$saved = app(IngredientDataEntryService::class)->syncCurrentData($ingredient, [
    'current_version' => ['display_name' => 'Sodium levulinate'],
    'cas_number' => '19856-23-6',
    'additional_identifiers' => [[
        'scheme' => 'unii',
        'value' => 'VK3H1Z8Z6V',
        'is_primary' => true,
    ]],
    'aliases' => [[
        'locale' => 'en',
        'name' => 'Sodium 4-oxovalerate',
        'kind' => 'common',
    ]],
    'substance_entries' => [[
        'substance_id' => $substance->id,
        'concentration_percent' => 0.8,
    ]],
]);
```

- [ ] **Step 2: Run the focused test and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientDataEntryServiceTest.php
```

- [ ] **Step 3: Inject and call the synchronizer, then add substance synchronization**

Inject `IngredientIdentitySynchronizer` beside `IngredientFunctionAssignmentService`. Remove scalar CAS/EC from the `Ingredient::fill()` payload. Merge `$this->ingredientIdentitySynchronizer->formState($ingredient)` into `formData()` and call `sync()` only when at least one identity state key is present.

Add `substance_entries` using rows shaped as:

```php
[
    'substance_id' => $entry->substance_id,
    'concentration_percent' => $entry->concentration_percent === null
        ? null
        : (float) $entry->concentration_percent,
]
```

When synchronizing, validate unique existing `substance_catalog` IDs and concentration `nullable|numeric|min:0|max:100`. Before replacing rows, cache existing `concentration_source`, `source_notes`, and `source_data` by `substance_id`; restore them for retained rows and default only new rows to `supplier`, `null`, `null`. Wrap the full `syncCurrentData()` mutation in `DB::transaction(..., attempts: 5)` so identity, substances, and the existing child collections commit together. Return eager-loaded `identifiers`, `aliases`, and `substanceEntries.substance`.

- [ ] **Step 4: Re-run data-entry and compliance tests**

```bash
php artisan test --compact tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/SubstanceComplianceTest.php
```

- [ ] **Step 5: Format and commit the shared entry integration**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/IngredientDataEntryService.php app/Models/Ingredient.php tests/Feature/IngredientDataEntryServiceTest.php
git diff --cached --check
git commit -m "feat: manage identity and substances through ingredient entry"
```

## Task 4: Replace admin scalar inputs with reusable identity curation controls

**Files:**

- Create: `app/Forms/Components/IngredientIdentityFields.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientDataEntry.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`
- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`

- [ ] **Step 1: Add failing create/edit form tests**

Test both `CreateIngredient` and `EditIngredient` with unsaved identity state. Assert primary CAS/EC, an additional UNII, localized aliases in two locales, and substance rows are persisted. Assert the edit form hydrates them, rejects an eleventh identifier and a sixth alias in one platform locale, and the list table's view/edit modals do not expose page-only helper state.

- [ ] **Step 2: Run the focused Filament and translation tests and confirm RED**

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="ingredient|translation catalogue"
```

- [ ] **Step 3: Build and integrate the shared Filament field schema**

Create `IngredientIdentityFields::schema(bool $platform): array` in `app/Forms/Components`. It returns:

- optional `cas_number` and `ec_number` text inputs;
- an `additional_identifiers` repeater with enum-backed scheme select, value, and primary toggle, capped at 10 in the browser;
- an `aliases` repeater with locale (`und` plus supported locales), enum-backed kind, and name;
- translated remaining-capacity helper copy;
- a clear note that ECHA list numbers are not EC/EINECS numbers.

Use the schema from the admin Material Identity section. Add a simple `substance_entries` repeater to ingredient compliance with a searchable `Substance` select and optional 0–100 concentration; do not expose per-row source fields there.

Extend `InteractsWithIngredientDataEntry` to extract/unset `cas_number`, `ec_number`, `additional_identifiers`, `aliases`, and `substance_entries`. Keep the classification prompt helper visible only on full `CreateIngredient` and `EditIngredient` pages, not ListIngredients table modals.

Add translation keys under `ingredients.editor.identity.*` and `ingredients.editor.compliance.substances.*`. Add non-empty DE/ES/FR/IT/NL entries to the authoritative catalogue, keep catalogue byte ordering valid, and assert the exact keys are owned by the `ingredients` source.

- [ ] **Step 4: Run Filament checks and focused tests**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="ingredient|translation catalogue"
```

- [ ] **Step 5: Commit the admin curation flow**

```bash
git add app/Forms/Components/IngredientIdentityFields.php app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientDataEntry.php app/Filament/Resources/Ingredients/Schemas/IngredientForm.php tests/Feature/Filament/CatalogResourcesTest.php lang/en/ingredients.php database/seeders/data/interface-translations.json tests/Feature/InterfaceTranslationCatalogueTest.php
git diff --cached --check
git commit -m "feat: curate ingredient identities in admin"
```

## Task 5: Let workspaces edit identity, aliases, and restricted substances

**Files:**

- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php`
- Modify: `lang/en/ingredients.php`
- Modify: `database/seeders/data/interface-translations.json`

- [ ] **Step 1: Add failing workspace authorization and form tests**

Prove a workspace owner can create, hydrate, update, and clear identifiers, aliases, and substance concentrations on a workspace ingredient. Prove another workspace cannot mutate them and a platform ingredient remains read-only. Assert the browser limit is 5 aliases/10 identifiers but the service still rejects a forged oversized Livewire payload. Add render assertions that primary CAS/EC values appear in the ordinary identity summary and non-primary/UNII/ECHA values appear in an **Additional identifiers** disclosure.

- [ ] **Step 2: Run the focused tests and confirm RED**

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php --filter="identifier|alias|substance"
```

- [ ] **Step 3: Map workspace state through the existing authoring service**

Add empty `additional_identifiers`, `aliases`, and `substance_entries` arrays to `blankState()`. Continue exposing root `cas_number`/`ec_number` form state, but derive it from `IngredientDataEntryService::formData()`. Pass every identity/substance key to `syncCurrentData()` from `syncState()` and `createInlineComponent()`.

In `IngredientEditor`, reuse `IngredientIdentityFields::schema(platform: false)`. Make the compliance tab available to every ingredient, keep allergen/IFRA sections conditional on aromatic compliance, and add the simple substance repeater as an always-available section; restricted-substance composition is not an aromatic-only concern. The substance selector reads the curated `substance_catalog`; users choose a substance and an optional concentration but do not create global substances from this form.

Pass `IngredientIdentitySynchronizer::formState()` to `ingredient-editor.blade.php` for the read-only reference summary. Render primary CAS and EC first, then a compact disclosure for all remaining typed identifiers. Label ECHA list rows distinctly. Do not query child rows from Blade.

Use the existing `isEditableBy()` authorization before state reaches the synchronizer. Preserve the existing dot/ownership warning that marks workspace data as user-controlled.

- [ ] **Step 4: Add translations and run focused tests**

Add translated keys for alternative names, additional identifiers, capacities, substances, concentration, and server validation messages. Then run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php --filter="identifier|alias|substance|translation catalogue"
```

- [ ] **Step 5: Commit the workspace authoring flow**

```bash
git add app/Services/UserIngredientAuthoringService.php app/Livewire/Dashboard/IngredientEditor.php resources/views/livewire/dashboard/ingredient-editor.blade.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php lang/en/ingredients.php database/seeders/data/interface-translations.json
git diff --cached --check
git commit -m "feat: let workspaces manage ingredient identity and substances"
```

## Task 6: Make platform duplication localized and complete

**Files:**

- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
- Modify: `tests/Feature/IngredientsIndexDuplicationTest.php`

- [ ] **Step 1: Add failing duplication matrix tests**

Build one platform ingredient containing English base fields, French and German `IngredientTranslation` rows, neutral/English/French aliases, 10 identifiers, SAP/fatty acids, components, allergens, substances, functions with mixed assignment provenance, and IFRA limits.

Assert for a French user:

- copied base name, saponification name, and information use French;
- English is used independently for any untranslated field;
- all identifiers and primary flags are copied;
- aliases are selected in deterministic order: active locale, `und`, then English only when the active locale has none, normalized duplicates removed, maximum 5;
- substances and their source metadata are copied;
- all existing relationship copies remain independent;
- no `IngredientTranslation` rows and no source linkage are attached to the workspace copy;
- later edits on either source or copy do not affect the other.

- [ ] **Step 2: Run duplication tests and confirm RED**

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientsIndexDuplicationTest.php
```

- [ ] **Step 3: Localize before saving and copy every child relation**

Before saving the replicated ingredient, load the source translation candidates and set:

```php
$copy->display_name = $source->localizedDisplayName($user->locale);
$copy->saponification_name = $source->localizedSaponificationName($user->locale);
$copy->info_markdown = $source->localizedInfoMarkdown($user->locale);
```

Pass `$user->locale` to a deterministic alias-copy method and call `IngredientIdentitySynchronizer` with the selected alias and identifier state after the copy exists. Extend `deepCopyRelations()` to replicate `substanceEntries` including `concentration_source`, `source_notes`, and `source_data`. Remove the ordinary section-label inline comments while touching that method.

Keep ownership columns and `source_data.user_authoring` responsibility/trusted-SAP marker exactly as today. Do not add `source_ingredient_id`.

- [ ] **Step 4: Re-run duplication and regression tests**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientsIndexDuplicationTest.php tests/Feature/IngredientFormulaMutationServiceTest.php
```

- [ ] **Step 5: Commit complete localized duplication**

```bash
git add app/Services/UserIngredientAuthoringService.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientsIndexDuplicationTest.php
git diff --cached --check
git commit -m "feat: duplicate localized ingredient compliance data"
```

## Task 7: Search by aliases and every identifier without leaking workspace data

**Files:**

- Create: `app/Services/IngredientCatalogSearchService.php`
- Modify: `app/Http/Controllers/IngredientController.php`
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Modify: `app/Services/RecipeWorkbenchIngredientCatalogBuilder.php`
- Modify: `resources/js/recipe-workbench/catalog.js`
- Modify: `tests/Feature/PublicIngredientPagesTest.php`
- Modify: `tests/Feature/IngredientsIndexLocalizationTest.php`
- Modify: `tests/Feature/RecipeWorkbenchViewDataBuilderTest.php`
- Modify: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Add failing server and browser search tests**

Test localized display-name fallback, INCI, primary and alternate IDs, `und` aliases, active-locale aliases, English aliases only when the ingredient has no active-locale alias, and ambiguous aliases returning both candidates. Create the same workspace alias in two workspaces and prove each workspace sees only its own record plus accessible platform matches.

Add a Node contract test using the established `node --input-type=module -e` pattern to prove `filterIngredients()` matches normalized `search_terms` without changing the displayed `name`.

- [ ] **Step 2: Run the focused search tests and confirm RED**

```bash
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexLocalizationTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php --filter="search|alias|identifier"
```

- [ ] **Step 3: Implement one locale-aware search service**

Generate `IngredientCatalogSearchService` and give it two methods:

```php
public function apply(Builder $query, string $search, ?string $locale = null): Builder;

/** @return array<int, string> */
public function terms(Ingredient $ingredient, ?string $locale = null): array;
```

`apply()` only adds match predicates; callers retain their existing platform/accessibility scopes. Match base and translated display names, INCI, every identifier value, and eligible aliases. Eligible aliases are active locale candidates plus `und`; use English only when that ingredient has no alias in the active locale candidates. Do not use a global alias lookup or choose one record from ambiguous results.

Use the service in `IngredientController::searchPlatform()` and `IngredientsIndex::ingredientQuery()`. In `RecipeWorkbenchIngredientCatalogBuilder`, eager-load identifiers and eligible aliases, then add a normalized `search_terms` string array to each payload row. Update `filterIngredients()` to match `name`, `inci_name`, or any `search_terms` entry.

- [ ] **Step 4: Re-run search tests**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexLocalizationTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php --filter="search|alias|identifier"
```

- [ ] **Step 5: Commit searchable identity data**

```bash
git add app/Services/IngredientCatalogSearchService.php app/Http/Controllers/IngredientController.php app/Livewire/Dashboard/IngredientsIndex.php app/Services/RecipeWorkbenchIngredientCatalogBuilder.php resources/js/recipe-workbench/catalog.js tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientsIndexLocalizationTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
git diff --cached --check
git commit -m "feat: search ingredients by aliases and identifiers"
```

## Task 8: Update classification prompts, exports, and catalogue seeding

**Files:**

- Modify: `app/Data/IngredientClassificationPromptInput.php`
- Modify: `app/Services/IngredientClassificationPromptBuilder.php`
- Modify: `app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientClassificationPrompt.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `app/Filament/Exports/IngredientExporter.php`
- Modify: `database/seeders/IngredientCatalogSeeder.php`
- Modify: `tests/Feature/IngredientClassificationPromptBuilderTest.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`
- Modify: `tests/Feature/CatalogSeederTest.php`

- [ ] **Step 1: Add failing prompt, export, and idempotent seeder tests**

Prompt tests must include multiple CAS values plus EC, UNII, and ECHA list entries and assert ECHA list is not labelled EINECS. Export tests assert primary-first deterministic CAS/EC columns plus complete identifier and locale/kind alias representations. Seeder tests prove legacy scalar dataset keys and optional new `identifiers`/`aliases` arrays both synchronize idempotently without duplicating records.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/CatalogSeederTest.php --filter="prompt|export|identifier|alias"
```

- [ ] **Step 3: Replace scalar prompt input and deterministic export fields**

Change `IngredientClassificationPromptInput` to accept typed identifier rows:

```php
/** @param array<int, array{scheme:string, value:string, is_primary:bool}> $identifiers */
public function __construct(
    public ?string $name,
    public ?string $inciName,
    public array $identifiers,
    public ?string $supplierNotes,
    public string $responseLocale,
) {}
```

The prompt's current-ingredient block groups all values by scheme. Its response instructions review each supplied external identifier and allow distinct supported proposals, while retaining the current rule that only officially verified functions receive COSING backing keys. Keep the human-readable response and locale instruction.

Change the exporter from scalar CAS/EC properties to `cas_numbers`, `ec_numbers`, `identifiers`, and `aliases`. Primary values sort first, then stable ID order. Sanitize every exported text value using the exporter's existing spreadsheet-injection convention.

Update `IngredientCatalogSeeder` to call the synchronizer after each ingredient is saved. It accepts an optional structured identity payload and maps existing `cas_number`/`ec_number` dataset fields when that payload is absent. This is future seeder compatibility only; do not run the seeder as part of deployment.

- [ ] **Step 4: Re-run focused tests**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/CatalogSeederTest.php --filter="prompt|export|identifier|alias"
```

- [ ] **Step 5: Commit prompt/export/seeder compatibility**

```bash
git add app/Data/IngredientClassificationPromptInput.php app/Services/IngredientClassificationPromptBuilder.php app/Filament/Resources/Ingredients/Pages/Concerns/InteractsWithIngredientClassificationPrompt.php app/Livewire/Dashboard/IngredientEditor.php app/Filament/Exports/IngredientExporter.php database/seeders/IngredientCatalogSeeder.php tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/CatalogSeederTest.php
git diff --cached --check
git commit -m "feat: expose complete ingredient identity data"
```

## Task 9: Drop the legacy ingredient columns and run the full identity regression suite

**Files:**

- Create: `database/migrations/2026_08_12_120100_drop_legacy_ingredient_identifier_columns.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `database/factories/IngredientFactory.php`
- Modify: `tests/Feature/IngredientIdentitySchemaTest.php`
- Modify: any remaining ingredient-only scalar references reported by the scoped search below

- [ ] **Step 1: Strengthen the schema test before removing columns**

Assert `ingredients.cas_number` and `ingredients.ec_number` are absent after all migrations, identifiers remain present, and rolling back the drop migration recreates and backfills the scalar fields from the primary CAS/EC rows. Update `IngredientIdentityMigrationTest` so its setup first calls the drop migration's `down()`, then the identity migration's `down()`; its `finally` block reapplies the identity migration when needed and always calls the drop migration's `up()`. This preserves the Task 1 backfill test after the final schema changes.

- [ ] **Step 2: Create the forward drop migration**

```bash
php artisan make:migration drop_legacy_ingredient_identifier_columns --table=ingredients --no-interaction
```

Rename it to the exact path above. `up()` drops only the two ingredient columns. `down()` recreates nullable strings and uses `ingredient_identifiers` to restore each primary CAS/EC value before the earlier identity-table migration can roll back.

- [ ] **Step 3: Remove scalar persistence and prove no application reference remains**

Remove `cas_number` and `ec_number` from `Ingredient` fillable state and `IngredientFactory`. Run:

```bash
rg -n "ingredient->(cas_number|ec_number)|Ingredient.*(cas_number|ec_number)|'cas_number'|'ec_number'" app/Models/Ingredient.php app/Services/IngredientDataEntryService.php app/Services/UserIngredientAuthoringService.php app/Filament/Resources/Ingredients app/Livewire/Dashboard/IngredientEditor.php app/Filament/Exports/IngredientExporter.php
```

Expected: form-state keys and prompt labels may remain; no code reads/writes scalar `Ingredient` attributes. Do not alter matching fields on allergens or substances.

- [ ] **Step 4: Run migrations, quality gates, and the complete relevant suite**

```bash
php artisan test --compact tests/Feature/IngredientIdentitySchemaTest.php tests/Feature/IngredientIdentityMigrationTest.php tests/Feature/IngredientIdentitySynchronizerTest.php tests/Feature/IngredientDataEntryServiceTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/IngredientsIndexDuplicationTest.php tests/Feature/IngredientsIndexLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php tests/Feature/IngredientClassificationPromptBuilderTest.php tests/Feature/CatalogSeederTest.php tests/Feature/SubstanceComplianceTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
graphify update .
git diff --check
```

Request a code review using `superpowers:requesting-code-review`, address every confirmed high/medium issue, and rerun the affected tests.

- [ ] **Step 5: Commit the legacy-column removal**

```bash
git add database/migrations/2026_08_12_120100_drop_legacy_ingredient_identifier_columns.php app/Models/Ingredient.php database/factories/IngredientFactory.php tests/Feature/IngredientIdentitySchemaTest.php tests/Feature/IngredientIdentityMigrationTest.php graphify-out
git diff --cached --check
git commit -m "refactor: retire scalar ingredient identifiers"
```

## Acceptance checklist

- [ ] Existing ingredient rows retain every obvious legacy CAS/EC value without automatic digit correction and without a catalogue reseed.
- [ ] Platform and workspace limits are server-enforced under a row lock.
- [ ] Platform identity is admin-curated and read-only to workspaces; workspace identity is tenant-isolated.
- [ ] Workspace users can maintain ingredient substances without managing per-delivery records or low-level source fields.
- [ ] French/German/etc. duplication writes localized base text with English field-level fallback and produces an independent workspace copy.
- [ ] Substances, identifiers, aliases, SAP/fatty acids, components, allergens, functions/provenance, and IFRA data copy independently.
- [ ] Searches find eligible aliases and all identifiers without leaking another workspace's aliases or auto-resolving ambiguity.
- [ ] Prompts and exports expose complete identifier collections and distinguish ECHA list from EC/EINECS.
- [ ] No `source_ingredient_id`, shared-record compliance override, API integration, or catalogue reseed was introduced.
