# Production Bench Purchasing and Inventory Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the first-pass all-in-one Production Bench purchasing and lot-ledger screens with a supplier-centred quotation, ordering, receipt, and summary-inventory workflow.

**Architecture:** Keep the existing workspace-scoped models and immutable stock ledger, evolving their schema through additive migrations. Put commercial-price normalization, procurement transitions, receipts, and adjustments in focused domain actions; expose them through small route-level Livewire pages instead of one large component. Preserve issued RFQ/PO snapshots and receipt/lot prices while feeding the latest applicable ingredient price into the existing `UserIngredientPriceMemory`.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Tailwind CSS 4, PostgreSQL, Pest 4, existing private media library.

---

## File Structure

### Domain and persistence

- Create `app/ListingPriceBasis.php` — per-unit versus total-purchase-format pricing.
- Create `app/ProcurementStage.php` — quotation versus purchase-order stage.
- Modify `app/PurchaseOrderStatus.php` — add quotation lifecycle states.
- Modify `app/ProductionDocumentType.php` — add quotation-request and purchase-order purposes.
- Create additive migrations in `database/migrations/` — evolve supplier, listing, procurement, receipt, and stock-lot fields without rewriting the first migrations.
- Modify `app/Models/Supplier.php` — structured address, contact, documents, receipts.
- Modify `app/Models/SupplierListing.php` — purchase-format terminology and normalized price fields.
- Modify `app/Models/PurchaseOrder.php` and `PurchaseOrderLine.php` — quotation/order lifecycle and immutable snapshots.
- Modify `app/Models/GoodsReceipt.php` and `GoodsReceiptLine.php` — direct receipts and mandatory price snapshots.
- Modify `app/Models/StockLot.php` — supplier-listing provenance for opening and received stock.
- Modify `database/factories/SupplierFactory.php`,
  `SupplierListingFactory.php`, `PurchaseOrderFactory.php`,
  `PurchaseOrderLineFactory.php`, `GoodsReceiptFactory.php`,
  `GoodsReceiptLineFactory.php`, and `StockLotFactory.php`.

### Services and actions

- Create `app/Services/SupplierListingPriceCalculator.php` — derive canonical price per kg/count and total purchase-format price.
- Create `app/Actions/Purchasing/SaveSupplier.php`.
- Create `app/Actions/Purchasing/SaveSupplierListing.php`.
- Modify `app/Actions/Purchasing/CreatePurchaseOrder.php` — create either an RFQ draft or direct PO draft, with nullable prices.
- Create `app/Actions/Purchasing/IssueQuotationRequest.php`.
- Create `app/Actions/Purchasing/RecordProcurementLinePrice.php`.
- Create `app/Actions/Purchasing/ConvertQuotationToPurchaseOrder.php`.
- Modify `app/Actions/Purchasing/PlacePurchaseOrder.php` — issue and snapshot the PO.
- Modify `app/Actions/Purchasing/ReceivePurchaseOrder.php` — nominal mass, actual packaging count, mandatory receipt price, immediate availability.
- Create `app/Actions/Purchasing/CreateDirectReceipt.php`.
- Modify `app/Actions/Inventory/CreateOpeningStockLot.php` — require a supplier listing and price.
- Create `app/Actions/Inventory/AdjustStock.php`.
- Modify `app/Services/StockPositionService.php` — summary rows and no raw-ingredient quarantine dependency.

### Customer pages

- Replace `app/Livewire/ProductionBench/PurchasingIndex.php` with focused components under `app/Livewire/ProductionBench/Purchasing/`.
- Create `SupplierIndex.php`, `SupplierDetail.php`, `SupplierListingIndex.php`, `ProcurementIndex.php`, `ProcurementEditor.php`, `ReceiptIndex.php`, and `ReceiptEditor.php`.
- Create the supplier, listing, procurement, and receipt views explicitly listed
  in Tasks 3, 6, and 8 under
  `resources/views/livewire/production-bench/purchasing/`.
- Create route wrappers under `resources/views/production-bench/purchasing/`.
- Create `resources/views/components/production-bench/purchasing-navigation.blade.php`.
- Modify `routes/web.php` and `resources/views/components/production-bench/navigation.blade.php`.
- Modify `app/Livewire/ProductionBench/InventoryIndex.php` to show subject summaries.
- Create `app/Livewire/ProductionBench/InventoryDetail.php`.
- Create `resources/views/livewire/production-bench/inventory-detail.blade.php`;
  keep initial stock and adjustment as secondary actions on the inventory pages.
- Remove the superseded monolithic purchasing Blade view after the new routes are covered.

### Outputs and tests

- Create `app/Http/Controllers/ProcurementDocumentController.php`.
- Create `app/Services/ProcurementDocumentPresenter.php`.
- Create RFQ/PO print views under `resources/views/production-bench/documents/`;
  use the existing print JavaScript so users can print or save PDF without a new
  dependency.
- Add focused Pest files for supplier listings, procurement lifecycle, receipt behavior, price propagation, and inventory pages.
- Update the existing Production Bench, opening-stock, purchase-order, and stock-position tests.

