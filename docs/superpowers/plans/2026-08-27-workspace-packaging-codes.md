# Workspace Packaging Codes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every workspace-owned packaging item an optional mnemonic internal material code, while keeping each supplier's SKU on its supplier listing and freezing internal codes in production and procurement history.

**Architecture:** Store `material_code` directly on `packaging_items` because packaging has no platform catalogue and is always workspace-owned. Normalize authored values to uppercase, enforce case-insensitive uniqueness among current packaging items in one workspace, and do not generate codes or reserve retired values. Reuse the existing production `material_code_snapshot` field for packaging requirements and add `material_code_snapshot` to purchase-order lines so issued RFQs and purchase orders remain historically stable.

**Tech Stack:** PHP 8.5, Laravel 13.26, Filament 5.7, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Vite 8, Pest 4.7, PostgreSQL/SQLite-compatible migrations, and spatie/laravel-translation-loader 2.8.

---

## Product decisions and invariants

- `packaging_items.material_code` is optional. A newly created packaging item has `null` unless the user enters a code.
- Koskalk never suggests or automatically generates a packaging code.
- The code is the workspace's internal/GMP reference for the packaging item. Example: `PK-BOT-250`.
- Accepted values follow the ingredient material-code grammar: 1–64 characters, trimmed and uppercased, starting with a letter or digit, containing only letters, digits, `.`, `_`, `-`, or `/`.
- A non-empty code is unique, case-insensitively, among current packaging items in one workspace. Another workspace may use the same code.
- Packaging-code uniqueness is separate from ingredient-code uniqueness. An ingredient and packaging item may both use the same text; this feature does not introduce a generalized cross-catalogue code registry.
- Clearing or changing a code frees the previous value immediately. Codes may be reused; there is no retirement ledger or code-change audit table.
- `supplier_listings.supplier_sku` remains the supplier's product reference. No additional workspace-authored code is added to supplier listings.
- Every supplier listing for the same packaging item shows the same packaging `material_code`, while each listing may have a different `supplier_sku`.
- Production requirements freeze the current packaging code into the existing `production_requirements.material_code_snapshot` field when the production draft or preview is created.
- Purchase-order lines freeze the current ingredient or packaging code into `purchase_order_lines.material_code_snapshot` when the RFQ/order draft is created. Later code changes do not rewrite the line or an issued document snapshot.
- Recipe versions continue to link packaging by `packaging_item_id` and snapshot their existing name/notes fields. The material code remains live in formula packaging and costing screens until production or procurement creates its own historical snapshot.

## Main-branch prerequisite

At plan-authoring time, `main` is at `07ecb8ff` and does **not** contain the ingredient material-code implementation. The completed implementation is on local branch `codex/workspace-material-codes` at `21de9275`.

- [x] Check out `main`, confirm the working tree is clean, and integrate `codex/workspace-material-codes` into `main` before implementing this plan:

```bash
git switch main
git status --short --branch
git merge --no-ff codex/workspace-material-codes
```

Expected: `main` contains the ingredient material-code implementation. Resolve any overlap without discarding the current alkali fixes or either material-code plan document.

- [x] Perform every implementation task and commit in this plan directly on `main`. Do not create another feature branch or worktree for the packaging work.
- [x] Confirm these baseline files exist before continuing:

```bash
test -f app/Services/WorkspaceIngredientCodeService.php
test -f app/Services/Production/ProductionRequirementMaterialCodeSnapshotter.php
test -f database/migrations/2026_08_26_120000_create_workspace_ingredient_codes_table.php
```

- [x] Read `.ai/rules/index.md`, all rules matching the files in the current task, and run:

```bash
grep -rin 'packaging\|material code\|supplier sku\|production requirement\|purchase order' .ai/rules
```

- [x] Use Laravel Boost `search-docs` before code changes for version-specific migration indexes, Filament text inputs, Livewire form validation, Eloquent unique constraints, and Pest Livewire testing. If Boost is unavailable, verify the installed package implementations locally and record that fallback in the handoff.

- [x] Establish a focused green baseline:

```bash
php artisan test --compact \
  tests/Feature/PackagingItemsIndexTest.php \
  tests/Feature/PackagingItemEditorLocalizationTest.php \
  tests/Feature/RecipeWorkbenchPersistenceTest.php \
  tests/Feature/ProductionBenchSupplierListingCreatePageTest.php \
  tests/Feature/ProductionBenchSupplierPagesTest.php \
  tests/Feature/ProcurementLifecycleTest.php \
  tests/Feature/ProductionCalculatedMaterialsTest.php \
  tests/Feature/FlashProductionSimulatorTest.php
```

