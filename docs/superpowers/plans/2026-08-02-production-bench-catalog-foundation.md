# Production Bench Catalogue Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the user-owned Packaging catalogue and supplier-tainted Ingredient data with clean workspace catalogues that feed formulas, mixed supplier orders, current indicative prices, and traceable opening stock.

**Architecture:** Ingredients and Packaging remain separate catalogue entities with direct foreign keys. Shared transactional tables—supplier listings, purchase-order lines, stock lots, and current prices—use an exactly-one-subject constraint so one supplier order can mix both catalogue types without a duplicate materials catalogue. Existing Basic production snapshots remain independent; quotation documents and professional Production Runs are separate follow-up plans.

**Tech Stack:** PHP 8.5, Laravel 13, PostgreSQL, Livewire 4, Filament Forms 5, Pest 4, Tailwind CSS 4.

---

## Delivery Boundary

This plan produces one reviewable checkpoint with:

- clean Ingredient fields and notes;
- `PackagingItem` as a workspace-owned catalogue model;
- fixed contextual Packaging categories;
- workspace-scoped current Ingredient and Packaging prices;
- Supplier Listing organic status and renamed supplier-facing fields;
- mixed Ingredient and Packaging purchase orders with database integrity;
- safe return from central catalogue creation to Supplier Listing creation;
- opening stock that requires a Supplier Listing and becomes immediately available.

This plan does not build quotation/PDF/email workflows, direct receipts, planning,
reservations, or professional Production Runs.

## File and Responsibility Map

### New files

- `app/PackagingCategory.php` — fixed translated Packaging categories.
- `app/OrganicStatus.php` — tri-state commercial organic status.
- `app/MaterialPriceSource.php` — provenance vocabulary for current prices.
- `app/Models/PackagingItem.php` — workspace Packaging catalogue model.
- `app/Models/CurrentMaterialPrice.php` — one current workspace price per catalogue subject.
- `app/Services/CurrentMaterialPriceService.php` — canonical price conversion, upsert, provenance, and costing propagation.
- `database/factories/PackagingItemFactory.php` — workspace Packaging fixtures.
- `database/factories/CurrentMaterialPriceFactory.php` — current-price fixtures.
- `database/migrations/2026_08_02_000001_redesign_catalog_foundation.php` — clean catalogue table and foreign-key rename.
- `database/migrations/2026_08_02_000002_create_current_material_prices_table.php` — price projection and data conversion.
- `database/migrations/2026_08_02_000003_align_supplier_listings_and_stock_lots.php` — commercial organic and stock provenance fields.
- `tests/Feature/CatalogFoundationSchemaTest.php` — database invariants.
- `tests/Feature/CurrentMaterialPriceTest.php` — price conversion, uniqueness, provenance, and propagation.
- `tests/Feature/CatalogReturnFlowTest.php` — central catalogue return and preselection.

### Renamed files

- `app/Models/UserPackagingItem.php` → `app/Models/PackagingItem.php`.
- `app/Policies/UserPackagingItemPolicy.php` → `app/Policies/PackagingItemPolicy.php`.
- `app/Services/UserPackagingItemAuthoringService.php` → `app/Services/PackagingItemAuthoringService.php`.
- `database/factories/UserPackagingItemFactory.php` → `database/factories/PackagingItemFactory.php`.

### Main modified files

- Ingredient domain: `app/Models/Ingredient.php`, `app/Services/UserIngredientAuthoringService.php`, `app/Services/IngredientDataEntryService.php`, `app/Livewire/Dashboard/IngredientEditor.php`, `app/Filament/Exports/IngredientExporter.php`, `app/Filament/Resources/UserIngredients/Tables/UserIngredientsTable.php`.
- Packaging UI: `app/Livewire/Dashboard/PackagingItemEditor.php`, `app/Livewire/Dashboard/PackagingItemsIndex.php`, `app/Http/Controllers/PackagingItemController.php`, `lang/en/packaging.php` and every supported locale file or database translation fixture.
- Pricing: `app/Livewire/Dashboard/IngredientsIndex.php`, `app/Services/RecipeWorkbenchIngredientCatalogBuilder.php`, `app/Services/RecipeVersionCostingSynchronizer.php`, `app/Services/RecipeVersionCostPreviewBuilder.php`, `app/Services/LiveCostingPricePropagationService.php`.
- Purchasing and stock: `app/Models/SupplierListing.php`, `app/Models/PurchaseOrderLine.php`, `app/Models/StockLot.php`, `app/Actions/Purchasing/SaveSupplierListing.php`, `app/Actions/Purchasing/CreatePurchaseOrder.php`, `app/Actions/Purchasing/ReceivePurchaseOrder.php`, `app/Actions/Inventory/CreateOpeningStockLot.php`.
- Supplier Listing UI: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`, `app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php`, and their Blade views.
- Every model, factory, service, JavaScript payload, media consumer, and test returned by `rg -l "UserPackagingItem|user_packaging_item" app database tests resources routes`.

---

### Task 1: Lock the target database schema with failing tests

**Files:**
- Create: `tests/Feature/CatalogFoundationSchemaTest.php`
- Modify: `tests/Feature/SupplierListingSchemaTest.php`
- Modify: `tests/Feature/StockLedgerSchemaTest.php`

- [ ] **Step 1: Write the failing catalogue schema tests**

```php
<?php