---

### Task 1: Evolve the supplier and supplier-listing schema

**Files:**
- Create: `database/migrations/2026_07_28_170000_redesign_production_bench_suppliers_and_listings.php`
- Create: `app/ListingPriceBasis.php`
- Modify: `app/Models/Supplier.php`
- Modify: `app/Models/SupplierListing.php`
- Modify: `database/factories/SupplierFactory.php`
- Modify: `database/factories/SupplierListingFactory.php`
- Test: `tests/Feature/SupplierListingSchemaTest.php`

- [ ] **Step 1: Create the migration and test files with Artisan**

Run:

```bash
php artisan make:migration redesign_production_bench_suppliers_and_listings --no-interaction
php artisan make:test --pest SupplierListingSchemaTest --no-interaction
```

Expected: one migration and `tests/Feature/SupplierListingSchemaTest.php` are created.

- [ ] **Step 2: Write failing schema and model tests**

Cover structured supplier data, the exclusive ingredient/packaging relationship,
mass normalization, count normalization, and both price bases:

```php
it('stores a supplier purchase format with a normalized unit price', function (): void {
    $listing = SupplierListing::factory()->create([
        'purchase_format' => 'Drum',
        'net_quantity' => '200',
        'net_unit' => 'kg',
        'canonical_quantity_per_purchase_format' => '200000',
        'price_basis' => ListingPriceBasis::PerUnit,
        'price_amount' => '4.20',
        'price_unit' => 'kg',
        'total_price' => '840.00',
    ]);

    expect($listing->purchase_format)->toBe('Drum')
        ->and($listing->price_basis)->toBe(ListingPriceBasis::PerUnit)
        ->and($listing->total_price)->toBe('840.000000000');
});

it('rejects a listing that references both an ingredient and packaging item', function (): void {
    expect(fn () => SupplierListing::factory()->create([
        'ingredient_id' => Ingredient::factory(),
        'user_packaging_item_id' => UserPackagingItem::factory(),
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/SupplierListingSchemaTest.php
```

Expected: FAIL because the new fields and enum do not exist.

- [ ] **Step 4: Implement the additive migration and enum**

The migration must:

```php
Schema::table('suppliers', function (Blueprint $table): void {
    $table->string('address_line_1')->nullable();
    $table->string('address_line_2')->nullable();
    $table->string('city')->nullable();
    $table->string('region')->nullable();
    $table->string('postal_code', 32)->nullable();
    $table->string('country_code', 2)->nullable();
    $table->string('website')->nullable();
});

Schema::table('supplier_listings', function (Blueprint $table): void {
    $table->renameColumn('pack_description', 'purchase_format');
    $table->renameColumn('canonical_quantity_per_pack', 'canonical_quantity_per_purchase_format');
    $table->renameColumn('commercial_quantity', 'net_quantity');
    $table->renameColumn('commercial_unit', 'net_unit');
    $table->renameColumn('pack_price', 'total_price');
});

Schema::table('supplier_listings', function (Blueprint $table): void {
    $table->string('price_basis', 24)->default('total_purchase_format');
    $table->decimal('price_amount', 20, 9)->nullable();
    $table->string('price_unit', 24)->nullable();
    $table->timestamp('price_recorded_at')->nullable();
});

DB::table('supplier_listings')->update([
    'price_amount' => DB::raw('total_price'),
    'price_recorded_at' => DB::raw('updated_at'),
]);
```

Create:

```php
enum ListingPriceBasis: string
{
    case PerUnit = 'per_unit';
    case TotalPurchaseFormat = 'total_purchase_format';
}
```

Update fillable fields, casts, relations, and factories using the renamed columns.
Keep the PostgreSQL exclusive-subject check constraint intact.

- [ ] **Step 5: Run the schema test**

Run:

```bash
php artisan test --compact tests/Feature/SupplierListingSchemaTest.php
```

Expected: PASS.

- [ ] **Step 6: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
git add app/ListingPriceBasis.php app/Models/Supplier.php app/Models/SupplierListing.php database/factories/SupplierFactory.php database/factories/SupplierListingFactory.php database/migrations tests/Feature/SupplierListingSchemaTest.php
git commit -m "feat: redesign supplier purchase formats"
```

---

### Task 2: Normalize listing prices and update current ingredient costing

**Files:**
- Create: `app/Services/SupplierListingPriceCalculator.php`
- Create: `app/Actions/Purchasing/SaveSupplier.php`
- Create: `app/Actions/Purchasing/SaveSupplierListing.php`
- Modify: `app/Services/UserIngredientPriceMemory.php`
- Test: `tests/Unit/SupplierListingPriceCalculatorTest.php`
- Test: `tests/Feature/SupplierListingManagementTest.php`

- [ ] **Step 1: Create service, actions, and tests**

Run:

```bash
php artisan make:class Services/SupplierListingPriceCalculator --no-interaction
php artisan make:class Actions/Purchasing/SaveSupplier --no-interaction
php artisan make:class Actions/Purchasing/SaveSupplierListing --no-interaction
php artisan make:test --pest --unit SupplierListingPriceCalculatorTest --no-interaction
php artisan make:test --pest SupplierListingManagementTest --no-interaction
```

- [ ] **Step 2: Write failing normalization tests**

```php
it('derives total and price per kg from a per kg quote', function (): void {
    $price = app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: '200',
        netUnit: 'kg',
        basis: ListingPriceBasis::PerUnit,
        enteredPrice: '4.20',
        priceUnit: 'kg',
    );

    expect($price)->toMatchArray([
        'canonical_quantity' => '200000.000000000',
        'price_per_kg' => '4.200000000',
        'total_price' => '840.000000000',
    ]);
});