Expected: PASS before adding the first RED assertion.

---

### Task 1: Add the optional workspace packaging-code data boundary

**Files:**

- Create: `database/migrations/2026_08_27_090000_add_material_code_to_packaging_items_table.php`
- Modify: `app/Models/PackagingItem.php`
- Modify: `app/Services/PackagingItemAuthoringService.php`
- Modify: `database/factories/PackagingItemFactory.php`
- Modify: `lang/en/packaging.php`
- Test: `tests/Feature/PackagingItemsIndexTest.php`
- Test: `tests/Feature/CatalogFoundationSchemaTest.php`

- [x] **Step 1: Generate the migration**

```bash
php artisan make:migration add_material_code_to_packaging_items_table --table=packaging_items --no-interaction
```

Rename the generated migration to `database/migrations/2026_08_27_090000_add_material_code_to_packaging_items_table.php` so subsequent plan steps have a stable path.

- [x] **Step 2: Write failing authoring and schema tests**

Extend `PackagingItemsIndexTest.php` with cases proving:

```php
it('leaves the packaging material code empty unless the user authors one', function (): void {
    $item = app(PackagingItemAuthoringService::class)->create([
        'name' => 'Clear 250 ml bottle',
        'category' => PackagingCategory::Bottle->value,
        'unit_cost' => '0.20',
    ], $this->owner);

    expect($item->material_code)->toBeNull();
});

it('normalizes and updates an optional packaging material code', function (): void {
    $item = app(PackagingItemAuthoringService::class)->create([
        'name' => 'Clear 250 ml bottle',
        'category' => PackagingCategory::Bottle->value,
        'unit_cost' => '0.20',
        'material_code' => ' pk-bot_250 ',
    ], $this->owner);

    expect($item->material_code)->toBe('PK-BOT_250');

    $updated = app(PackagingItemAuthoringService::class)->update($item, [
        'name' => $item->name,
        'category' => $item->category->value,
        'unit_cost' => '0.20',
        'material_code' => '',
    ], $this->owner);

    expect($updated->material_code)->toBeNull();
});
```

Add cases for invalid characters, more than 64 characters, case-insensitive duplicates in one workspace, the same value in another workspace, and immediate reuse after a code is changed or cleared. Assert duplicate and format failures use the `material_code` validation key and leave the existing row unchanged.

Extend `CatalogFoundationSchemaTest.php` to assert `packaging_items.material_code` exists and is nullable.

- [x] **Step 3: Run the tests and verify RED**

```bash
php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/CatalogFoundationSchemaTest.php
```

Expected: FAIL because the column and authoring behavior do not exist.

- [x] **Step 4: Implement the reversible schema change**

Use a nullable column with no backfill and no default:

```php
Schema::table('packaging_items', function (Blueprint $table): void {
    $table->string('material_code', 64)->nullable()->after('name');
    $table->unique(
        ['workspace_id', 'material_code'],
        'packaging_items_workspace_material_code_unique',
    );
});

if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
    DB::statement(
        'CREATE UNIQUE INDEX packaging_items_workspace_material_code_ci_unique '
        .'ON packaging_items (workspace_id, LOWER(material_code))',
    );
}
```

In `down()`, drop the expression index first for PostgreSQL/SQLite, then drop the composite unique index and column. MySQL relies on normalized uppercase writes and the application's case-insensitive string collation.

- [x] **Step 5: Implement normalization, validation, and friendly duplicate errors**

Add `material_code` to `PackagingItem`'s `#[Fillable]` list and factory definition. In `PackagingItemAuthoringService`:

```php
private function normalizeMaterialCode(mixed $value): ?string
{
    $normalized = Str::upper(trim((string) $value));

    return $normalized === '' ? null : $normalized;
}
```

Before saving, validate non-null values with:

```php
if (preg_match('/\A[A-Z0-9][A-Z0-9._\/-]{0,63}\z/', $materialCode) !== 1) {
    throw ValidationException::withMessages([
        'material_code' => __('packaging.validation.material_code_format'),
    ]);
}
```

Perform a workspace-scoped duplicate query excluding the current item, then assign `$packagingItem->material_code = $materialCode`. Catch `UniqueConstraintViolationException` around the save and convert it to `ValidationException` using `packaging.validation.material_code_unique`. Preserve the existing create/update authorization and pricing behavior.

