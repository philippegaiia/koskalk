# Workspace Material Codes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each workspace an optional, mnemonic internal material code for both platform and private ingredients, while keeping Koskalk catalogue keys hidden and immutable and preserving the code used by each production run.

**Architecture:** Store the workspace-authored code in a separate `workspace_ingredient_codes` overlay keyed by workspace and ingredient. Normalize codes to uppercase and require uniqueness only among current assignments in the same workspace. Do not generate codes, copy them when duplicating ingredients, reserve retired values, or create a code-history ledger. Production requirements copy the current code into `material_code_snapshot`; changing or reusing a live code never rewrites historical production rows.

**Tech Stack:** PHP 8.5, Laravel 13.26, Filament 5.7, Livewire 4, Blade, Tailwind CSS 4, Pest 4.7, PostgreSQL/SQLite-compatible migrations, and spatie/laravel-translation-loader 2.8.

---

## Product decisions and invariants

- `ingredients.catalog_key` is Koskalk's technical identity. It remains globally unique, automatically generated where applicable, immutable after creation, and hidden from normal workspace users.
- The admin ingredient editor shows `catalog_key` as read-only so administrators can diagnose catalogue identity. It does not offer an edit control.
- `CH1` and `CH3` remain reserved catalogue keys used by `CanonicalSoapAlkaliResolver`; workspace material codes never replace or influence this logic.
- `material_code` is the workspace's optional internal/GMP reference for an ingredient. It applies equally to a Koskalk platform ingredient and a workspace-owned ingredient.
- The user authors `material_code`; Koskalk does not suggest or generate one.
- Accepted codes are 1–64 characters, are trimmed and uppercased, start with a letter or digit, and contain only letters, digits, `.`, `_`, `-`, or `/`.
- A non-empty code is unique, case-insensitively, among current assignments in one workspace. The same code may exist in another workspace.
- Clearing or changing a code frees the old value immediately. A workspace may reuse it for a different ingredient.
- There is deliberately no retired-code table, reservation ledger, or code-change audit history in this feature.
- Production requirements freeze the current code, or `null`, alongside the existing material-name snapshot. Existing production snapshots are never backfilled or rewritten.
- A supplier's `code` identifies the supplier. `supplier_listings.supplier_sku` identifies the supplier's product. No additional user code is added to a supplier listing.
- All listings for the same ingredient show the same workspace material code through the ingredient overlay. If two supplier products are genuinely different materials or grades, the user creates two private ingredients.

## Delivery sequence

The work is intentionally ordered so the admin identity clarification lands first, followed by workspace authoring, purchasing display, and production freezing. Each task is independently testable and committable.

## Pre-implementation gate

- [ ] **Step 1: Re-read repository guidance**

Open `.ai/rules/index.md`, then every rule matching the files in the current task. At minimum, read the app, models, migrations, services, schemas/Filament, Livewire, Production Bench, language, and test rules. Run:

```bash
grep -rin 'catalog_key\|material code\|ingredient code\|production requirement\|supplier sku' .ai/rules
```

- [ ] **Step 2: Confirm installed versions and documentation**

```bash
composer show --direct
composer show livewire/livewire
```

Use Laravel Boost `search-docs` before code changes with version-scoped queries for schema indexes, model factories, Livewire validation, Filament read-only entries, and Livewire component tests. Do not add or update dependencies.

- [ ] **Step 3: Protect the current alkali work before touching overlapping files**

```bash
git status --short --branch
git diff --check
```

At plan authoring time, the working tree contains uncommitted alkali-review fixes, including changes in `ProductionAvailabilityPreview`, `production-create.blade.php`, translations, and production tests. Preserve them exactly. Commit that completed work first or stage every material-code file path explicitly; never reset, overwrite, or broadly stage the dirty tree.