use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses workspace packaging and clean generic ingredient columns', function (): void {
    expect(Schema::hasColumns('packaging_items', [
        'public_id', 'workspace_id', 'created_by_user_id', 'name', 'category',
        'notes', 'is_active', 'featured_image_path',
    ]))->toBeTrue()
        ->and(Schema::hasTable('user_packaging_items'))->toBeFalse()
        ->and(Schema::hasColumns('ingredients', ['notes']))->toBeTrue()
        ->and(Schema::hasColumns('ingredients', [
            'supplier_name', 'supplier_reference', 'is_organic',
        ]))->toBeFalse();
});

it('requires current prices to reference exactly one catalogue subject', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();

    expect(fn () => DB::table('current_material_prices')->insert([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => $packaging->id,
        'price_per_canonical_unit' => '0.004200000000',
        'currency' => 'EUR',
        'recorded_at' => now(),
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 2: Update existing schema assertions**

Replace every asserted `user_packaging_item_id` with `packaging_item_id`. Add
`organic_status` to Supplier Listings and Purchase Order Lines. Add
`supplier_listing_id` and `organic_status` to Stock Lots. Add an exactly-one
subject failure case for `purchase_order_lines`.

- [ ] **Step 3: Run the tests to verify they fail**

Run:

```bash
php artisan test --compact tests/Feature/CatalogFoundationSchemaTest.php tests/Feature/SupplierListingSchemaTest.php tests/Feature/StockLedgerSchemaTest.php
```

Expected: FAIL because `packaging_items`, `current_material_prices`, and the new
columns do not exist.

- [ ] **Step 4: Commit the red tests**

```bash
git add tests/Feature/CatalogFoundationSchemaTest.php tests/Feature/SupplierListingSchemaTest.php tests/Feature/StockLedgerSchemaTest.php
git commit -m "test: define catalog foundation schema"
```

---

### Task 2: Create category and organic enums

**Files:**
- Create: `app/PackagingCategory.php`
- Create: `app/OrganicStatus.php`
- Create: `app/MaterialPriceSource.php`
- Test: `tests/Feature/CatalogFoundationSchemaTest.php`

- [ ] **Step 1: Add enum-label tests**

```php
use App\OrganicStatus;
use App\PackagingCategory;

it('defines contextual packaging categories and commercial organic states', function (): void {
    expect(array_column(PackagingCategory::cases(), 'value'))->toBe([
        'box', 'jar', 'bottle', 'lid', 'cap', 'label', 'tube', 'pump', 'shipping', 'other',
    ])->and(array_column(OrganicStatus::cases(), 'value'))->toBe([
        'unknown', 'conventional', 'organic',
    ]);

    foreach (PackagingCategory::cases() as $category) {
        expect($category->getLabel())->toBe(__("packaging.categories.{$category->value}"));
    }
});
```

- [ ] **Step 2: Run the enum test to verify it fails**

Run: `php artisan test --compact tests/Feature/CatalogFoundationSchemaTest.php`

Expected: FAIL because the enums do not exist.

- [ ] **Step 3: Implement the backed enums**

```php
<?php

namespace App;

use Filament\Support\Contracts\HasLabel;

enum PackagingCategory: string implements HasLabel
{
    case Box = 'box';
    case Jar = 'jar';
    case Bottle = 'bottle';
    case Lid = 'lid';
    case Cap = 'cap';
    case Label = 'label';
    case Tube = 'tube';
    case Pump = 'pump';
    case Shipping = 'shipping';
    case Other = 'other';

    public function getLabel(): string
    {
        return __("packaging.categories.{$this->value}");
    }
}
```

Create `OrganicStatus` with `Unknown`, `Conventional`, and `Organic`. Create
`MaterialPriceSource` with `ManualCosting`, `SupplierListing`,
`ProcurementDocument`, `Receipt`, and `OpeningStock`.

- [ ] **Step 4: Add contextual translation keys**

In `lang/en/packaging.php` add:

```php
'categories' => [
    'box' => 'Box',
    'jar' => 'Jar',
    'bottle' => 'Bottle',
    'lid' => 'Lid',
    'cap' => 'Cap',
    'label' => 'Label',
    'tube' => 'Tube',
    'pump' => 'Pump',
    'shipping' => 'Shipping packaging',
    'other' => 'Other packaging',
],
```

Add equivalently contextual translations to every supported locale catalogue.

- [ ] **Step 5: Run the enum test and commit**

Run: `php artisan test --compact tests/Feature/CatalogFoundationSchemaTest.php`

Expected: enum assertion PASS; schema assertions still FAIL.

```bash
git add app/PackagingCategory.php app/OrganicStatus.php app/MaterialPriceSource.php lang tests/Feature/CatalogFoundationSchemaTest.php
git commit -m "feat: define packaging and sourcing enums"
```

---

### Task 3: Reshape the catalogue schema

**Files:**
- Create: `database/migrations/2026_08_02_000001_redesign_catalog_foundation.php`
- Create: `database/migrations/2026_08_02_000002_create_current_material_prices_table.php`
- Create: `database/migrations/2026_08_02_000003_align_supplier_listings_and_stock_lots.php`
- Test: `tests/Feature/CatalogFoundationSchemaTest.php`
- Test: `tests/Feature/SupplierListingSchemaTest.php`
- Test: `tests/Feature/StockLedgerSchemaTest.php`

- [ ] **Step 1: Generate the migrations**

Run each command with `--no-interaction`, then rename the generated files to the
explicit paths above so their execution order remains deterministic:

```bash
php artisan make:migration redesign_catalog_foundation --no-interaction
php artisan make:migration create_current_material_prices_table --no-interaction
php artisan make:migration align_supplier_listings_and_stock_lots --no-interaction
```

- [ ] **Step 2: Implement the catalogue migration**

The migration must:

1. add nullable `notes` to `ingredients`;
2. drop `supplier_name`, `supplier_reference`, and `is_organic`;
3. rename `user_packaging_items` to `packaging_items`;
4. rename all six referencing columns to `packaging_item_id`;
5. add nullable `workspace_id` and `created_by_user_id`;
6. copy the old `user_id` to `created_by_user_id` and resolve its owned workspace;
7. set `category = 'other'` and `is_active = true` for existing rows;
8. make `workspace_id` and `category` non-null;
9. drop the old `user_id` foreign key and column;
10. recreate explicitly named foreign keys and indexes after table/column renames.

Use the exact referencing tables:

```php
$packagingReferences = [
    'recipe_version_costing_packaging_items',
    'recipe_version_packaging_items',
    'production_batch_packaging_items',
    'supplier_listings',
    'purchase_order_lines',
    'stock_lots',
];
```

Before making `workspace_id` non-null, resolve every existing Packaging row through
the owning user's workspace. Add a migration test fixture proving the backfill. If
any row cannot be resolved, throw a descriptive exception and leave the migration
transaction unchanged; do not silently delete data or add runtime compatibility
branches. The authorized demo cleanup, if it is actually needed, must be an
explicitly reviewed step before rerunning the migration.

- [ ] **Step 3: Implement current-price storage**

```php
Schema::create('current_material_prices', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
    $table->foreignId('packaging_item_id')->nullable()->constrained()->restrictOnDelete();
    $table->decimal('price_per_canonical_unit', 24, 12);
    $table->string('currency', 3);
    $table->timestamp('recorded_at');
    $table->string('source_type', 32)->nullable();
    $table->unsignedBigInteger('source_id')->nullable();
    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});

DB::statement('ALTER TABLE current_material_prices ADD CONSTRAINT current_material_prices_exact_subject_check CHECK ((ingredient_id IS NOT NULL)::int + (packaging_item_id IS NOT NULL)::int = 1)');
DB::statement('CREATE UNIQUE INDEX current_material_prices_workspace_ingredient_unique ON current_material_prices (workspace_id, ingredient_id) WHERE ingredient_id IS NOT NULL');
DB::statement('CREATE UNIQUE INDEX current_material_prices_workspace_packaging_unique ON current_material_prices (workspace_id, packaging_item_id) WHERE packaging_item_id IS NOT NULL');
```

Backfill Ingredient prices as `price_per_kg / 1000` and Packaging prices as the
existing per-item `unit_cost`. After successful backfill, drop
`user_ingredient_prices`, `packaging_items.unit_cost`, and
`packaging_items.currency`.

- [ ] **Step 4: Align commercial and stock schema**

Rename `supplier_listings.supplier_name` to `supplier_item_name`. Add
`organic_status` defaulting to `unknown`. Rename the Packaging FK on listings,
order lines, and lots. Add `organic_status` to order lines and stock lots. Add
nullable `supplier_listing_id` to stock lots.

Add an exactly-one subject check to `purchase_order_lines`, matching the existing
listing and lot constraints.

- [ ] **Step 5: Run the schema tests**

Run:

```bash
php artisan test --compact tests/Feature/CatalogFoundationSchemaTest.php tests/Feature/SupplierListingSchemaTest.php tests/Feature/StockLedgerSchemaTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit the schema**

```bash
git add database/migrations tests/Feature/CatalogFoundationSchemaTest.php tests/Feature/SupplierListingSchemaTest.php tests/Feature/StockLedgerSchemaTest.php
git commit -m "feat: normalize production catalog schema"
```

---

### Task 4: Replace the user Packaging domain with workspace Packaging

**Files:**
- Rename: `app/Models/UserPackagingItem.php` → `app/Models/PackagingItem.php`
- Rename: `app/Policies/UserPackagingItemPolicy.php` → `app/Policies/PackagingItemPolicy.php`
- Rename: `app/Services/UserPackagingItemAuthoringService.php` → `app/Services/PackagingItemAuthoringService.php`
- Rename: `database/factories/UserPackagingItemFactory.php` → `database/factories/PackagingItemFactory.php`
- Modify: every file returned by `rg -l "UserPackagingItem|user_packaging_item" app database/factories tests resources routes`
- Test: `tests/Feature/PackagingItemsIndexTest.php`
- Test: `tests/Feature/PackagingItemEditorLocalizationTest.php`

- [ ] **Step 1: Update Packaging behavior tests first**

Change factories and assertions to use a Workspace. Add:

```php
it('creates packaging for the active workspace with a category', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();

    actingAs($user);

    Livewire::test(PackagingItemEditor::class)
        ->set('data.name', 'Kraft soap box')
        ->set('data.category', PackagingCategory::Box->value)
        ->set('data.notes', '100 g rectangular soap')
        ->call('save')
        ->assertHasNoErrors();

    expect(PackagingItem::query()->whereBelongsTo($workspace)->sole())
        ->category->toBe(PackagingCategory::Box)
        ->created_by_user_id->toBe($user->id)
        ->notes->toBe('100 g rectangular soap');
});
```

- [ ] **Step 2: Run the focused Packaging tests to verify failure**

Run:

```bash
php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/PackagingItemEditorLocalizationTest.php
```

Expected: FAIL on the old model, ownership, and missing category.

- [ ] **Step 3: Implement the Packaging model**

```php
#[Fillable([
    'public_id', 'workspace_id', 'created_by_user_id', 'name', 'category',
    'notes', 'is_active', 'featured_image_path', 'featured_image_original_name',
])]
class PackagingItem extends Model
{
    use HasFactory;
    use HasMediaAssetUsages;
    use HasPublicId;

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class)->withoutGlobalScopes();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'category' => PackagingCategory::class,
            'is_active' => 'boolean',
            'featured_image_original_name' => OriginalFilename::class,
        ];
    }
}
```

The factory must create or accept a Workspace, default to `Other`, and set
`is_active = true`.

- [ ] **Step 4: Rename types and foreign-key attributes mechanically**

Replace:

```text
UserPackagingItem              → PackagingItem
UserPackagingItemFactory       → PackagingItemFactory
UserPackagingItemAuthoringService → PackagingItemAuthoringService
user_packaging_item_id         → packaging_item_id
```

Do not rename customer-facing routes (`packaging-items.*`) or existing JavaScript
payload keys unless they expose the database-specific `user_` prefix.

- [ ] **Step 5: Make authoring workspace-scoped and archival**

`PackagingItemAuthoringService::create()` obtains `$user->company()`, stores
`workspace_id`, `created_by_user_id`, category, name, notes, and active state.
`update()` verifies `$workspace->hasMember($user)`. Used items are deactivated;
hard deletion is allowed only for unused records.

- [ ] **Step 6: Run Packaging, formula, media, and public-ID regressions**

Run:

```bash
php artisan test --compact tests/Feature/PackagingItemsIndexTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/RecipeVersionPackagingPlanTest.php tests/Feature/RecipeVersionCostingTest.php tests/Feature/MediaAssetConsumerIntegrationTest.php tests/Feature/PublicRouteIdentifierTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit the Packaging domain rename**