Add the normalized value to both `blankState()` and `formData()`; `blankState()` must return `null` and must not generate a value.

- [x] **Step 6: Verify and commit the data boundary**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/CatalogFoundationSchemaTest.php
git add app/Models/PackagingItem.php app/Services/PackagingItemAuthoringService.php database/factories/PackagingItemFactory.php database/migrations/2026_08_27_090000_add_material_code_to_packaging_items_table.php lang/en/packaging.php tests/Feature/PackagingItemsIndexTest.php tests/Feature/CatalogFoundationSchemaTest.php
git commit -m "feat: add workspace packaging codes"
```

Expected: PASS, including blank creation, normalization, uniqueness, and reuse.

---

### Task 2: Author and display the code in the packaging catalogue and Recipe Workbench

**Files:**

- Modify: `app/Livewire/Dashboard/PackagingItemEditor.php`
- Modify: `app/Livewire/Dashboard/PackagingItemsIndex.php`
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `app/Services/RecipeVersionCostPreviewBuilder.php`
- Modify: `app/Services/RecipeWorkbenchVersionPayloadMapper.php`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/sections/costing-section.js`
- Modify: `resources/js/recipe-workbench/sections/packaging-section.js`
- Modify: `resources/views/livewire/dashboard/packaging-items-index.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/packaging-catalog-modal.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/packaging-tab.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php`
- Modify: `lang/en/packaging.php`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/PackagingItemsIndexTest.php`
- Test: `tests/Feature/PackagingItemEditorLocalizationTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/RecipeVersionCostingTest.php`

- [x] **Step 1: Write failing catalogue and Workbench tests**

Add Livewire tests proving the packaging editor accepts `data.material_code`, refills the normalized value after save, clears it, and surfaces duplicate errors without changing the item. Assert the index shows the code and finds an item when searching only by code.

Extend Workbench coverage so the packaging catalogue payload contains:

```php
[
    'id' => $packagingItem->id,
    'material_code' => 'PK-BOT-250',
    'name' => 'Clear 250 ml bottle',
]
```

Assert creating a packaging item through the Workbench modal persists an optional code, and that packaging-plan and costing rows expose `material_code` without serializing it as an independently editable recipe field.

- [x] **Step 2: Run the focused tests and verify RED**

```bash
php artisan test --compact \
  tests/Feature/PackagingItemsIndexTest.php \
  tests/Feature/PackagingItemEditorLocalizationTest.php \
  tests/Feature/RecipeWorkbenchPersistenceTest.php \
  tests/Feature/RecipeVersionCostingTest.php
```

Expected: FAIL on missing form state and payload/display fields.

- [x] **Step 3: Add the optional editor field and catalogue search/display**

In `PackagingItemEditor::form()`, add:

```php
TextInput::make('material_code')
    ->label(__('packaging.editor.form.material_code.label'))
    ->helperText(__('packaging.editor.form.material_code.helper'))
    ->maxLength(64),
```

Do not mark it required and do not provide a default or placeholder that resembles a generated suggestion.

In `PackagingItemsIndex`, include `material_code` in the selected columns, allow `sortBy('material_code')`, and include it in the existing search closure. In the table, render a dedicated `Internal material code` column in monospace; render `—` for null.

- [x] **Step 4: Carry the live code through Workbench catalogue, plan, and costing payloads**

Add `material_code` to `packagingCatalogPayload()` and `savePackagingItem()` responses. Pass the modal's `material_code` into `PackagingItemAuthoringService` on create/update.

Add `material_code` to:

- `packagingCatalogForm` initialization and reset;
- packaging catalogue combobox descriptions and `searchText`;
- `addPackagingPlanRow()` and restored packaging plan rows;
- server-side `RecipeWorkbenchVersionPayloadMapper` packaging rows, resolved from the linked packaging item;
- `RecipeVersionCostPreviewBuilder::packagingRow()` and costing payload rows, resolved from the linked item;
- the Alpine costing-row mapping.

The display value is derived from the linked `PackagingItem`; do not add `material_code` to `recipe_version_packaging_items` or `recipe_version_costing_packaging_items`.

- [x] **Step 5: Render the code consistently**

In the Workbench modal, add an optional internal-code input. In the packaging selector, packaging-plan rows, and costing rows, render the code as secondary monospace text above or below the item name. Keep `name` as the primary label.

- [x] **Step 6: Verify and commit the catalogue and Workbench behavior**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact \
  tests/Feature/PackagingItemsIndexTest.php \
  tests/Feature/PackagingItemEditorLocalizationTest.php \
  tests/Feature/RecipeWorkbenchPersistenceTest.php \
  tests/Feature/RecipeVersionCostingTest.php
npm run build
git add app/Livewire/Dashboard/PackagingItemEditor.php app/Livewire/Dashboard/PackagingItemsIndex.php app/Services/RecipeVersionCostingSynchronizer.php app/Services/RecipeVersionCostPreviewBuilder.php app/Services/RecipeWorkbenchVersionPayloadMapper.php resources/js/recipe-workbench/component.js resources/js/recipe-workbench/sections/costing-section.js resources/js/recipe-workbench/sections/packaging-section.js resources/views/livewire/dashboard/packaging-items-index.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/packaging-catalog-modal.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/packaging-tab.blade.php resources/views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php lang/en/packaging.php lang/en/workbench.php tests/Feature/PackagingItemsIndexTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeVersionCostingTest.php
git commit -m "feat: expose packaging codes in costing"
```