it('derives price per kg from a total drum quote', function (): void {
    $price = app(SupplierListingPriceCalculator::class)->forMass(
        netQuantity: '200',
        netUnit: 'kg',
        basis: ListingPriceBasis::TotalPurchaseFormat,
        enteredPrice: '900',
        priceUnit: null,
    );

    expect($price['price_per_kg'])->toBe('4.500000000');
});
```

The feature test must also prove a listing cannot use another workspace's supplier
or private ingredient/packaging item.

- [ ] **Step 3: Run the tests to verify failure**

Run:

```bash
php artisan test --compact tests/Unit/SupplierListingPriceCalculatorTest.php tests/Feature/SupplierListingManagementTest.php
```

Expected: FAIL because the service and actions have no behavior.

- [ ] **Step 4: Implement fixed-precision price normalization**

Use `MassConverter` and BCMath. The public service contract is:

```php
/**
 * @return array{
 *   canonical_quantity: string,
 *   price_per_canonical_unit: string,
 *   price_per_kg: string,
 *   total_price: string
 * }
 */
public function forMass(
    string $netQuantity,
    string $netUnit,
    ListingPriceBasis $basis,
    string $enteredPrice,
    ?string $priceUnit,
): array;

/**
 * @return array{
 *   canonical_quantity: string,
 *   price_per_canonical_unit: string,
 *   price_per_item: string,
 *   total_price: string
 * }
 */
public function forCount(
    string $netQuantity,
    ListingPriceBasis $basis,
    string $enteredPrice,
): array;
```

`SaveSupplierListing` validates workspace ownership and stores both the original
commercial entry and derived values. Saving a listing alone does not update formula
costing; only an applicable procurement or receipt price does.

- [ ] **Step 5: Run tests, format, and commit**

Run:

```bash
php artisan test --compact tests/Unit/SupplierListingPriceCalculatorTest.php tests/Feature/SupplierListingManagementTest.php
vendor/bin/pint --dirty --format agent
git add app/Actions/Purchasing app/Services/SupplierListingPriceCalculator.php app/Services/UserIngredientPriceMemory.php tests/Unit/SupplierListingPriceCalculatorTest.php tests/Feature/SupplierListingManagementTest.php
git commit -m "feat: normalize supplier listing prices"
```

Expected: all selected tests PASS.

---

### Task 3: Build focused supplier and listing pages

**Files:**
- Create: `app/Livewire/ProductionBench/Purchasing/SupplierIndex.php`
- Create: `app/Livewire/ProductionBench/Purchasing/SupplierDetail.php`
- Create: `app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php`
- Create: `resources/views/livewire/production-bench/purchasing/supplier-index.blade.php`
- Create: `resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php`
- Create: `resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php`
- Create: `resources/views/production-bench/purchasing/suppliers.blade.php`
- Create: `resources/views/production-bench/purchasing/supplier.blade.php`
- Create: `resources/views/production-bench/purchasing/listings.blade.php`
- Create: `resources/views/components/production-bench/purchasing-navigation.blade.php`
- Modify: `resources/views/components/production-bench/navigation.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductionBenchSupplierPagesTest.php`

- [ ] **Step 1: Write failing route and Livewire tests**

The test journey must:

```php
$this->actingAs($owner)
    ->get(route('production-bench.purchasing.suppliers'))
    ->assertOk()
    ->assertSee('Suppliers')
    ->assertDontSee('Receive delivery');

Livewire::test(SupplierDetail::class, ['supplier' => $supplier->public_id])
    ->set('listingSubjectType', 'ingredient')
    ->set('listingSubjectId', $ingredient->id)
    ->set('purchaseFormat', 'Drum')
    ->set('netQuantity', '200')
    ->set('netUnit', 'kg')
    ->set('priceBasis', 'per_unit')
    ->set('priceAmount', '4.20')
    ->set('priceUnit', 'kg')
    ->call('saveListing')
    ->assertHasNoErrors()
    ->assertSee('200 kg drum');
```

Also assert that cancelled Production Bench workspaces render these pages read-only.

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierPagesTest.php
```

Expected: FAIL because the routes and components do not exist.

- [ ] **Step 3: Add focused routes and local navigation**

Add named routes:

```php
Route::view('/purchasing/suppliers', 'production-bench.purchasing.suppliers')
    ->name('purchasing.suppliers');
Route::view('/purchasing/suppliers/{supplier}', 'production-bench.purchasing.supplier')
    ->name('purchasing.supplier');
Route::view('/purchasing/listings', 'production-bench.purchasing.listings')
    ->name('purchasing.listings');
```

The local Purchasing navigation contains Suppliers, Supplier listings, Quotation
requests, Purchase orders, and Receipts. Each view has one page heading, one primary
action, and no embedded form for another lifecycle stage.

- [ ] **Step 4: Implement supplier-centred pages**

`SupplierIndex` uses `WithPagination` with `search` and `active` filters.
`SupplierDetail` shows contact/address first, listings second, then recent
procurement and receipts. `SupplierListingIndex` searches supplier, material,
supplier SKU, and description.

Use full labels **Purchase format** and **Unit of measure**. Never render
**Commercial pack** or unexplained **UOM**.

- [ ] **Step 5: Verify responsive UI and run tests**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchPagesTest.php
npm run build
vendor/bin/pint --dirty --format agent
```

Expected: tests PASS and Vite build completes.

- [ ] **Step 6: Commit and pause for visual checkpoint**

```bash
git add app/Livewire/ProductionBench/Purchasing resources/views/livewire/production-bench/purchasing resources/views/production-bench/purchasing resources/views/components/production-bench routes/web.php tests/Feature
git commit -m "feat: add supplier centred purchasing pages"
```

Manual checkpoint URL:

`/dashboard/production-bench/purchasing/suppliers`

Do not start procurement-page styling until the user confirms the supplier and
listing hierarchy is understandable.

---

### Task 4: Add quotation-to-purchase-order persistence and transitions

**Files:**
- Create: `database/migrations/2026_07_28_170100_add_procurement_lifecycle_to_purchase_orders.php`
- Create: `app/ProcurementStage.php`
- Modify: `app/PurchaseOrderStatus.php`
- Modify: `app/Models/PurchaseOrder.php`
- Modify: `app/Models/PurchaseOrderLine.php`
- Modify: `database/factories/PurchaseOrderFactory.php`
- Modify: `database/factories/PurchaseOrderLineFactory.php`
- Modify: `app/Actions/Purchasing/CreatePurchaseOrder.php`
- Create: `app/Actions/Purchasing/IssueQuotationRequest.php`
- Create: `app/Actions/Purchasing/RecordProcurementLinePrice.php`
- Create: `app/Actions/Purchasing/ConvertQuotationToPurchaseOrder.php`
- Modify: `app/Actions/Purchasing/PlacePurchaseOrder.php`
- Test: `tests/Feature/ProcurementLifecycleTest.php`
- Test: `tests/Feature/ProcurementPricePropagationTest.php`

- [ ] **Step 1: Write failing lifecycle tests**

```php
$draft = app(CreatePurchaseOrder::class)->handle(
    actor: $owner,
    workspace: $workspace,
    supplier: $supplier,
    lines: [['listing' => $listing, 'purchase_formats' => 2]],
    stage: ProcurementStage::Quotation,
);

$issued = app(IssueQuotationRequest::class)->handle($owner, $draft);
expect($issued->status)->toBe(PurchaseOrderStatus::QuotationRequested)
    ->and($issued->quotation_reference)->not->toBeNull()
    ->and($issued->quotation_snapshot['lines'][0]['purchase_formats'])->toBe(2);

app(RecordProcurementLinePrice::class)->handle(
    actor: $owner,
    line: $issued->lines()->sole(),
    basis: ListingPriceBasis::PerUnit,
    amount: '4.75',
    unit: 'kg',
);

$order = app(ConvertQuotationToPurchaseOrder::class)->handle($owner, $issued);
expect($order->lines()->sole()->supplier_listing_id)->toBe($listing->id)
    ->and($order->quotation_snapshot)->not->toBeNull();
```

Price propagation tests assert that `UserIngredientPrice.price_per_kg` and current
formula costing rows update, while an existing receipt and completed production
snapshot stay unchanged.

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
php artisan test --compact tests/Feature/ProcurementLifecycleTest.php tests/Feature/ProcurementPricePropagationTest.php
```

Expected: FAIL on missing fields, enums, and actions.

- [ ] **Step 3: Add lifecycle columns**

Add nullable price columns on lines so RFQs can be unpriced, plus:

```php
$table->string('stage', 24)->default('purchase_order');
$table->string('quotation_reference', 64)->nullable();
$table->timestamp('quotation_requested_at')->nullable();
$table->jsonb('quotation_snapshot')->nullable();
$table->timestamp('price_confirmed_at')->nullable();
$table->timestamp('issued_at')->nullable();
$table->jsonb('purchase_order_snapshot')->nullable();
$table->jsonb('supplier_snapshot')->nullable();
$table->jsonb('delivery_address_snapshot')->nullable();
$table->decimal('shipping_amount', 20, 9)->default(0);
$table->decimal('discount_amount', 20, 9)->default(0);
$table->decimal('tax_amount', 20, 9)->default(0);
```