- [ ] **Step 4: Establish a focused green baseline**

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchStockPreparationTest.php
```

Expected: PASS before material-code RED tests are added. If an existing alkali fix is not yet green, finish that change separately before beginning this plan.

---

### Task 1: Add the workspace material-code data boundary

**Files:**

- Create: `app/Models/WorkspaceIngredientCode.php`
- Create: `database/factories/WorkspaceIngredientCodeFactory.php`
- Create: `database/migrations/2026_08_26_120000_create_workspace_ingredient_codes_table.php`
- Create: `app/Services/WorkspaceIngredientCodeService.php`
- Create: `tests/Feature/WorkspaceIngredientCodeTest.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `app/Models/Workspace.php`
- Modify: `lang/en/ingredients.php`

- [ ] **Step 1: Generate the model, factory, migration, service, and test**

```bash
php artisan make:model WorkspaceIngredientCode --factory --migration --no-interaction
php artisan make:class Services/WorkspaceIngredientCodeService --no-interaction
php artisan make:test --pest WorkspaceIngredientCodeTest --no-interaction
```

Rename the generated migration to `database/migrations/2026_08_26_120000_create_workspace_ingredient_codes_table.php` immediately after generation.

- [ ] **Step 2: Write the failing service and schema tests**

Use `uses(RefreshDatabase::class);` and factories. Cover all of these cases:

1. A platform ingredient can receive a workspace code.
2. A private ingredient owned by that workspace can receive a code.
3. `rm-olive_01` is stored as `RM-OLIVE_01`.
4. Blank input deletes the overlay row and returns `null`.
5. Two ingredients in one workspace cannot have `RM-OLIVE`, including case variants.
6. Two workspaces may both use `RM-OLIVE`.
7. Updating an ingredient from `RM-OLIVE` to `RM-OLIVE-NEW` immediately permits another ingredient to use `RM-OLIVE`.
8. Owner, administrator, and editor roles may save; viewers and non-members may not.
9. A workspace cannot assign a code to another workspace's private ingredient.
10. Invalid characters and values longer than 64 characters fail under `material_code`.
11. The database has unique boundaries for `(workspace_id, ingredient_id)` and the normalized workspace code.

The reuse assertion must be explicit:

```php
$service->synchronize($owner, $workspace, $oliveOil, 'RM-OLIVE-NEW');
$reused = $service->synchronize($owner, $workspace, $coconutOil, 'RM-OLIVE');

expect($reused?->material_code)->toBe('RM-OLIVE');
```

- [ ] **Step 3: Run the test and verify RED**

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientCodeTest.php
```

Expected: FAIL because the table, model, and service do not exist.

- [ ] **Step 4: Create the overlay schema**

The migration must create only current assignments:

```php
Schema::create('workspace_ingredient_codes', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
    $table->string('material_code', 64);
    $table->timestamps();

    $table->unique(['workspace_id', 'ingredient_id'], 'workspace_ingredient_codes_material_unique');
    $table->unique(['workspace_id', 'material_code'], 'workspace_ingredient_codes_code_unique');
});
```

Because the service stores only uppercase values, the composite unique index protects concurrent service writes. In the same migration, follow `2026_08_01_100000_add_code_to_suppliers_table.php`: when the driver is PostgreSQL or SQLite, create an additional unique expression index on `(workspace_id, LOWER(material_code))` named `workspace_ingredient_codes_code_ci_unique`, and drop that expression index explicitly in `down()` before dropping the table. MySQL relies on the normalized uppercase write boundary and the application's existing case-insensitive string collation. Do not add nullable rows, soft deletes, a retired-code table, or a data backfill.

- [ ] **Step 5: Implement the model, relations, and factory**

`WorkspaceIngredientCode` uses `HasFactory` and `#[Fillable(['workspace_id', 'ingredient_id', 'material_code'])]`, with explicit `workspace(): BelongsTo` and `ingredient(): BelongsTo` relationships.

Add:

```php
// Ingredient.php
public function workspaceCodes(): HasMany;

// Workspace.php
public function ingredientCodes(): HasMany;
```