Expected: focused tests and Vite build pass.

---

### Task 3: Keep packaging identity and supplier identity distinct in purchasing and inventory

**Files:**

- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierDetail.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/ProcurementCreate.php`
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/procurement-create.blade.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `lang/en/production_bench.php`
- Test: `tests/Feature/ProductionBenchSupplierListingCreatePageTest.php`
- Test: `tests/Feature/ProductionBenchSupplierPagesTest.php`
- Test: `tests/Feature/ProductionBenchProcurementPagesTest.php`
- Test: `tests/Feature/ProductionBenchInventoryModalTest.php`

- [x] **Step 1: Write failing purchasing and inventory tests**

Cover these acceptance cases:

1. Packaging selectors show `PK-BOT-250 · Clear 250 ml bottle` when a code exists and only the name when it does not.
2. Packaging selectors and listing/index searches find `PK-BOT-250` by code.
3. A supplier-listing row shows both `PK-BOT-250` and its separate `supplier_sku = 123645`.
4. Two supplier listings for one packaging item show the same internal code but preserve different supplier SKUs.
5. Saving a supplier listing cannot change `packaging_items.material_code`, even if a forged `material_code` attribute is submitted.
6. Inventory and procurement selectors/search results expose the packaging code without adding queries per row.

- [x] **Step 2: Run the focused tests and verify RED**

```bash
php artisan test --compact \
  tests/Feature/ProductionBenchSupplierListingCreatePageTest.php \
  tests/Feature/ProductionBenchSupplierPagesTest.php \
  tests/Feature/ProductionBenchProcurementPagesTest.php \
  tests/Feature/ProductionBenchInventoryModalTest.php
```

Expected: FAIL because packaging labels and searches currently use only the packaging name.

- [x] **Step 3: Update server-side labels and searches**

Use one presentation rule everywhere:

```php
private function packagingLabel(PackagingItem $item): string
{
    return filled($item->material_code)
        ? $item->material_code.' · '.$item->name
        : $item->name;
}
```

Extend packaging searches with `orWhereLike('material_code', "%{$search}%")` or the equivalent normalized case-insensitive query already used by the surrounding module. Ensure list queries eager-load/select the code and do not query from Blade.

Keep `SaveSupplierListing` unchanged except for an explicit regression test proving `material_code` is ignored/not accepted. `supplier_sku` remains the only supplier-product reference field.

- [x] **Step 4: Render both identifiers without conflating them**

Render the internal code next to the packaging name as secondary monospace text. Keep the supplier SKU next to the purchase format under the existing `Supplier SKU` terminology. Do not relabel either field as a generic `Code`.

- [x] **Step 5: Verify and commit purchasing visibility**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact \
  tests/Feature/ProductionBenchSupplierListingCreatePageTest.php \
  tests/Feature/ProductionBenchSupplierPagesTest.php \
  tests/Feature/ProductionBenchProcurementPagesTest.php \
  tests/Feature/ProductionBenchInventoryModalTest.php
git add app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php app/Livewire/ProductionBench/Purchasing/SupplierDetail.php app/Livewire/ProductionBench/Purchasing/ProcurementCreate.php app/Livewire/ProductionBench/InventoryIndex.php resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php resources/views/livewire/production-bench/purchasing/procurement-create.blade.php resources/views/livewire/production-bench/inventory-index.blade.php lang/en/production_bench.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchProcurementPagesTest.php tests/Feature/ProductionBenchInventoryModalTest.php
git commit -m "feat: show packaging codes in operations"
```