```bash
git add app database/factories resources routes tests
git commit -m "refactor: make packaging workspace owned"
```

---

### Task 5: Remove commercial fields from Ingredient authoring

**Files:**
- Modify: `app/Models/Ingredient.php`
- Modify: `app/Services/UserIngredientAuthoringService.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `app/Filament/Exports/IngredientExporter.php`
- Modify: `app/Filament/Resources/UserIngredients/Tables/UserIngredientsTable.php`
- Modify: `database/factories/IngredientFactory.php`
- Modify: `lang/en/ingredients.php` and supported translations
- Test: `tests/Feature/UserIngredientAuthoringTest.php`
- Test: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
- Test: `tests/Feature/Filament/CatalogResourcesTest.php`

- [ ] **Step 1: Rewrite tests around generic Ingredient identity**

Remove inputs and assertions for supplier name, supplier reference, and organic.
Add a note assertion:

```php
Livewire::test(IngredientEditor::class)
    ->set('data.name', 'French Green Clay')
    ->set('data.category', IngredientCategory::Clay->value)
    ->set('data.notes', 'Fine cosmetic-grade green clay')
    ->call('save')
    ->assertHasNoErrors();

expect(Ingredient::query()->where('display_name', 'French Green Clay')->sole())
    ->notes->toBe('Fine cosmetic-grade green clay');