The factory creates a workspace, an ingredient, and an uppercase mnemonic code by default. Do not add `material_code` to `Ingredient::$fillable` or the `ingredients` table.

- [ ] **Step 6: Implement one normalization and authorization service**

Use this public contract:

```php
final class WorkspaceIngredientCodeService
{
    public function synchronize(
        User $actor,
        Workspace $workspace,
        Ingredient $ingredient,
        ?string $materialCode,
    ): ?WorkspaceIngredientCode;

    public function codeFor(Workspace $workspace, Ingredient $ingredient): ?string;

    /** @param list<int> $ingredientIds @return Collection<int, string> */
    public function codesFor(Workspace $workspace, array $ingredientIds): Collection;
}
```

Implementation rules:

- Normalize with `Str::upper(trim($materialCode))`.
- Treat an empty normalized value as a request to delete the current row.
- Validate against `\A[A-Z0-9][A-Z0-9._\/-]{0,63}\z`.
- Permit only workspace owner/admin/editor roles, without requiring a Production Bench entitlement.
- Permit active platform ingredients and private ingredients belonging to this workspace; reject foreign private ingredients.
- Use `DB::transaction(..., attempts: 5)` and `updateOrCreate()` after validation.
- Perform a friendly duplicate query before writing, but keep the unique index as the concurrency boundary. Convert the database duplicate into `ValidationException::withMessages(['material_code' => ...])`.
- Make `codesFor()` a single workspace-scoped query keyed by `ingredient_id`; callers must not issue one query per row.

Add English keys for the field label, helper text, format error, duplicate error, and inaccessible-material error.

- [ ] **Step 7: Verify and commit the data boundary**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/WorkspaceIngredientCodeTest.php
git add app/Models/WorkspaceIngredientCode.php app/Models/Ingredient.php app/Models/Workspace.php app/Services/WorkspaceIngredientCodeService.php database/factories/WorkspaceIngredientCodeFactory.php database/migrations/2026_08_26_120000_create_workspace_ingredient_codes_table.php lang/en/ingredients.php tests/Feature/WorkspaceIngredientCodeTest.php
git commit -m "feat: add workspace material codes"
```

Expected: PASS, including the explicit current-code reuse case.

---

### Task 2: Show the Koskalk catalogue key in Admin without making it editable

**Files:**

- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `lang/en/ingredients.php`
- Modify: `tests/Feature/Filament/CatalogResourcesTest.php`

- [ ] **Step 1: Write the failing Admin schema tests**

Extend `CatalogResourcesTest.php` to prove:

- an existing ingredient edit form shows its exact `catalog_key`;
- the key is represented by a read-only `TextEntry`, not a `TextInput`;
- the create form explains that the catalogue key is assigned after creation;
- filling or submitting the form cannot change `catalog_key`;
- `CH1` and `CH3` receive the same read-only treatment as other platform ingredients.

- [ ] **Step 2: Run the Admin test and verify RED**

```bash
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php
```

Expected: FAIL because the identity entry is absent.

- [ ] **Step 3: Add the read-only identity entry**

At the top of the General tab's classification/identity section, add a `TextEntry::make('catalog_key')` with:

- label `Koskalk catalogue key`;
- helper text explaining that it is a technical, immutable identifier;
- the record's key on edit/view;
- `Assigned after creation` when no record exists;
- copyable output when a key exists.

Do not use `TextInput`, do not dehydrate a value, do not add an update hook, and do not change `IngredientDataEntryService::generateCatalogKey()`. This task clarifies identity; it does not make keys mutable or change their format.

- [ ] **Step 4: Run required Filament checks and commit**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/Filament/CatalogResourcesTest.php
git add app/Filament/Resources/Ingredients/Schemas/IngredientForm.php lang/en/ingredients.php tests/Feature/Filament/CatalogResourcesTest.php
git commit -m "feat: expose immutable catalog key to admins"
```

Expected: PASS and no unresolved Filacheck finding.

---