Rename `ordered_packs` to `ordered_purchase_formats`,
`canonical_quantity_per_pack` to
`canonical_quantity_per_purchase_format`, and `pack_price` to
`purchase_format_price`.

- [ ] **Step 4: Implement transactional transitions**

Statuses are:

```php
case Draft = 'draft';
case QuotationRequested = 'quotation_requested';
case QuotationReceived = 'quotation_received';
case Ordered = 'ordered';
case PartiallyReceived = 'partially_received';
case Received = 'received';
case Cancelled = 'cancelled';
```

Every transition:

- locks the procurement document;
- verifies workspace access and current status;
- snapshots supplier/listing/line data at issuance;
- generates references under the workspace lock;
- preserves the issued quotation snapshot during conversion;
- calls `UserIngredientPriceMemory::remember()` when an applicable ingredient
  price is recorded;
- never updates historical snapshots.

- [ ] **Step 5: Run tests, format, and commit**

```bash
php artisan test --compact tests/Feature/ProcurementLifecycleTest.php tests/Feature/ProcurementPricePropagationTest.php tests/Feature/PurchaseOrderControlsTest.php
vendor/bin/pint --dirty --format agent
git add app database/factories database/migrations tests/Feature
git commit -m "feat: add quotation to purchase order lifecycle"
```

Expected: selected tests PASS.

---

### Task 5: Generate RFQ and PO print/save-PDF and email-copy outputs

**Files:**
- Modify: `app/ProductionDocumentType.php`
- Create: `app/Services/ProcurementDocumentPresenter.php`
- Create: `app/Http/Controllers/ProcurementDocumentController.php`
- Create: `resources/views/production-bench/documents/quotation-request.blade.php`
- Create: `resources/views/production-bench/documents/purchase-order.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProcurementDocumentOutputTest.php`

- [ ] **Step 1: Write failing output tests**

```php
$this->actingAs($owner)
    ->get(route('production-bench.purchasing.documents.print', $order))
    ->assertOk()
    ->assertSee('data-print-document')
    ->assertSee($order->reference)
    ->assertSee('2 × 200 kg drum');

$this->actingAs($owner)
    ->getJson(route('production-bench.purchasing.documents.email', $order))
    ->assertOk()
    ->assertJsonPath('subject', "Purchase order {$order->reference}")
    ->assertJsonPath('body', fn (string $body): bool => str_contains($body, '2 × 200 kg drum'));
```

Add a mutation assertion: editing the supplier or listing after issuance does not
change output content.

- [ ] **Step 2: Run the test to verify failure**

Run:

```bash
php artisan test --compact tests/Feature/ProcurementDocumentOutputTest.php
```

Expected: FAIL because routes and presenter do not exist.

- [ ] **Step 3: Implement one snapshot presenter**

`ProcurementDocumentPresenter` accepts a `PurchaseOrder`, chooses the correct
immutable snapshot, and returns:

```php
/**
 * @return array{
 *   title: string,
 *   reference: string,
 *   supplier: array<string, mixed>,
 *   delivery_address: array<string, mixed>,
 *   lines: array<int, array<string, mixed>>,
 *   totals: array<string, string>,
 *   notes: ?string
 * }
 */
public function present(PurchaseOrder $procurement): array;

/** @return array{subject: string, body: string} */
public function emailCopy(PurchaseOrder $procurement): array;
```

Reuse `resources/views/layouts/print.blade.php` and
`resources/js/print-document.js`. The print route returns an authorized printable
HTML document; the browser's native print dialog provides paper printing and
save-as-PDF. The email route returns authorized JSON copy. No Composer dependency
is added.

- [ ] **Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/ProcurementDocumentOutputTest.php
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ProcurementDocumentController.php app/Services/ProcurementDocumentPresenter.php app/ProductionDocumentType.php resources/views/production-bench/documents routes/web.php tests/Feature/ProcurementDocumentOutputTest.php
git commit -m "feat: add procurement document outputs"
```

---

### Task 6: Build quotation and purchase-order pages

**Files:**
- Create: `app/Livewire/ProductionBench/Purchasing/ProcurementIndex.php`
- Create: `app/Livewire/ProductionBench/Purchasing/ProcurementEditor.php`
- Create: `resources/views/livewire/production-bench/purchasing/procurement-index.blade.php`
- Create: `resources/views/livewire/production-bench/purchasing/procurement-editor.blade.php`
- Create: `resources/views/production-bench/purchasing/quotations.blade.php`
- Create: `resources/views/production-bench/purchasing/orders.blade.php`
- Create: `resources/views/production-bench/purchasing/procurement.blade.php`
- Modify: `resources/views/components/production-bench/purchasing-navigation.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductionBenchProcurementPagesTest.php`

- [ ] **Step 1: Write failing Livewire journeys**

Test both entry paths:

- start an RFQ without prices;
- start a priced PO directly;
- add several listings from the selected supplier;
- issue RFQ;
- record returned per-kg prices;
- convert to PO without reselecting lines;
- issue PO;
- see Print / save PDF and Copy for email actions.

The critical assertion is:

```php
expect($converted->lines->pluck('supplier_listing_id')->all())
    ->toBe($originalListingIds);