```

- [ ] **Step 2: Run the Ingredient tests to verify failure**

Run:

```bash
php artisan test --compact tests/Feature/UserIngredientAuthoringTest.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php tests/Feature/Filament/CatalogResourcesTest.php
```

Expected: FAIL until the forms and services use `notes` and omit commercial fields.

- [ ] **Step 3: Remove the fields from every Ingredient path**

Remove the three fields from `$fillable`, casts, form states, editor components,
duplication payloads, exporters, admin tables, factories, and tests. Add one
nullable `Textarea::make('notes')` to the generic Ingredient details section.

- [ ] **Step 4: Verify no active code reference remains**

Run:

```bash
rg -n "supplier_name|supplier_reference|is_organic" app tests database/factories resources
```

Expected: only Supplier Listing `supplier_item_name` and historical migration text;
no Ingredient model or UI reference.

- [ ] **Step 5: Run tests and commit**

Run the three focused files again. Expected: PASS.

```bash
git add app database/factories lang tests resources
git commit -m "refactor: keep ingredients supplier neutral"
```

---

### Task 6: Implement workspace current prices

**Files:**
- Create: `app/Models/CurrentMaterialPrice.php`
- Create: `app/Services/CurrentMaterialPriceService.php`
- Create: `database/factories/CurrentMaterialPriceFactory.php`
- Remove: `app/Models/UserIngredientPrice.php`
- Remove: `app/Services/UserIngredientPriceMemory.php`
- Remove: `app/Policies/UserIngredientPricePolicy.php`
- Modify: `app/Services/LiveCostingPricePropagationService.php`
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Modify: `app/Livewire/Dashboard/PackagingItemsIndex.php`
- Modify: `app/Livewire/Dashboard/PackagingItemEditor.php`
- Modify: `app/Services/RecipeWorkbenchIngredientCatalogBuilder.php`
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `app/Services/RecipeVersionCostPreviewBuilder.php`
- Test: `tests/Feature/CurrentMaterialPriceTest.php`
- Test: `tests/Feature/IngredientsIndexPriceTest.php`
- Test: `tests/Feature/RecipeVersionCostingTest.php`

- [ ] **Step 1: Write price conversion and isolation tests**

```php
it('stores ingredient prices per gram and packaging prices per item', function (): void {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();
    $service = app(CurrentMaterialPriceService::class);

    $ingredientPrice = $service->rememberIngredient(
        workspace: $workspace,
        ingredient: $ingredient,
        pricePerKilogram: '4.20',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
    );
    $packagingPrice = $service->rememberPackaging(
        workspace: $workspace,
        packagingItem: $packaging,
        pricePerItem: '0.42',
        currency: 'EUR',
        source: MaterialPriceSource::ManualCosting,
        sourceId: null,
        actor: $owner,
    );

    expect($ingredientPrice->price_per_canonical_unit)->toBe('0.004200000000')
        ->and($packagingPrice->price_per_canonical_unit)->toBe('0.420000000000');
});
```

Also prove that the same platform Ingredient can have different prices in two
workspaces and that Packaging from another workspace is rejected.

- [ ] **Step 2: Run the price tests to verify failure**

Run: `php artisan test --compact tests/Feature/CurrentMaterialPriceTest.php`

Expected: FAIL because model and service do not exist.

- [ ] **Step 3: Implement the current-price model and service**

`CurrentMaterialPrice` casts `price_per_canonical_unit` to `decimal:12`,
`recorded_at` to datetime, and `source_type` to `MaterialPriceSource`. It exposes
`workspace()`, `ingredient()`, `packagingItem()`, and `createdBy()`.

The service validates workspace access, exact subject ownership, positive decimal
strings, selectable currency, and latest-recorded-time semantics. It uses
`updateOrCreate()` with the correct subject key and calls workspace-scoped costing
propagation after commit.

- [ ] **Step 4: Make costing propagation workspace-scoped**

Change the propagation signatures to:

```php
public function ingredientPriceChanged(
    Workspace $workspace,
    int $ingredientId,
    string $pricePerKilogram,
    ?int $exceptCostingId = null,
): void;