---

### Task 4: Freeze internal material codes in RFQs and purchase orders

**Files:**

- Create: `database/migrations/2026_08_27_090100_add_material_code_snapshot_to_purchase_order_lines_table.php`
- Create: `app/Services/ProcurementLineSnapshotBuilder.php`
- Modify: `app/Models/PurchaseOrderLine.php`
- Modify: `database/factories/PurchaseOrderLineFactory.php`
- Modify: `app/Actions/Purchasing/CreatePurchaseOrder.php`
- Modify: `app/Actions/Purchasing/IssueQuotationRequest.php`
- Modify: `app/Actions/Purchasing/PlacePurchaseOrder.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/ProcurementDetail.php`
- Modify: `app/Services/ProcurementDocumentFormatter.php`
- Modify: `resources/views/livewire/production-bench/purchasing/procurement-detail.blade.php`
- Modify: `resources/views/production-bench/purchasing/document-print.blade.php`
- Test: `tests/Feature/ProcurementLifecycleTest.php`
- Test: `tests/Feature/ProcurementDocumentOutputTest.php`
- Test: `tests/Feature/ProductionBenchProcurementPagesTest.php`

- [x] **Step 1: Generate the migration and service**

```bash
php artisan make:migration add_material_code_snapshot_to_purchase_order_lines_table --table=purchase_order_lines --no-interaction
php artisan make:class Services/ProcurementLineSnapshotBuilder --no-interaction
```

Rename the migration to `database/migrations/2026_08_27_090100_add_material_code_snapshot_to_purchase_order_lines_table.php`.

- [x] **Step 2: Write failing historical-snapshot tests**

Create one ingredient listing with `RM-OLIVE` and one packaging listing with `PK-BOT-250`, then create an RFQ/order draft. Assert each `PurchaseOrderLine` stores the appropriate `material_code_snapshot`.

Change both live codes after draft creation and assert:

```php
expect($ingredientLine->fresh()->material_code_snapshot)->toBe('RM-OLIVE')
    ->and($packagingLine->fresh()->material_code_snapshot)->toBe('PK-BOT-250');
```

Issue the quotation and purchase order, then assert both immutable JSON document snapshots contain the original `material_code`. Cover null codes and verify old lines/snapshots without this key continue to render.

- [x] **Step 3: Run procurement tests and verify RED**

```bash
php artisan test --compact \
  tests/Feature/ProcurementLifecycleTest.php \
  tests/Feature/ProcurementDocumentOutputTest.php \
  tests/Feature/ProductionBenchProcurementPagesTest.php
```

Expected: FAIL because purchase-order lines do not have a material-code snapshot.

- [x] **Step 4: Add the line snapshot column**

```php
Schema::table('purchase_order_lines', function (Blueprint $table): void {
    $table->string('material_code_snapshot', 64)
        ->nullable()
        ->after('listing_name');
});
```

Add the field to `PurchaseOrderLine`'s `#[Fillable]` list and factory. `down()` drops only this column. Do not backfill existing lines.

- [x] **Step 5: Freeze the code at draft creation**

Inject `WorkspaceIngredientCodeService` into `CreatePurchaseOrder`. Before creating lines, bulk-load ingredient codes for all ingredient listing IDs. For each line:

```php
'material_code_snapshot' => $listing->ingredient_id !== null
    ? $ingredientCodes->get($listing->ingredient_id)
    : $listing->packagingItem?->material_code,
```

Load packaging relationships before the write loop or resolve all packaging codes in one workspace-scoped query. Do not perform one query per order line.

- [x] **Step 6: Centralize issued line snapshots**

Give `ProcurementLineSnapshotBuilder` one public interface:

```php
/** @return array<string, mixed> */
public function build(PurchaseOrderLine $line, bool $includePrice): array;
```

The returned array includes the existing fields plus:

```php
'material_code' => $line->material_code_snapshot,
```

Inject this module into both `IssueQuotationRequest` and `PlacePurchaseOrder`, replacing their duplicated line-array construction. This ensures RFQ and purchase-order snapshots cannot drift.