```

- [ ] **Step 2: Run the page test to verify failure**

```bash
php artisan test --compact tests/Feature/ProductionBenchProcurementPagesTest.php
```

- [ ] **Step 3: Implement separate quotation and PO indexes**

Both indexes may reuse `ProcurementIndex` with a route-provided
`ProcurementStage`, but each has its own heading, empty state, filters, and primary
action. Do not combine both indexes and the editor on one screen.

The editor layout is:

1. supplier and delivery details;
2. line table with supplier listings and purchase-format quantities;
3. prices shown only when applicable;
4. order-level shipping, discount, and tax;
5. expected date and notes;
6. attachments;
7. one status-appropriate primary action.

- [ ] **Step 4: Verify UI and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchProcurementPagesTest.php tests/Feature/ProcurementLifecycleTest.php
npm run build
vendor/bin/pint --dirty --format agent
git add app/Livewire/ProductionBench/Purchasing resources/views/livewire/production-bench/purchasing resources/views/production-bench/purchasing resources/views/components/production-bench routes/web.php tests/Feature
git commit -m "feat: add quotation and purchase order workspace"
```

- [ ] **Step 5: Pause for visual checkpoint**

Review these flows before receipts:

- `/dashboard/production-bench/purchasing/quotations`
- `/dashboard/production-bench/purchasing/orders`

Confirm that lines are entered once and that RFQ-to-PO conversion is visually
obvious.

---

### Task 7: Redesign posted receipts and add direct receipts

**Files:**
- Create: `database/migrations/2026_07_28_170200_redesign_goods_receipts.php`
- Modify: `app/Models/GoodsReceipt.php`
- Modify: `app/Models/GoodsReceiptLine.php`
- Modify: receipt factories
- Modify: `app/Actions/Purchasing/ReceivePurchaseOrder.php`
- Create: `app/Actions/Purchasing/CreateDirectReceipt.php`
- Modify: `app/Actions/Purchasing/ReverseGoodsReceipt.php`
- Test: `tests/Feature/GoodsReceiptWorkflowTest.php`

- [ ] **Step 1: Write failing receipt tests**

Required cases:

```php
it('receives mass from nominal purchase format quantity', function (): void {
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'receipt-1',
        deliveryReference: 'DEL-42',
        lines: [[
            'order_line' => $massLine,
            'purchase_formats_received' => 2,
            'price_basis' => ListingPriceBasis::PerUnit,
            'price_amount' => '4.75',
            'price_unit' => 'kg',
            'supplier_batch_number' => 'SUP-BATCH-9',
        ]],
    );

    expect($receipt->lines()->sole()->actual_quantity)->toBe('400000.000000000')
        ->and($receipt->lines()->sole()->stockLot->status)->toBe(StockLotStatus::Released);
});

it('records an actual packaging shortage', function (): void {
    $receipt = app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'packaging-shortage',
        deliveryReference: null,
        lines: [[
            'order_line' => $packagingLine,
            'purchase_formats_received' => 1,
            'actual_count_received' => '995',
            'price_basis' => ListingPriceBasis::TotalPurchaseFormat,
            'price_amount' => '120',
            'price_unit' => null,
        ]],
    );

    expect($receipt->lines()->sole()->actual_quantity)->toBe('995.000000000');
});

it('refuses to post a receipt without a price', function (): void {
    $massLine->update([
        'price_basis' => null,
        'price_amount' => null,
        'price_unit' => null,
        'purchase_format_price' => null,
    ]);

    expect(fn () => app(ReceivePurchaseOrder::class)->handle(
        actor: $owner,
        order: $order,
        idempotencyKey: 'missing-price',
        deliveryReference: null,
        lines: [[
            'order_line' => $massLine,
            'purchase_formats_received' => 1,
        ]],
    ))->toThrow(ValidationException::class);
});
```

Also prove direct receipt requires a supplier and listing, and does not create a
purchase order.

- [ ] **Step 2: Run tests to verify failure**

```bash
php artisan test --compact tests/Feature/GoodsReceiptWorkflowTest.php
```

- [ ] **Step 3: Evolve receipt persistence**

Make `purchase_order_id` and `purchase_order_line_id` nullable for direct receipts.
Add mandatory `supplier_id` and `supplier_listing_id` snapshots, receipt reference,
price basis/amount/unit, purchase-format price, and canonical unit cost. Rename
`packs_received` to `purchase_formats_received`.

- [ ] **Step 4: Implement receipt rules in one transaction**

For mass:

```php
$canonicalQuantity = bcmul(
    $line->canonical_quantity_per_purchase_format,
    (string) $purchaseFormatsReceived,
    9,
);
```

For packaging, use `actual_count_received`, require a positive whole count, and
allow it to differ from the nominal expected count.

Every posted receipt:

- has a price, prefilled from the PO or entered at receipt;
- snapshots the price;
- updates the listing's latest price;
- updates ingredient indicative price through `UserIngredientPriceMemory`;
- creates a released stock lot and immutable movement;
- supports partial PO status calculation;
- reverses with compensating movements only.