### Task 3: Let workspace users author a material code on both private and platform ingredients

**Files:**

- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `resources/views/livewire/dashboard/ingredient-editor.blade.php`
- Modify: `lang/en/ingredients.php`
- Modify: `tests/Feature/UserIngredientAuthoringTest.php`
- Modify: `tests/Feature/IngredientEditorLocalizationTest.php`

- [ ] **Step 1: Write failing private-ingredient authoring tests**

Add Livewire tests proving:

- the private ingredient create form accepts `data.material_code` and creates both records atomically;
- edit loads and updates the current workspace code;
- clearing the field deletes only the overlay, not the ingredient;
- a duplicate code returns a `data.material_code` validation error and leaves both ingredient and code unchanged;
- no material code is generated when the field is blank;
- duplicating a platform ingredient starts with a blank code rather than copying an existing assignment.

- [ ] **Step 2: Write failing platform-reference tests**

On an existing platform ingredient page, prove:

- catalogue fields remain read-only;
- a separate `Internal material code` card is editable for the active workspace;
- saving affects only that workspace's overlay;
- switching to another workspace shows that workspace's independent value;
- viewers cannot save.

- [ ] **Step 3: Run the editor tests and verify RED**

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php
```

Expected: FAIL because neither authoring surface exists.

- [ ] **Step 4: Add the private ingredient form field**

Add `TextInput::make('material_code')` to the Details section for workspace-owned ingredient create/edit only. Configure it with:

- the shared translated label and helper text;
- maximum length 64;
- `live(onBlur: true)` only if needed for validation feedback;
- no default and no automatic state mutation beyond what the service returns.

In `mount()`, resolve the current user's `company()` and fill `data.material_code` from `WorkspaceIngredientCodeService::codeFor()`. For a new ingredient, fill `null`.

In `save()`, extract and unset `material_code` before passing the ingredient state to `UserIngredientAuthoringService`. Inside the existing outer transaction, save/update the ingredient first, then call `WorkspaceIngredientCodeService::synchronize()`. This ensures a duplicate code rolls back a newly created ingredient and any other form mutations. After save, refill the normalized code from the service.

Do not add the field to `ingredients`, `UserIngredientAuthoringService`, or the Koskalk catalogue-key generator.

- [ ] **Step 5: Add the platform ingredient overlay form**

The platform ingredient's main Filament schema is intentionally disabled. Keep it disabled. Add one separate compact Blade form above it using a dedicated public property such as `workspaceMaterialCode` and a `saveWorkspaceMaterialCode()` action on `IngredientEditor`.

The action must:

1. resolve the authenticated user, active workspace, and current accessible platform ingredient;
2. call the same `WorkspaceIngredientCodeService::synchronize()` method;
3. map `material_code` validation errors to `workspaceMaterialCode`;
4. replace the property with the normalized saved value;
5. show the existing application notification pattern.

Render the same card read-only for a viewer. Never show `catalog_key` on this workspace-facing page.

- [ ] **Step 6: Verify and commit workspace authoring**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php
git add app/Livewire/Dashboard/IngredientEditor.php resources/views/livewire/dashboard/ingredient-editor.blade.php lang/en/ingredients.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php
git commit -m "feat: author workspace material codes"
```

Expected: PASS; platform identity stays read-only while its workspace overlay is writable.

---

### Task 4: Use the linked material code in purchasing without adding another listing code

**Files:**

- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierDetail.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php`
- Modify: `tests/Feature/ProductionBenchSupplierListingCreatePageTest.php`
- Modify: `tests/Feature/SupplierListingManagementTest.php`

- [ ] **Step 1: Write failing purchasing tests**

Prove that:

- ingredient selector labels use `RM-OLIVE · Olive oil` when a workspace code exists;
- searching `RM-OLIVE` finds the ingredient;
- an ingredient with no code still renders its localized name cleanly;
- the selector no longer exposes or searches hidden `catalog_key` values;
- listing index and supplier detail show the linked workspace code next to the ingredient name;
- two supplier listings for the same ingredient show the same material code but retain their own `supplier_sku` values;
- saving a supplier listing does not accept or persist a new `material_code` attribute.

- [ ] **Step 2: Run the purchasing tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/SupplierListingManagementTest.php
```

