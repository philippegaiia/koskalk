# Production Bench Phase 2 Purchasing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Let makers maintain direct supplier listings for existing ingredients and packaging, order supplier pack multiples, receive partial deliveries into distinct internal lots, and preserve historical prices, supplier batches, and private documents.

**Architecture:** Supplier listings reference exactly one existing ingredient or packaging record with database-enforced direct foreign keys. Purchase-order lines snapshot every commercial and costing field when created so later listing changes cannot rewrite history. Receipt posting locks the order and lines, creates distinct receipt lots, posts immutable receipt movements, and derives historical per-gram or per-count costs from actual received quantity. The Inventory projection adds incoming quantities from ordered but unreceived packs.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4, BCMath, PostgreSQL transactions and row locking, existing private media processing.

---

## Invariants

- Every supplier and purchasing record is workspace-owned.
- One supplier may have multiple listings for the same Soapkraft ingredient.
- A listing directly references exactly one ingredient or packaging item.
- Commercial volume descriptions are metadata; inventory arithmetic remains mass or count.
- Purchased packs are positive whole numbers.
- Expected quantity equals packs multiplied by canonical mass/count per pack.
- Expected cost equals packs multiplied by snapshotted pack price.
- Listing edits never change existing order-line snapshots.
- Partial receipts post only actual received quantity.
- Every receipt delivery creates a distinct internal lot.
- Several receipt lots may share one supplier batch number.
- Actual received quantity may differ from listing expectation.
- Received-lot historical unit cost is derived from receipt economics and never follows later listing edits.
- Receipt retries are idempotent.
- Reversal posts compensating movements and never deletes receipt history.
- CoAs and material certificates attach to received lots, not only generic suppliers.

## Task 1: Suppliers and Direct Listings

**Files:**

- Create: `app/Models/Supplier.php`
- Create: `app/Models/SupplierListing.php`
- Create: `database/migrations/*_create_suppliers_table.php`
- Create: `database/migrations/*_create_supplier_listings_table.php`
- Create: `database/factories/SupplierFactory.php`
- Create: `database/factories/SupplierListingFactory.php`
- Create: `app/Policies/SupplierPolicy.php`
- Create: `app/Policies/SupplierListingPolicy.php`
- Create: `tests/Feature/SupplierListingTest.php`

### Step 1: Write failing supplier/listing tests

Cover workspace isolation, direct subject constraint, several listings for one olive-oil ingredient and supplier, commercial descriptions such as `5-gallon pail`, expected canonical mass/count per pack, pack price/currency, active state, and read-only rejection after cancellation.

Expected: FAIL because supplier tables do not exist.

### Step 2: Generate and implement models

Suppliers keep identity, contact details, default currency, notes, and active state. Listings keep supplier SKU/name, commercial pack description/container, expected canonical quantity, unit kind, optional original commercial quantity/unit, pack price, currency, minimum packs, notes, and active state.

Use direct `ingredient_id` and `user_packaging_item_id` columns with an exactly-one database check. Ingredient expected quantity is canonical grams; packaging expected quantity is integer count.

### Step 3: Verify and commit

```bash
git add app database tests/Feature/SupplierListingTest.php
git commit -m "feat: add supplier listings"
```

## Task 2: Purchase Orders and Receipt Schema

**Files:**

- Create: `app/PurchaseOrderStatus.php`
- Create: `app/GoodsReceiptStatus.php`
- Create: `app/Models/PurchaseOrder.php`
- Create: `app/Models/PurchaseOrderLine.php`
- Create: `app/Models/GoodsReceipt.php`
- Create: `app/Models/GoodsReceiptLine.php`
- Create: `database/migrations/*_create_purchase_orders_table.php`
- Create: `database/migrations/*_create_purchase_order_lines_table.php`
- Create: `database/migrations/*_create_goods_receipts_table.php`
- Create: `database/migrations/*_create_goods_receipt_lines_table.php`
- Create: `database/factories/PurchaseOrderFactory.php`
- Create: `database/factories/PurchaseOrderLineFactory.php`
- Create: `database/factories/GoodsReceiptFactory.php`
- Create: `database/factories/GoodsReceiptLineFactory.php`
- Create: `tests/Feature/PurchaseOrderSchemaTest.php`