- [x] **Step 7: Display the frozen code in procurement details and outputs**

Render `material_code_snapshot` on draft/detail rows. Update email and print output to use the immutable snapshot's `material_code` when present, with null-safe access for pre-feature JSON:

```php
$materialCode = $line['material_code'] ?? null;
```

The supplier SKU must remain visible independently.

- [x] **Step 8: Verify and commit procurement history**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact \
  tests/Feature/ProcurementLifecycleTest.php \
  tests/Feature/ProcurementDocumentOutputTest.php \
  tests/Feature/ProductionBenchProcurementPagesTest.php
git add app/Actions/Purchasing/CreatePurchaseOrder.php app/Actions/Purchasing/IssueQuotationRequest.php app/Actions/Purchasing/PlacePurchaseOrder.php app/Livewire/ProductionBench/Purchasing/ProcurementDetail.php app/Models/PurchaseOrderLine.php app/Services/ProcurementDocumentFormatter.php app/Services/ProcurementLineSnapshotBuilder.php database/factories/PurchaseOrderLineFactory.php database/migrations/2026_08_27_090100_add_material_code_snapshot_to_purchase_order_lines_table.php resources/views/livewire/production-bench/purchasing/procurement-detail.blade.php resources/views/production-bench/purchasing/document-print.blade.php tests/Feature/ProcurementLifecycleTest.php tests/Feature/ProcurementDocumentOutputTest.php tests/Feature/ProductionBenchProcurementPagesTest.php
git commit -m "feat: freeze material codes in procurement"
```

---

### Task 5: Extend production code freezing from ingredients to packaging

**Files:**

- Modify: `app/Services/Production/ProductionRequirementMaterialCodeSnapshotter.php`
- Modify: `app/Services/Production/ProductionAvailabilityPreview.php`
- Modify: `app/Services/Production/FlashProductionSimulator.php`
- Modify: `app/Services/Production/ProductionDetailPresenter.php`
- Test: `tests/Feature/ProductionCalculatedMaterialsTest.php`
- Test: `tests/Feature/FlashProductionSimulatorTest.php`
- Test: `tests/Feature/ProductionDetailPresenterTest.php`
- Test: `tests/Feature/ProductionBenchStockPreparationTest.php`

- [x] **Step 1: Write failing production parity tests**

Create a packaging item with `PK-BOT-250`, include it in a published formula's packaging plan, and assert:

1. production availability preview returns `material_code = PK-BOT-250`;
2. flash simulation returns the same code;
3. creating a production stores `material_code_snapshot = PK-BOT-250` on the packaging requirement;
4. changing the live packaging code does not change the stored requirement, production detail, or stock preparation;
5. a later production uses the new code;
6. packaging without a code continues to return/store `null`.

- [x] **Step 2: Run production tests and verify RED**

```bash
php artisan test --compact \
  tests/Feature/ProductionCalculatedMaterialsTest.php \
  tests/Feature/FlashProductionSimulatorTest.php \
  tests/Feature/ProductionDetailPresenterTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php
```

Expected: FAIL because `ProductionRequirementMaterialCodeSnapshotter` explicitly assigns `null` when there is no ingredient ID.

- [x] **Step 3: Bulk-resolve both material types**

Extend `ProductionRequirementMaterialCodeSnapshotter::apply()` to collect unique packaging IDs and fetch their codes in one workspace-scoped query:

```php
$packagingCodes = PackagingItem::query()
    ->where('workspace_id', $workspace->id)
    ->whereIn('id', $packagingItemIds)
    ->pluck('material_code', 'id');
```

Map each requirement using ingredient code first, packaging code second, otherwise null. Keep the existing `material_code_snapshot` field and do not add a second production column.

- [x] **Step 4: Preserve preview and stored-production parity**

The preview, simulator, presenter, and Blade views already understand `material_code`/`material_code_snapshot` from the ingredient implementation. Make only the minimal changes required to stop treating packaging as permanently null. Do not read live packaging codes when presenting an existing production; always use its stored snapshot.

- [x] **Step 5: Verify and commit production freezing**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact \
  tests/Feature/ProductionCalculatedMaterialsTest.php \
  tests/Feature/FlashProductionSimulatorTest.php \
  tests/Feature/ProductionDetailPresenterTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php
git add app/Services/Production/ProductionRequirementMaterialCodeSnapshotter.php app/Services/Production/ProductionAvailabilityPreview.php app/Services/Production/FlashProductionSimulator.php app/Services/Production/ProductionDetailPresenter.php tests/Feature/ProductionCalculatedMaterialsTest.php tests/Feature/FlashProductionSimulatorTest.php tests/Feature/ProductionDetailPresenterTest.php tests/Feature/ProductionBenchStockPreparationTest.php
git commit -m "feat: freeze packaging codes in production"
```