Expected: FAIL because labels and search currently know only ingredient catalogue fields.

- [ ] **Step 3: Add workspace-scoped code search and labels**

Update `availableIngredientQuery()` to eager-load the current workspace's `workspaceCodes` relation. Extend `ingredientSearchResults()` with a `whereHas('workspaceCodes', ...)` branch constrained by both `workspace_id` and `material_code`. Remove the normal-user `catalog_key` search branch.

Change `ingredientLabel()` to prepend the eager-loaded code when present:

```php
$name = $ingredient->localizedDisplayName() ?? $ingredient->display_name ?? __('ingredients.editor.common.unnamed');
$materialCode = $ingredient->workspaceCodes->first()?->material_code;

return filled($materialCode) ? $materialCode.' · '.$name : $name;
```

Use the same method when an ingredient comes from a return-to-catalog flow; do not directly assign `localizedDisplayName()` in `mount()`.

- [ ] **Step 4: Add code data to listing rows in bulk**

In listing-index and supplier-detail row preparation, bulk-load codes for the page's ingredient IDs with `WorkspaceIngredientCodeService::codesFor()`. Add `material_code` to each presentation row and render it as secondary monospace text beside or immediately above the localized ingredient name.

Do not add a migration or field to `supplier_listings`. Keep:

- `suppliers.code` = supplier identity;
- `supplier_listings.supplier_sku` = supplier product identity;
- `workspace_ingredient_codes.material_code` = the workspace's material identity.

- [ ] **Step 5: Verify and commit purchasing display**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/CatalogReturnFlowTest.php
git add app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php app/Livewire/ProductionBench/Purchasing/SupplierDetail.php resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/SupplierListingManagementTest.php
git commit -m "feat: show material codes in purchasing"
```

Expected: PASS, with no new supplier-listing identifier.

---

### Task 5: Freeze the current material code in production requirements

**Files:**

- Create: `database/migrations/2026_08_26_121000_add_material_code_snapshot_to_production_requirements.php`
- Create: `app/Services/Production/ProductionRequirementMaterialCodeSnapshotter.php`
- Modify: `app/Models/ProductionRequirement.php`
- Modify: `database/factories/ProductionRequirementFactory.php`
- Modify: `app/Actions/Production/CreateProductionDraft.php`
- Modify: `app/Services/Production/ProductionAvailabilityPreview.php`
- Modify: `app/Services/Production/FlashProductionSimulator.php`
- Modify: `app/Services/Production/ProductionDetailPresenter.php`
- Modify: `resources/views/livewire/production-bench/production/production-create.blade.php`
- Modify: `resources/views/livewire/production-bench/production/flash-planner.blade.php`
- Modify: `resources/views/livewire/production-bench/production/stock-preparation.blade.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Modify: `tests/Feature/ProductionPlanningTest.php`
- Modify: `tests/Feature/ProductionCalculatedMaterialsTest.php`
- Modify: `tests/Feature/FlashProductionSimulatorTest.php`
- Modify: `tests/Feature/ProductionDetailPresenterTest.php`
- Modify: `tests/Feature/ProductionBenchStockPreparationTest.php`

- [ ] **Step 1: Generate the migration and snapshotter**

```bash
php artisan make:migration add_material_code_snapshot_to_production_requirements --table=production_requirements --no-interaction
php artisan make:class Services/Production/ProductionRequirementMaterialCodeSnapshotter --no-interaction
```

Rename the generated migration to `database/migrations/2026_08_26_121000_add_material_code_snapshot_to_production_requirements.php` immediately after generation.

- [ ] **Step 2: Write failing snapshot lifecycle tests**