public function packagingPriceChanged(
    Workspace $workspace,
    int $packagingItemId,
    string $pricePerItem,
    ?int $exceptCostingId = null,
): void;
```

Scope through `costing.recipeVersion.workspace_id`, not `costing.user_id`, so all
members see the workspace price.

- [ ] **Step 5: Replace Formula and catalogue price lookups**

Update Ingredient and Packaging indexes, Formula Bench catalogue builders,
costing synchronization, and previews to read `current_material_prices` for the
active workspace. Convert Ingredient price per gram to kg/lb only at the display
boundary. Packaging remains per item.

- [ ] **Step 6: Run pricing and formula regressions**

Run:

```bash
php artisan test --compact tests/Feature/CurrentMaterialPriceTest.php tests/Feature/IngredientsIndexPriceTest.php tests/Feature/PackagingItemsIndexTest.php tests/Feature/RecipeVersionCostingTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit workspace pricing**

```bash
git add app database/factories resources tests
git commit -m "feat: add workspace material prices"
```

---

### Task 7: Align Supplier Listings and mixed purchase orders

**Files:**
- Modify: `app/Models/SupplierListing.php`
- Modify: `app/Models/PurchaseOrderLine.php`
- Modify: `app/Actions/Purchasing/SaveSupplierListing.php`
- Modify: `app/Actions/Purchasing/CreatePurchaseOrder.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php`
- Modify: supplier-listing Blade views
- Modify: `database/factories/SupplierListingFactory.php`
- Modify: `database/factories/PurchaseOrderLineFactory.php`
- Test: `tests/Feature/SupplierListingManagementTest.php`
- Test: `tests/Feature/PurchasingWorkflowTest.php`