### Step 1: Write failing schema tests

Assert draft/ordered/partially-received/received/cancelled statuses; supplier/workspace ownership; snapshotted listing identity, subject, SKU, description, pack quantity, canonical content, pack price and currency; receipt-to-order links; actual quantity; supplier batch; dates; idempotency keys; and distinct internal lots per receipt line.

### Step 2: Generate and implement the schema

Purchase orders store a human reference, supplier, status, order/expected dates, currency, and notes. Lines snapshot every commercial field and store integer ordered packs.

Goods receipts store supplier delivery reference, receipt date, status, notes, actor, idempotency key, and optional reversal date/actor. Receipt lines store order line, packs received, actual canonical quantity, original entered quantity/unit, historical total cost, generated stock lot, supplier batch, and optional expiry.

### Step 3: Verify and commit

```bash
git add app database tests/Feature/PurchaseOrderSchemaTest.php
git commit -m "feat: add purchasing ledger schema"
```

## Task 3: Order Snapshot and Lifecycle Actions

**Files:**

- Create: `app/Actions/Purchasing/CreatePurchaseOrder.php`
- Create: `app/Actions/Purchasing/PlacePurchaseOrder.php`
- Create: `app/Actions/Purchasing/CancelPurchaseOrder.php`
- Create: `tests/Feature/PurchaseOrderLifecycleTest.php`

### Step 1: Write failing lifecycle tests

Prove:

- ordering three packs snapshots the listing and calculates expected quantity/cost;
- multiple listings for one ingredient remain independently selectable;
- changing the listing afterward leaves the order unchanged;
- only draft orders can be edited/placed;
- cancelled orders cannot receive;
- cancellation and all writes fail in read-only mode.

### Step 2: Implement actions

`CreatePurchaseOrder` validates same-workspace supplier/listings, snapshots lines in one transaction, and assigns a readable reference. `PlacePurchaseOrder` locks the draft and marks it ordered. `CancelPurchaseOrder` locks an unreceived order and marks it cancelled.

### Step 3: Verify and commit

```bash
git add app/Actions/Purchasing tests/Feature/PurchaseOrderLifecycleTest.php
git commit -m "feat: snapshot supplier purchase orders"
```

## Task 4: Partial Receipt Posting and Reversal

**Files:**

- Create: `app/Actions/Purchasing/ReceivePurchaseOrder.php`
- Create: `app/Actions/Purchasing/ReverseGoodsReceipt.php`
- Modify: `app/Services/InternalLotCodeGenerator.php`
- Modify: `app/Services/StockPositionService.php`
- Create: `tests/Feature/GoodsReceiptPostingTest.php`

### Step 1: Write failing receipt tests

Cover:

- partial receipt of one line;
- actual received mass differing from expected pack mass;
- two deliveries sharing one supplier batch but creating distinct internal lots;
- unique internal lot codes;
- received-lot cost per gram/count based on snapshotted economics and actual quantity;
- ordered → partially received → received transitions;
- duplicate idempotency key returning the same receipt without duplicate stock;
- over-receipt rejection;
- compensating reversal movement and reversed status;
- listing edits not changing received lot cost.

### Step 2: Implement receipt actions

`ReceivePurchaseOrder` runs in a retried transaction, locks the order and lines, verifies remaining packs, creates receipt records, creates one released or quarantined lot per receipt line, posts one purchase-receipt movement per lot, and updates the order lifecycle atomically.

`ReverseGoodsReceipt` locks the receipt and lots, posts linked negative receipt-reversal movements, marks the receipt reversed, and recalculates the order status. It never deletes lots, lines, or movements.

### Step 3: Extend incoming projection

`StockPositionService` adds outstanding expected canonical quantities from ordered/partially received order lines for the same subject. Actual receipt variance affects physical stock, while incoming follows packs still outstanding.

### Step 4: Verify and commit

```bash
git add app/Actions/Purchasing app/Services tests/Feature/GoodsReceiptPostingTest.php
git commit -m "feat: post partial goods receipts"
```

## Task 5: Purchasing Documents at the Point of Work

**Files:**