---

### Task 6: Complete localization, glossary, and regression verification

**Files:**

- Modify: `lang/en/packaging.php`
- Modify: `lang/en/production_bench.php`
- Modify: `lang/en/workbench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Modify: `CONTEXT.md`
- Modify: `.ai/rules/services.md`
- Test: `tests/Feature/InterfaceTranslationCatalogueTest.php`
- Test: `tests/Feature/NumberFormatPreferenceTest.php`

- [x] **Step 1: Add reviewed terminology and translation coverage**

Use `Internal material code` consistently for the workspace identifier and `Supplier SKU` for the supplier identifier. Add concise helper text explaining that the internal code is optional and is not generated.

Append every new key to `interface-translations.json` for every supported locale, preserving the authoritative catalogue format. Extend `InterfaceTranslationCatalogueTest.php` to require the new keys and reviewed English terminology.

- [x] **Step 2: Update durable domain guidance**

Expand the glossary entry in `CONTEXT.md` to cover ingredients and packaging:

> **Internal material code** — An optional workspace-authored mnemonic reference for an ingredient or packaging item. Ingredient codes and packaging codes are each unique among their own current workspace assignments, may be changed or reused, and are distinct from Koskalk catalogue keys, supplier codes, supplier SKUs, and stock-lot codes. Production and procurement history preserve the value frozen at creation time.

Extend `.ai/rules/services.md` with the durable rule that internal material codes are never generated, supplier SKUs remain listing-owned, and historical production/procurement uses snapshots.

- [x] **Step 3: Run formatting, catalogue validation, and focused regression suites**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact \
  tests/Feature/InterfaceTranslationCatalogueTest.php \
  tests/Feature/NumberFormatPreferenceTest.php \
  tests/Feature/WorkspaceIngredientCodeTest.php \
  tests/Feature/PackagingItemsIndexTest.php \
  tests/Feature/RecipeWorkbenchPersistenceTest.php \
  tests/Feature/ProductionBenchSupplierListingCreatePageTest.php \
  tests/Feature/ProductionBenchSupplierPagesTest.php \
  tests/Feature/ProcurementLifecycleTest.php \
  tests/Feature/ProcurementDocumentOutputTest.php \
  tests/Feature/ProductionCalculatedMaterialsTest.php \
  tests/Feature/FlashProductionSimulatorTest.php \
  tests/Feature/ProductionDetailPresenterTest.php \
  tests/Feature/ProductionBenchStockPreparationTest.php
npm run build
```

Expected: PASS with no generated-code expectation and no ingredient material-code regression.

- [x] **Step 4: Refresh the local knowledge graph**

```bash
graphify update .
```

If Graphify refuses to overwrite because its safety check detects fewer nodes, report the warning and leave the existing graph untouched.

- [x] **Step 5: Review the complete diff and commit documentation/localization**

```bash
git diff --check
git status --short
git diff --stat
git add .ai/rules/services.md CONTEXT.md database/seeders/data/interface-translations.json lang/en/packaging.php lang/en/production_bench.php lang/en/workbench.php tests/Feature/InterfaceTranslationCatalogueTest.php tests/Feature/NumberFormatPreferenceTest.php
git commit -m "docs: define packaging material code semantics"
```

## Completion criteria

- A packaging item can exist without a code, and no code is generated.
- A user can author, edit, clear, and reuse a mnemonic packaging code.
- Current non-empty codes are case-insensitively unique among packaging items in one workspace.
- Ingredient and packaging code namespaces remain separate.
- Packaging selectors, catalogue, formula packaging, costing, purchasing, and inventory show/search the internal code where operationally useful.
- Supplier SKU remains an independent supplier-listing field.
- RFQ and purchase-order lines freeze ingredient and packaging codes, and issued document JSON/output uses those snapshots.
- Production preview, flash simulation, stored requirements, production detail, and stock preparation all agree on the packaging code.
- Existing records with null codes and historical JSON without `material_code` remain valid.
- Focused tests, Pint, Vite build, translation catalogue validation, and `git diff --check` pass.