- [ ] **Step 1: Add commercial-organic and mixed-order tests**

```php
it('creates one order containing ingredient and packaging listings', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredientListing = SupplierListing::factory()->for($workspace)->for($supplier)
        ->for(Ingredient::factory())
        ->create(['organic_status' => OrganicStatus::Organic]);
    $packagingListing = SupplierListing::factory()->for($workspace)->for($supplier)
        ->for(PackagingItem::factory()->for($workspace), 'packagingItem')
        ->create();

    $order = app(CreatePurchaseOrder::class)->handle(
        actor: $owner,
        workspace: $workspace,
        supplier: $supplier,
        lines: [
            ['listing' => $ingredientListing, 'packs' => 1],
            ['listing' => $packagingListing, 'packs' => 2],
        ],
    );

    expect($order->lines)->toHaveCount(2)
        ->and($order->lines->whereNotNull('ingredient_id'))->toHaveCount(1)
        ->and($order->lines->whereNotNull('packaging_item_id'))->toHaveCount(1)
        ->and($order->lines->firstWhere('ingredient_id', $ingredientListing->ingredient_id)->organic_status)
        ->toBe(OrganicStatus::Organic);
});
```

- [ ] **Step 2: Run focused tests to verify failure**

Run:

```bash
php artisan test --compact tests/Feature/SupplierListingManagementTest.php tests/Feature/PurchasingWorkflowTest.php
```