- [ ] **Step 5: Run receipt and ledger tests, then commit**

```bash
php artisan test --compact tests/Feature/GoodsReceiptWorkflowTest.php tests/Feature/PurchaseOrderControlsTest.php tests/Feature/StockLedgerSchemaTest.php
vendor/bin/pint --dirty --format agent
git add app database/factories database/migrations tests/Feature
git commit -m "feat: add practical production receipts"
```

---

### Task 8: Build dedicated receipt pages and document attachments

**Files:**
- Create: `app/Livewire/ProductionBench/Purchasing/ReceiptIndex.php`
- Create: `app/Livewire/ProductionBench/Purchasing/ReceiptEditor.php`
- Create: `resources/views/livewire/production-bench/purchasing/receipt-index.blade.php`
- Create: `resources/views/livewire/production-bench/purchasing/receipt-editor.blade.php`
- Create: `resources/views/production-bench/purchasing/receipts.blade.php`
- Create: `resources/views/production-bench/purchasing/receipt.blade.php`
- Modify: `resources/views/components/production-bench/purchasing-navigation.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductionBenchReceiptPagesTest.php`

- [ ] **Step 1: Write failing page tests**

Test:

- receive against an open order;
- prefilled known price;
- required price when the PO is unpriced;
- no mass “actual weight” field;
- optional supplier batch and expiry;
- actual count for packaging;
- direct purchase that still requires supplier and listing;
- private invoice, receipt, delivery note, and CoA attachments.

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --compact tests/Feature/ProductionBenchReceiptPagesTest.php
```

- [ ] **Step 3: Implement focused receipt UI**

The receipts index shows recent receipts and a clear choice:

- **Receive purchase order**
- **Record direct purchase**

The editor reveals only fields applicable to the selected line type. Mass shows:

`Number of drums × 200 kg = 400 kg added`

Packaging shows nominal count and an editable actual count. There is no ingredient
quarantine selector.

- [ ] **Step 4: Verify files, accessibility, build, and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchReceiptPagesTest.php
npm run build
vendor/bin/pint --dirty --format agent
git add app/Livewire/ProductionBench/Purchasing resources/views routes/web.php tests/Feature/ProductionBenchReceiptPagesTest.php
git commit -m "feat: add dedicated receipt workspace"
```

---

### Task 9: Require supplier provenance and price for initial stock

**Files:**
- Create: `database/migrations/2026_07_28_170300_link_opening_stock_to_supplier_listings.php`
- Modify: `app/Models/StockLot.php`
- Modify: `database/factories/StockLotFactory.php`
- Modify: `app/Actions/Inventory/CreateOpeningStockLot.php`
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Test: `tests/Feature/OpeningStockLedgerTest.php`

- [ ] **Step 1: Replace direct ingredient opening-stock tests**

The new contract is:

```php
$lot = app(CreateOpeningStockLot::class)->handle(
    actor: $owner,
    workspace: $workspace,
    supplierListing: $listing,
    quantity: '73.5',
    unit: 'kg',
    priceBasis: ListingPriceBasis::PerUnit,
    priceAmount: '4.60',
    priceUnit: 'kg',
    idempotencyKey: 'opening-olive-oil',
    supplierBatchNumber: 'SUP-42',
);

expect($lot->supplier_listing_id)->toBe($listing->id)
    ->and($lot->historical_unit_cost)->toBe('0.004600000')
    ->and($lot->status)->toBe(StockLotStatus::Released);
```

Add failures for missing listing, other-workspace listing, missing price, and
fractional packaging count.

- [ ] **Step 2: Run to verify failure**

```bash
php artisan test --compact tests/Feature/OpeningStockLedgerTest.php
```

- [ ] **Step 3: Implement listing-linked opening stock**

Add nullable `supplier_listing_id` to `stock_lots` for compatibility with existing
production-output lots, but require it in `CreateOpeningStockLot`.

Initial stock accepts remaining mass/count rather than purchase-format count,
always creates a released lot, calculates historical unit cost, updates the latest
applicable listing/ingredient price, and posts one opening movement.

- [ ] **Step 4: Move opening stock to a secondary flow**

Remove the large opening-stock form from the top of Inventory. Keep an **Initial
stock setup** action that opens a focused form or route. The first selection is
Supplier, then Supplier listing; the ingredient/packaging item is displayed from
the listing and is not independently selectable.

- [ ] **Step 5: Test and commit**

```bash
php artisan test --compact tests/Feature/OpeningStockLedgerTest.php tests/Feature/ProductionBenchPagesTest.php
npm run build
vendor/bin/pint --dirty --format agent
git add app database resources tests/Feature
git commit -m "feat: link initial stock to supplier listings"
```

---

### Task 10: Replace the lot ledger with inventory summaries and detail