- Create: `app/Actions/Purchasing/UploadProductionDocument.php`
- Modify: `app/Actions/Inventory/AttachProductionDocument.php`
- Create: `tests/Feature/ProductionDocumentUploadTest.php`

### Step 1: Write failing upload tests

Use fake private storage and queue handling to prove PDF/image validation, same-workspace ownership, purchase confirmation/invoice/receipt/delivery-note attachments to orders/receipts, and CoA/SDS/specification/certificate attachment to a received lot. Prove one media asset may be attached to several internal lots sharing a supplier batch.

### Step 2: Implement upload and attachment

Reuse `MediaAssetUploadService` with PDF/image types. Create the typed `ProductionDocument` immediately for processing or ready assets in the same workspace. Keep download/preview authorization through the existing private media routes.

### Step 3: Verify and commit

```bash
git add app/Actions tests/Feature/ProductionDocumentUploadTest.php
git commit -m "feat: attach private purchasing documents"
```

## Task 6: Customer Purchasing Workspace

**Files:**

- Create: `app/Livewire/ProductionBench/PurchasingIndex.php`
- Create: `resources/views/production-bench/purchasing.blade.php`
- Create: `resources/views/livewire/production-bench/purchasing-index.blade.php`
- Modify: `resources/views/components/production-bench/navigation.blade.php`
- Modify: `routes/web.php`
- Modify: `lang/en/production_bench.php`
- Create: `tests/Feature/ProductionBenchPurchasingTest.php`

### Step 1: Write failing Livewire tests

Test the complete customer flow:

- create a supplier;
- create several listings for the same olive oil;
- see pack quantity and total pack mass/count clearly;
- order several packs;
- place the order;
- partially receive actual mass with supplier batch and expiry;
- receive the remainder as a separate internal lot;
- see receipt documents and lot certificates;
- see updated Physical and Incoming stock;
- preserve every view but block mutations after cancellation.

### Step 2: Implement the coherent purchasing page

Use one Purchasing workspace with compact Suppliers, Listings, Orders, and Receipts views. Avoid giant forms and modal chains:

- supplier details expand inline;
- listings sit under their supplier and show the linked Soapkraft item first;
- the order builder selects pack listings and whole pack quantities;
- the order detail exposes one primary lifecycle action;
- receipt entry shows expected remaining packs beside actual received quantity;
- documents are added where the maker is already working.

Keep controls tablet-friendly and use semantic color only for ordered, partial, received, cancelled, quarantine, and shortage states.

### Step 3: Verify and commit

```bash
git add app/Livewire resources/views routes lang tests/Feature/ProductionBenchPurchasingTest.php
git commit -m "feat: add purchasing workspace"
```

## Task 7: Checkpoint 2 Review Experience

**Files:**

- Modify: `app/Livewire/ProductionBench/HomeIndex.php`
- Modify: `resources/views/livewire/production-bench/home-index.blade.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `database/seeders/data/interface-translations.json`
- Create: `tests/Feature/ProductionBenchCheckpointTwoTest.php`

### Step 1: Add the integrated review test

Build one scenario from opening stock through supplier listing, three-pack order, partial receipt, repeated supplier batch on the final delivery, private CoA, and final stock truth. Assert historical listing/order/lot prices remain independent.

### Step 2: Polish home and inventory integration

Home shows arriving orders, quarantined receipts, and provenance/document attention. Inventory links receipt lots back to the supplier, order, and receipt without turning the page into an ERP table.

### Step 3: Complete translations and accessibility

Synchronize reviewed catalogue entries, semantic headings, form labels, focus states, validation copy, keyboard reachability, and tablet overflow behavior.

### Step 4: Run checkpoint verification

```bash
php -d memory_limit=512M vendor/bin/pest --compact tests/Feature/SupplierListingTest.php tests/Feature/PurchaseOrderSchemaTest.php tests/Feature/PurchaseOrderLifecycleTest.php tests/Feature/GoodsReceiptPostingTest.php tests/Feature/ProductionDocumentUploadTest.php tests/Feature/ProductionBenchPurchasingTest.php tests/Feature/ProductionBenchCheckpointTwoTest.php
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
graphify update .
```

Then run the complete Pest suite and perform the opening-stock → listing → order → partial receipt → lot review in the dedicated preview.