Expected: FAIL on renamed fields, organic status, and Packaging model.

- [ ] **Step 3: Update listing models, actions, and forms**

Cast `organic_status` to `OrganicStatus`; expose it only for Ingredient listings.
Rename `supplier_name` to `supplier_item_name`. Continue enforcing that a listing
cannot change its catalogue subject after creation.

After an applicable confirmed listing price is saved, call
`CurrentMaterialPriceService` with source `SupplierListing` and the listing ID.
For Ingredients, treat the supplier's entered price per mass unit (normally kg,
but also lb or oz) as the authoritative input and convert it to canonical price per
gram. For Packaging, convert the entered commercial-quantity price to canonical
price per item. Calculate the line/format total from those inputs; never use a
rounded displayed total as the source of the canonical price.

- [ ] **Step 4: Snapshot both line types in one order**

`CreatePurchaseOrder` accepts one supplier and a list of its listings, regardless
of catalogue type. It copies `organic_status`, the exact subject FK, supplier item
name, purchase format, content, price, and currency. Reject mixed suppliers and
mixed currencies in one order.

- [ ] **Step 5: Run tests and commit**

Run the two focused files again. Expected: PASS.

```bash
git add app database/factories resources tests
git commit -m "feat: align supplier listings with catalog items"
```

---

