# Currency-Aware Receipt Costing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make receipt and inventory costing understandable and safe when supplier prices, workspace costing currency, shipping, duties, VAT and later price corrections differ.

**Architecture:** Keep supplier/PO/receipt amounts in their transaction currency. Convert into the workspace `default_currency` through a cached exchange-rate provider and persist the rate snapshot with the receipt/lot cost. Keep posted receipts immutable; represent later price, shipping, duty and non-recoverable-tax changes as immutable lot-cost adjustments. Production Runs will consume the resulting effective lot cost in the next phase.

**Tech Stack:** Laravel 13, Eloquent migrations/models/actions, Livewire 4, Pest 4, Tailwind CSS 4, Laravel HTTP client.

---

### Task 1: Define currency and acquisition-cost contracts with failing tests

**Files:**
- Create: `app/Contracts/ExchangeRateProvider.php`
- Create: `app/Data/ExchangeRateSnapshot.php`
- Create: `app/StockCostAdjustmentType.php`
- Test: `tests/Feature/CurrencyAwareReceiptCostingTest.php`

- [ ] Write tests proving that a same-currency conversion returns a rate of `1`, a cross-currency conversion returns a deterministic provider rate, and receipt cost adjustments support positive and negative amounts with a required reason/type.
- [ ] Run `php artisan test --compact tests/Feature/CurrencyAwareReceiptCostingTest.php` and verify the new tests fail because the contracts and adjustment type do not exist.
- [ ] Implement the smallest typed contract/data object and enum needed by those tests.
- [ ] Run the focused test file again and verify it passes.

### Task 2: Add cached exchange-rate snapshots

**Files:**
- Create: `app/Services/FrankfurterExchangeRateProvider.php`
- Create: `app/Services/ExchangeRateService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/CurrencyAwareReceiptCostingTest.php`

- [ ] Add an HTTP-faked test for USD→EUR conversion, including the provider date and rate in the returned snapshot.
- [ ] Add a test proving the same daily pair is served from cache without a second HTTP request.
- [ ] Add a test proving manual conversion is accepted when the provider is unavailable and the failure is not silently hidden.
- [ ] Implement the provider using `https://api.frankfurter.dev/v2/rate/{base}/{quote}` with explicit timeout/connect-timeout, validation and cache keyed by date/base/quote.
- [ ] Bind the provider contract through dependency injection.
- [ ] Run the focused tests and keep the HTTP client fully faked.

### Task 3: Persist transaction and workspace-currency receipt costs

**Files:**
- Create: `database/migrations/2026_08_04_100000_add_currency_snapshots_to_receipt_costs.php`
- Modify: `app/Models/GoodsReceiptLine.php`
- Modify: `app/Models/StockLot.php`
- Modify: `app/Actions/Purchasing/PostGoodsReceiptLine.php`
- Modify: `app/Services/CurrentMaterialPriceService.php`
- Test: `tests/Feature/DirectGoodsReceiptPostingTest.php`
- Test: `tests/Feature/ProductionBenchGoodsReceiptPagesTest.php`

- [ ] Add failing assertions that a USD receipt in an EUR workspace preserves USD receipt values and stores EUR converted cost, rate, provider and rate date.
- [ ] Add failing assertions that same-currency receipts store a `1` rate without an external request.
- [ ] Add the migration and casts for transaction cost, workspace cost, functional currency, exchange rate, rate date, provider and manual-rate flag.
- [ ] Convert receipt/lot acquisition cost once at posting, persist the snapshot, and pass only the workspace-currency value to `CurrentMaterialPrice` and Bench propagation.
- [ ] Keep supplier listing and PO transaction values unchanged.
- [ ] Run the focused receipt tests and then the existing receipt suite.

### Task 4: Add immutable lot-cost adjustments

**Files:**
- Create: `database/migrations/2026_08_04_100001_create_stock_lot_cost_adjustments_table.php`
- Create: `app/Models/StockLotCostAdjustment.php`
- Create: `app/Actions/Inventory/AddStockLotCostAdjustment.php`
- Modify: `app/Models/StockLot.php`
- Modify: `app/Services/CurrentMaterialPriceService.php`
- Test: `tests/Feature/StockLotCostAdjustmentTest.php`

- [ ] Write failing tests for shipping, import duty, non-recoverable tax, discount and price-correction adjustments; verify VAT can be excluded by entering no adjustment.
- [ ] Write failing tests proving adjustments are immutable, require a type/reason, cannot cross workspaces, and do not create quantity movements.
- [ ] Implement the adjustment table with original amount/currency plus workspace-currency amount and rate snapshot.
- [ ] Calculate and expose effective lot cost as original receipt cost plus immutable adjustments.
- [ ] Update `CurrentMaterialPrice` from the effective cost when an adjustment is posted, without rewriting the original receipt line or lot acquisition snapshot.
- [ ] Add compensating adjustment support instead of deleting an adjustment.
- [ ] Run focused tests and the stock-ledger tests.

### Task 5: Make Bench/current-price currency semantics explicit

**Files:**
- Modify: `app/Services/LiveCostingPricePropagationService.php`
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `app/Services/ProductionSnapshotService.php`
- Modify: `lang/en/production_bench.php`
- Test: `tests/Feature/RecipeVersionCostingTest.php`
- Test: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] Add tests proving Bench rows always receive the workspace costing currency and never a raw supplier-currency number.
- [ ] Add tests proving recorded production snapshots retain their copied cost when current prices or lot adjustments change.
- [ ] Update labels/help text to say “costing currency” and clarify that Workbench price edits update the workspace current price.
- [ ] Keep production lot consumption out of this slice; preserve the existing `raw_material_lot_id` boundary for the next Production Run implementation.

### Task 6: Improve receipt-entry table readability

**Files:**
- Modify: `resources/views/livewire/production-bench/purchasing/receipt-create.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/receipt-detail.blade.php`
- Modify: `resources/css/app.css` only if an existing shared table utility must be extended
- Test: `tests/Feature/ProductionBenchGoodsReceiptPagesTest.php`

- [ ] Add a test for visible labels: item, ordered, previously received, remaining, receive now, actual quantity, supplier price, inventory cost and currency.
- [ ] Replace the current dense grid with explicit column spans/widths, light body rows, retained header background, right-aligned numeric cells and an expandable details region for batch/expiry/notes/cost additions.
- [ ] Keep the table keyboard accessible and horizontally scrollable on narrow tablets; do not hide required inputs.
- [ ] Run Filament/Tailwind checks where applicable and render the affected feature tests.

### Task 7: Full verification and handoff

**Files:**
- Modify only files required by the preceding tasks.

- [ ] Run `vendor/bin/pint --dirty --format agent`.
- [ ] Run `vendor/bin/filacheck --fix` if any Filament files were touched.
- [ ] Run `php artisan test --compact` for the focused receipt, costing, inventory and production snapshot suites.
- [ ] Run `git diff --check` and `graphify update .`.
- [ ] Run the production frontend build if the receipt view changed.
- [ ] Review the final diff for transaction-currency preservation, workspace-currency conversion, adjustment auditability, and no silent receipt mutation.