Create an ingredient with `RM-OLIVE`, then create a production draft and assert its ingredient requirement stores `material_code_snapshot = RM-OLIVE`. Update the current code to `RM-OLIVE-NEW` and assert:

- the existing production requirement remains `RM-OLIVE`;
- the existing `subject_name_snapshot` remains unchanged;
- a new production requirement receives `RM-OLIVE-NEW`.

Then assign the freed `RM-OLIVE` to a different ingredient and prove the old production still shows its original name plus `RM-OLIVE`. This is the acceptance test for deliberate code reuse without a reservation ledger.

Also cover:

- no workspace code produces a `null` snapshot;
- packaging requirements always receive `null`;
- calculated `CH1`/`CH3` requirements receive their workspace code after the regular and calculated requirement collections are combined;
- preview and flash-planner output use the same current code that creation will freeze.

- [ ] **Step 3: Run the production tests and verify RED**

```bash
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchStockPreparationTest.php
```

Expected: FAIL because the snapshot column and enrichment service do not exist.

- [ ] **Step 4: Add the nullable production snapshot column**

The migration adds:

```php
$table->string('material_code_snapshot', 64)
    ->nullable()
    ->after('subject_name_snapshot');
```

The reverse migration drops only this column. Do not backfill existing productions; `null` truthfully means no frozen code was recorded by the older application version.

Add `material_code_snapshot` to `ProductionRequirement`'s `#[Fillable]` list and factory state.

- [ ] **Step 5: Enrich the complete requirement collection in one query**

Use this public contract:

```php
final class ProductionRequirementMaterialCodeSnapshotter
{
    /** @param Collection<int, array<string, mixed>> $requirements */
    public function apply(Workspace $workspace, Collection $requirements): Collection;
}
```

`apply()` must:

1. collect unique non-null ingredient IDs;
2. load all current codes in one workspace-scoped query;
3. return a new collection where ingredient arrays contain their current code under `material_code_snapshot` and packaging arrays contain `null`;
4. preserve all existing keys, order, fractional KOH purity labels, masses, and percentages unchanged.

Inject and call it in `CreateProductionDraft` only after regular requirements have been concatenated with `ProductionCalculatedRequirementBuilder` output, and before `requirements()->createMany()`. Call the same service at the equivalent point in `ProductionAvailabilityPreview` and `FlashProductionSimulator` so previews match eventual persistence.

- [ ] **Step 6: Present the code without mutating the material name**

Add `material_code` separately to preview/simulator output and `ProductionDetailPresenter` material rows. Render the code as concise monospace secondary text on:

- production creation requirement preview;
- flash planner requirements;
- stock preparation;
- production detail, including actual-use rows.

Keep `material_name` and `subject_name_snapshot` untouched. KOH therefore renders as, for example, material code `RM-KOH` plus the existing name `Potassium hydroxide (KOH 90%)`; the code does not replace or absorb the purity label.

- [ ] **Step 7: Verify and commit production history freezing**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionSnapshotsTest.php
git add app/Actions/Production/CreateProductionDraft.php app/Models/ProductionRequirement.php app/Services/Production/FlashProductionSimulator.php app/Services/Production/ProductionAvailabilityPreview.php app/Services/Production/ProductionDetailPresenter.php app/Services/Production/ProductionRequirementMaterialCodeSnapshotter.php database/factories/ProductionRequirementFactory.php database/migrations/2026_08_26_121000_add_material_code_snapshot_to_production_requirements.php resources/views/livewire/production-bench/production/production-create.blade.php resources/views/livewire/production-bench/production/flash-planner.blade.php resources/views/livewire/production-bench/production/stock-preparation.blade.php resources/views/livewire/production-bench/production/production-detail.blade.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchStockPreparationTest.php
git commit -m "feat: freeze production material codes"
```

Expected: PASS. Changing or reusing a current code must never alter an existing requirement snapshot.

---

### Task 6: Localize the feature and record the domain vocabulary

**Files:**

- Modify: `lang/en/ingredients.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `tests/Feature/InterfaceTranslationCatalogueTest.php`
- Modify: `CONTEXT.md`