### Task 8: Add the central catalogue return flow

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/PackagingItemController.php`
- Modify: `app/Http/Controllers/IngredientController.php`
- Modify: `app/Livewire/Dashboard/PackagingItemEditor.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`
- Modify: Supplier Listing create Blade view
- Test: `tests/Feature/CatalogReturnFlowTest.php`

- [ ] **Step 1: Write allowlisted return tests**

Prove these behaviors:

- Supplier Listing offers `Create ingredient` and `Create packaging item` links.
- Creating Packaging from that link returns to Supplier Listing with the new
  Packaging item selected.
- Creating Ingredient does the same.
- Supplier context is retained when the flow started from Supplier detail.
- An arbitrary external or internal `return_to` URL is ignored.
- A returned item from another workspace is rejected.

- [ ] **Step 2: Run the return-flow test to verify failure**

Run: `php artisan test --compact tests/Feature/CatalogReturnFlowTest.php`

Expected: FAIL because the flow does not exist.

- [ ] **Step 3: Implement an allowlisted return context**

Use a symbolic query value, not an arbitrary URL:

```text
return_to=supplier_listing
supplier=<supplier public_id, optional>
```

After create, redirect to the named Supplier Listing route with:

```text
material_type=ingredient|packaging
ingredient=<ingredient public_id>
packaging_item=<packaging public_id>
supplier=<supplier public_id, optional>
```

Resolve every public ID through the active workspace before preselection. Unknown
return contexts fall back to the normal catalogue edit page.

- [ ] **Step 4: Run tests and commit**

Run: `php artisan test --compact tests/Feature/CatalogReturnFlowTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php`

Expected: PASS.

```bash
git add app routes resources tests/Feature/CatalogReturnFlowTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
git commit -m "feat: return catalog creation to supplier listings"
```

---

### Task 9: Require a Supplier Listing for opening stock

**Files:**
- Modify: `app/Models/StockLot.php`
- Modify: `app/Actions/Inventory/CreateOpeningStockLot.php`
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: Inventory Blade view
- Modify: `app/Actions/Purchasing/ReceivePurchaseOrder.php`
- Modify: `database/factories/StockLotFactory.php`
- Test: `tests/Feature/OpeningStockLedgerTest.php`
- Test: `tests/Feature/PurchasingWorkflowTest.php`

- [ ] **Step 1: Replace bare-subject opening tests**

```php
it('requires a workspace supplier listing and posts released opening stock', function (): void {
    [$owner, $workspace] = activeProductionWorkspace();
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create([
        'organic_status' => OrganicStatus::Organic,
    ]);

    $lot = app(CreateOpeningStockLot::class)->handle(
        actor: $owner,
        workspace: $workspace,
        listing: $listing,
        quantity: '2.5',
        unit: 'lb',
        pricePerCanonicalUnit: '0.01',
        currency: 'EUR',
        openedAt: '2026-08-02',
        idempotencyKey: 'opening-olive-oil',
    );

    expect($lot->supplier_listing_id)->toBe($listing->id)
        ->and($lot->ingredient_id)->toBe($ingredient->id)
        ->and($lot->organic_status)->toBe(OrganicStatus::Organic)
        ->and($lot->status)->toBe(StockLotStatus::Released);
});
```

Also prove cross-workspace listings are rejected, Packaging counts remain whole,
and the raw opening-stock form contains no quarantine control.

- [ ] **Step 2: Run opening-stock tests to verify failure**

Run: `php artisan test --compact tests/Feature/OpeningStockLedgerTest.php`

Expected: FAIL on the new action signature and fields.

- [ ] **Step 3: Implement listing-derived opening stock**

Change the action signature from `subject` and caller-selected status to a locked
`SupplierListing`. Resolve the subject from the listing, verify workspace access,
require price/currency/date, and always create a Released raw-material or Packaging
lot. Preserve negative corrections for later adjustment actions; opening quantity
itself remains positive.

Set `supplier_listing_id` and snapshot `organic_status`. Call
`CurrentMaterialPriceService` with the opening entry as `OpeningStock` provenance.

- [ ] **Step 4: Align normal receipts**

When receiving a PO line, set `stock_lots.supplier_listing_id` and snapshot the PO
line's `organic_status`. Raw Ingredient and Packaging receipts are Released in V1;
remove caller-selectable quarantine from this action and UI.

- [ ] **Step 5: Run tests and commit**

Run:

```bash
php artisan test --compact tests/Feature/OpeningStockLedgerTest.php tests/Feature/PurchasingWorkflowTest.php tests/Feature/ProductionBenchPagesTest.php
```

Expected: PASS.

```bash
git add app database/factories resources tests
git commit -m "feat: tie opening stock to supplier listings"
```

---

### Task 10: Remove stale names and verify the checkpoint

**Files:**
- Modify: any remaining files found by the searches below
- Test: all affected catalogue, formula, purchasing, inventory, media, and Basic production tests

- [ ] **Step 1: Search for stale domain names**

Run:

```bash
rg -n "UserPackagingItem|user_packaging_item|UserIngredientPrice|user_ingredient_prices" app database/factories resources routes tests
rg -n "supplier_name|supplier_reference|is_organic" app database/factories resources tests
```

Expected: no active application reference. Historical migrations may retain old
names because they describe the old schema before the forward migration.

- [ ] **Step 2: Run focused PHP tests**

```bash
php artisan test --compact tests/Feature/CatalogFoundationSchemaTest.php tests/Feature/CurrentMaterialPriceTest.php tests/Feature/PackagingItemsIndexTest.php tests/Feature/PackagingItemEditorLocalizationTest.php tests/Feature/UserIngredientAuthoringTest.php tests/Feature/IngredientsIndexPriceTest.php tests/Feature/RecipeVersionPackagingPlanTest.php tests/Feature/RecipeVersionCostingTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/PurchasingWorkflowTest.php tests/Feature/OpeningStockLedgerTest.php tests/Feature/CatalogReturnFlowTest.php tests/Feature/ProductionSnapshotsTest.php
```

Expected: PASS.

- [ ] **Step 3: Run required quality tools**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
npm run build
graphify update .
git diff --check
```

Expected: all commands pass; Graphify refreshes without errors.

- [ ] **Step 4: Run the broader regression suite**

Run: `php artisan test --compact`

Expected: PASS. If the known pre-existing Pest exporter memory issue in
`FailStaleMediaAssetsTest.php` recurs, record it separately and rerun every affected
suite explicitly; do not treat unrelated memory exhaustion as a catalogue failure.

- [ ] **Step 5: Commit cleanup**

```bash
git add app database database/factories lang resources routes tests graphify-out
git commit -m "chore: complete catalog foundation migration"
```

- [ ] **Step 6: Stop for the user checkpoint**

Provide authenticated review links for Ingredients, Packaging, Supplier Listings,
and Opening Stock. Do not begin quotation documents or professional Production
Runs until the user approves this checkpoint.
