# Production Inventory, Purchasing, and Setup Contextual Help Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Explain the stock, purchasing, and configuration concepts that feed Production Bench calculations and lifecycle actions.

**Architecture:** Inventory, purchasing, and setup keep separate translation groups and page-level topic subsets. Shared Production Bench registries and triggers from the earlier plans are reused. Help explains operational consequences, while existing Livewire actions and inline confirmation copy remain authoritative for mutations.

**Tech Stack:** Laravel language files, Blade, Livewire 4, shared contextual-help foundation, Spatie Translation Loader, Pest 4, WordPress.

---

## File map

**Create:**

- `lang/en/help_production_inventory.php`
- `lang/en/help_production_purchasing.php`
- `lang/en/help_production_setup.php`
- `tests/Feature/ContextualHelpProductionSupplySetupTest.php`

**Modify:**

- `resources/views/livewire/production-bench/inventory-index.blade.php`
- all purchasing views under `resources/views/livewire/production-bench/purchasing/`
- `resources/views/livewire/production-bench/purchasing-index.blade.php`
- setup views under `resources/views/livewire/production-bench/production/`
- `database/seeders/data/interface-translations.json`
- focused Production Bench inventory, purchasing, and settings tests.

### Task 1: Define inventory, purchasing, and setup content

- [ ] **Step 1: Generate the failing feature test**

Run: `php artisan make:test --pest ContextualHelpProductionSupplySetupTest --no-interaction`

- [ ] **Step 2: Assert the inventory topic inventory**

```text
help_production_inventory.quantities.physical
help_production_inventory.quantities.reserved
help_production_inventory.quantities.available
help_production_inventory.requirements.requirement_vs_allocation
help_production_inventory.lots.identity
help_production_inventory.lots.expiry
help_production_inventory.movements.adjustment
help_production_inventory.movements.negative_stock
help_production_inventory.output.manufactured_lot
help_production_inventory.output.quarantine_and_release
```

- [ ] **Step 3: Assert the purchasing topic inventory**

```text
help_production_purchasing.suppliers.supplier
help_production_purchasing.suppliers.listing
help_production_purchasing.units.purchase_unit
help_production_purchasing.currency.listing_currency
help_production_purchasing.procurement.requirement
help_production_purchasing.procurement.quotation_request
help_production_purchasing.orders.purchase_order
help_production_purchasing.receipts.goods_receipt
help_production_purchasing.receipts.quantity_conversion
help_production_purchasing.costing.inventory_cost
help_production_purchasing.costing.receipt_reversal
```

- [ ] **Step 4: Assert the setup topic inventory**

```text
help_production_setup.batch_sizes.preset
help_production_setup.numbering.planning_reference
help_production_setup.numbering.batch_number
help_production_setup.tasks.task_type
help_production_setup.tasks.task_set
help_production_setup.organization.department
help_production_setup.organization.employee
help_production_setup.calendar.working_day
help_production_setup.calendar.exception
```

Every topic must pass the foundation validator. Consequential topics include `what_to_do` and `why`.

- [ ] **Step 5: Verify red and author English content**

Run: `php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php`

Content must preserve these distinctions:

- Physical stock is recorded quantity; reserved stock is committed to planned production; available stock is the unreserved remainder used for readiness.
- A requirement states what production needs; an allocation identifies the stock lot intended to satisfy it.
- Negative stock is an exception requiring reconciliation, not normal availability.
- Supplier listings own purchase unit and currency; receipt conversion determines inventory quantity.
- Goods receipt and reversal affect lots and costing history.
- Planning references and batch numbers remain different identifiers.
- Task types are reusable definitions; task sets are ordered production templates.
- Calendar exceptions override ordinary working-day rules.

- [ ] **Step 6: Synchronize and review all text translations**

Run `php artisan translations:sync`, draft every blank text field for `de`, `es`, `fr`, `it`, `nl`, and `pt_BR`, review quantities, currencies, stock consequences, and irreversible actions in context, then run `php artisan translations:catalogue:export`. Leave `article_url` absent until publication.

- [ ] **Step 7: Verify and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
git add lang/en/help_production_inventory.php lang/en/help_production_purchasing.php lang/en/help_production_setup.php database/seeders/data/interface-translations.json tests/Feature/ContextualHelpProductionSupplySetupTest.php
git commit -m "feat: add production supply and setup help content"
```

### Task 2: Add Inventory help

**Files:** inventory view and focused inventory tests.

- [ ] **Step 1: Add failing page and trigger assertions**

The overview mode registers Physical, Reserved, Available, Requirement versus allocation, and Negative stock. The stock mode adds Lot identity, Expiry, and Adjustment. Manufactured output and quarantine states register their output topics. Add visible `Why?` triggers for negative stock and quarantined output.

- [ ] **Step 2: Verify red and implement**

Run: `php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchInventoryModalTest.php`

Pass the exact mode-specific topic list to `<x-production-bench.page>`. Add section and state triggers without changing filters, modal state, stock calculations, or adjustment actions.

- [ ] **Step 3: Run regression and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryModalTest.php tests/Feature/ProductionExecutionTest.php
git add resources/views/livewire/production-bench/inventory-index.blade.php tests/Feature/ContextualHelpProductionSupplySetupTest.php
git commit -m "feat: add Production Inventory help"
```

### Task 3: Add supplier and listing help

**Files:** supplier index, create, detail, edit, listing index, listing create, and tests.

- [ ] **Step 1: Assert page subsets**

Supplier pages register Supplier. Listing pages register Listing, Purchase unit, and Listing currency. The listing form has direct triggers beside unit and currency and keeps both fields locked after selecting a listing where the existing workflow requires it.