**Files:**
- Create: `app/Actions/Inventory/AdjustStock.php`
- Modify: `app/Services/StockPositionService.php`
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Create: `app/Livewire/ProductionBench/InventoryDetail.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Create: `resources/views/livewire/production-bench/inventory-detail.blade.php`
- Create: `resources/views/production-bench/inventory-detail.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/InventorySummaryPagesTest.php`
- Test: `tests/Feature/StockAdjustmentTest.php`
- Modify: `tests/Unit/StockPositionServiceTest.php`

- [ ] **Step 1: Write failing summary and adjustment tests**

Summary assertions:

```php
$this->actingAs($owner)
    ->get(route('production-bench.inventory'))
    ->assertOk()
    ->assertSee('Physical')
    ->assertSee('Reserved')
    ->assertSee('Available')
    ->assertSee('Incoming')
    ->assertDontSee('Quarantined');
```

Detail assertions cover internal lots, supplier batch, received price, incoming PO,
documents, and immutable movement history.

Adjustment assertions prove a `-2.4 kg` adjustment can make stock negative, requires
a reason, and adds a `StockCountAdjustment` movement without editing older rows.

- [ ] **Step 2: Run tests to verify failure**

```bash
php artisan test --compact tests/Feature/InventorySummaryPagesTest.php tests/Feature/StockAdjustmentTest.php tests/Unit/StockPositionServiceTest.php
```

- [ ] **Step 3: Implement subject-level summaries**

Return:

```php
[
    'physical' => $physical,
    'reserved' => $reserved,
    'available' => bcsub($physical, $reserved, 9),
    'incoming' => $incoming,
]
```

Forecast requirements remain separate and do not reduce available stock. Negative
values remain un-clamped.

- [ ] **Step 4: Implement inventory detail and adjustment**

`InventoryDetail` resolves exactly one workspace-accessible ingredient or packaging
item by public ID and shows its aggregated positions, lots, receipts, supplier
listings, open PO lines, documents, and movements.

`AdjustStock::handle()` accepts actor, lot, signed quantity, unit, reason, and
idempotency key; it posts a new `StockCountAdjustment` movement and never edits the
lot's prior movements.

- [ ] **Step 5: Verify UI and commit**

```bash
php artisan test --compact tests/Feature/InventorySummaryPagesTest.php tests/Feature/StockAdjustmentTest.php tests/Unit/StockPositionServiceTest.php
npm run build
vendor/bin/pint --dirty --format agent
git add app resources routes/web.php tests
git commit -m "feat: add production inventory summaries"
```

- [ ] **Step 6: Pause for visual checkpoint**

Review:

- `/dashboard/production-bench/inventory`
- one ingredient detail;
- initial stock setup;
- stock adjustment.

Confirm the default screen answers physical/reserved/available without exposing the
full lot ledger first.

---

### Task 11: Remove the superseded page and run the complete verification

**Files:**
- Delete: `app/Livewire/ProductionBench/PurchasingIndex.php`
- Delete: `resources/views/livewire/production-bench/purchasing-index.blade.php`
- Delete or redirect: `resources/views/production-bench/purchasing.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ProductionBenchPagesTest.php`
- Modify: any affected localization files under `lang/`

- [ ] **Step 1: Add redirect and terminology regression assertions**

The old `/purchasing` route redirects to the supplier index. Assert rendered
Production Bench pages do not contain:

- `Commercial pack`
- `UOM`
- `Actual net quantity` for mass receipts
- `Quarantine until checked`
- `Price pending`

- [ ] **Step 2: Remove the monolithic component and old route view**

Delete only after every operation is reachable from a focused route and covered by
feature tests.

- [ ] **Step 3: Run focused suites**

```bash
php artisan test --compact tests/Feature/SupplierListingSchemaTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProcurementLifecycleTest.php tests/Feature/ProcurementPricePropagationTest.php tests/Feature/ProcurementDocumentOutputTest.php tests/Feature/ProductionBenchProcurementPagesTest.php tests/Feature/GoodsReceiptWorkflowTest.php tests/Feature/ProductionBenchReceiptPagesTest.php tests/Feature/OpeningStockLedgerTest.php tests/Feature/InventorySummaryPagesTest.php tests/Feature/StockAdjustmentTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/PurchaseOrderControlsTest.php tests/Unit/SupplierListingPriceCalculatorTest.php tests/Unit/StockPositionServiceTest.php
```

Expected: all selected tests PASS.

- [ ] **Step 4: Run project-required static and frontend checks**

```bash
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
```

No `app/Filament` file is expected to change. If one does change, also run:

```bash
vendor/bin/filacheck --fix
```

- [ ] **Step 5: Run the complete test suite**

```bash
php -d memory_limit=512M vendor/bin/pest --compact
```

Expected: all existing and new tests PASS; existing skipped tests remain skipped.

- [ ] **Step 6: Refresh the code graph**

```bash
graphify update .
```

Expected: `graphify-out/GRAPH_REPORT.md` and graph artifacts refresh successfully.

- [ ] **Step 7: Final commit**

```bash
git add app database resources routes tests lang graphify-out
git commit -m "feat: complete purchasing and inventory redesign"
```

The final handoff must list the three visual checkpoints, focused/full test results,
and the preview URLs generated through Laravel Boost.