- [ ] **Step 1: Add the English source copy**

Use `Internal material code` consistently in workspace UI. Do not label it merely `Ingredient code`, because that can be confused with the Koskalk catalogue key, supplier code, supplier SKU, or stock-lot code.

The helper text should say:

> Optional mnemonic reference used by your workspace, for example RM-OLIVE. It must be unique among your current materials.

Add short translated labels for production/purchasing displays and success/validation messages. Admin copy uses `Koskalk catalogue key` and explicitly says it is immutable.

- [ ] **Step 2: Add reviewed catalogue translations**

Append the new translation keys to `interface-translations.json` for every supported locale, preserving the existing JSON formatting and the current uncommitted alkali translation corrections. Extend `InterfaceTranslationCatalogueTest.php` to require every new key and verify the reviewed `Internal material code` terminology.

- [ ] **Step 3: Record the stable glossary distinction**

Add this domain meaning to `CONTEXT.md` without documenting classes or table names:

> **Internal material code** — An optional workspace-authored mnemonic reference for an ingredient. It is unique among the workspace's current assignments when present, may be changed or reused, and is distinct from Koskalk catalogue keys, supplier codes, supplier SKUs, and stock-lot codes. Production history preserves the value used at creation time.

Also clarify that `catalog_key` is a hidden, immutable Koskalk technical identity and that `CH1`/`CH3` are canonical resolver keys.

- [ ] **Step 4: Verify and commit language/domain changes**

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/IngredientEditorLocalizationTest.php
git diff --check -- lang/en/ingredients.php lang/en/production_bench.php database/seeders/data/interface-translations.json CONTEXT.md tests/Feature/InterfaceTranslationCatalogueTest.php
git add lang/en/ingredients.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/InterfaceTranslationCatalogueTest.php CONTEXT.md
git commit -m "docs: define internal material codes"
```

Expected: PASS with all supported locales represented.

---

### Task 7: Run final integrity and regression verification

- [ ] **Step 1: Format and inspect framework-specific code**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
git diff --check
```

Expected: no formatting errors and no unresolved Filacheck findings.

- [ ] **Step 2: Run the complete focused regression set**

```bash
php artisan test --compact tests/Feature/WorkspaceIngredientCodeTest.php tests/Feature/Filament/CatalogResourcesTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientEditorLocalizationTest.php tests/Feature/PublicIngredientPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/CatalogReturnFlowTest.php tests/Feature/ProductionPlanningTest.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchStockPreparationTest.php tests/Feature/ProductionSnapshotsTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: all tests PASS.

- [ ] **Step 3: Verify the essential schema and behavior explicitly**

```bash
php artisan test --compact --filter='workspace material code|material code snapshot|catalogue key'
```

The final assertions must demonstrate:

- one current code per workspace/ingredient;
- current code uniqueness inside a workspace;
- cross-workspace reuse;
- reuse after change or clearing;
- unchanged historical production snapshots after reuse;
- no `material_code` column on `ingredients` or `supplier_listings`;
- no retired/reserved-code table;
- immutable `catalog_key` and unchanged `CH1`/`CH3` resolution.

- [ ] **Step 4: Refresh the repository knowledge graph**

```bash
graphify update .
```

- [ ] **Step 5: Review the final diff and commit any verification-only corrections**

```bash
git status --short
git diff --stat
git diff --check
```

Stage corrections by explicit path. Do not include unrelated or pre-existing alkali-review changes in a material-code commit.

## Acceptance summary

The feature is complete when an administrator can see but not edit Koskalk's catalogue key; a workspace user can optionally author one mnemonic code for any accessible ingredient; that code is unique only among the workspace's current assignments; purchasing reuses the ingredient-level code rather than inventing a fourth identifier; and each new production freezes the current code without preventing future changes or reuse.