- [ ] **Step 2: Verify red and add triggers**

Run:

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
```

Use page and field triggers only. Do not alter supplier persistence, listing unit, listing currency, or catalog-item creation.

- [ ] **Step 3: Verify and commit**

```bash
git add resources/views/livewire/production-bench/purchasing/supplier-index.blade.php resources/views/livewire/production-bench/purchasing/supplier-create.blade.php resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php resources/views/livewire/production-bench/purchasing/supplier-edit.blade.php resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php resources/views/livewire/production-bench/purchasing/supplier-listing-create.blade.php tests/Feature/ContextualHelpProductionSupplySetupTest.php
git commit -m "feat: add supplier and listing help"
```

### Task 4: Add procurement, order, receipt, and costing help

**Files:** purchasing index plus procurement and receipt views and tests.

- [ ] **Step 1: Assert exact workflow mappings**

- Procurement lists and details: Requirement, Quotation request, Purchase order.
- Purchase-order creation: Purchase order, Listing, Purchase unit, Listing currency.
- Receipt creation and detail: Goods receipt, Quantity conversion, Inventory cost.
- Reversal controls: Receipt reversal with a visible `Why?` trigger; existing confirmation remains inline.

- [ ] **Step 2: Verify red and implement without mutation changes**

Run:

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchProcurementPagesTest.php tests/Feature/ProductionBenchGoodsReceiptPagesTest.php
```

Pass explicit topic lists to the common page component and add direct triggers. Do not change totals, currencies, unit conversion, receipt posting, lot creation, cost adjustments, or reversal actions.

- [ ] **Step 3: Run purchasing regression and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchProcurementPagesTest.php tests/Feature/ProductionBenchGoodsReceiptPagesTest.php tests/Feature/GoodsReceiptDocumentUiAndInventoryTest.php tests/Feature/GoodsReceiptSchemaAndImmutabilityTest.php
git add resources/views/livewire/production-bench/purchasing-index.blade.php resources/views/livewire/production-bench/purchasing/procurement-index.blade.php resources/views/livewire/production-bench/purchasing/procurement-create.blade.php resources/views/livewire/production-bench/purchasing/procurement-detail.blade.php resources/views/livewire/production-bench/purchasing/receipt-index.blade.php resources/views/livewire/production-bench/purchasing/receipt-create.blade.php resources/views/livewire/production-bench/purchasing/receipt-detail.blade.php tests/Feature/ContextualHelpProductionSupplySetupTest.php
git commit -m "feat: add purchasing workflow help"
```

### Task 5: Add Production Setup help

**Files:** numbering, settings, batch-size, task-set, and calendar setup views and tests.

- [ ] **Step 1: Assert setup mappings**

- Numbering page: Planning reference and Batch number.
- Batch-size pages: Preset.
- Settings index: Department, Employee, Task type.
- Task-set pages: Task type and Task set.
- Calendar setup: Working day and Exception.

- [ ] **Step 2: Verify red and implement**

Run:

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/ProductionBatchSizePagesTest.php tests/Feature/ProductionTaskSetPagesTest.php
```

Add topic arrays and selective triggers. Do not change numbering assignment, preset applicability, task ordering, organization records, or calendar rules.

- [ ] **Step 3: Run setup regression and commit**

```bash
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ProductionBenchProductionSettingsTest.php tests/Feature/ProductionRunNumberSettingsPageTest.php tests/Feature/ProductionBatchSizePagesTest.php tests/Feature/ProductionTaskSetPagesTest.php tests/Feature/ProductionBenchProductionCalendarTest.php
git add resources/views/livewire/production-bench/production/numbering-settings.blade.php resources/views/livewire/production-bench/production/settings-index.blade.php resources/views/livewire/production-bench/production/batch-size-index.blade.php resources/views/livewire/production-bench/production/batch-size-form.blade.php resources/views/livewire/production-bench/production/task-set-index.blade.php resources/views/livewire/production-bench/production/task-set-form.blade.php tests/Feature/ContextualHelpProductionSupplySetupTest.php
git commit -m "feat: add Production Setup help"
```

### Task 6: Publish supply guides and complete translations

- [ ] **Step 1: Publish English WordPress guides**

```text
/docs/inventory/physical-reserved-and-available-stock/
/docs/inventory/lots-expiry-and-adjustments/
/docs/inventory/manufactured-output-and-release/
/docs/purchasing/suppliers-and-listings/
/docs/purchasing/procurement-and-purchase-orders/
/docs/purchasing/goods-receipts-conversion-and-costing/
/docs/production/batch-size-and-numbering-setup/
/docs/production/tasks-organization-and-calendars/
```

Add exact anchors and English URLs only after publication.

- [ ] **Step 2: Review translations and add published links**

Review existing `de`, `es`, `fr`, `it`, `nl`, and `pt_BR` values in the rendered page. Preserve quantities, negations, currency meaning, stock consequences, and irreversible actions. Add localized guide URLs only after their articles are live.

- [ ] **Step 3: Export and verify**

```bash
php artisan translations:catalogue:export
php artisan test --compact tests/Feature/ContextualHelpProductionSupplySetupTest.php tests/Feature/ContextualHelpContentTest.php tests/Feature/ProductionBenchLocalizationTest.php tests/Feature/InterfaceTranslationCatalogueTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
```

- [ ] **Step 4: Commit translated supply and setup help**

```bash
git add database/seeders/data/interface-translations.json tests/Feature/ContextualHelpProductionSupplySetupTest.php
git commit -m "feat: translate production supply help"
```
