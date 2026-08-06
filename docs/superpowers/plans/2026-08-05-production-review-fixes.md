# Production Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct confirmed production-planning defects and wire manual exchange-rate recovery into goods-receipt posting without changing the workspace-currency decision.

**Architecture:** Keep validation at the action/service boundary, make flash generation bounded before proposal allocation, and preserve existing Livewire workflows. Reuse the existing exchange-rate snapshot fields so manual rates remain auditable and immutable.

**Tech Stack:** Laravel 13, Livewire 4, Pest 4, PHP 8.5, SQLite/MySQL-compatible Eloquent queries.

---

### Task 1: Use task-type default durations in flash simulations

**Files:**
- Modify: `app/Services/Production/FlashProductionSimulator.php`
- Test: `tests/Feature/FlashProductionSimulatorTest.php`

- [x] Add a test task-set item whose explicit duration is null and whose task type default duration is 30 minutes; simulate two batches and assert the line and total task minutes equal 60.
- [x] Run the focused test and confirm it fails because the simulator currently sums only nullable item durations.
- [x] Eager-load `items.taskType` in the simulator task-set lookup and sum `duration_minutes ?? taskType->default_duration_minutes`.
- [x] Run `php artisan test --compact tests/Feature/FlashProductionSimulatorTest.php`.

### Task 2: Bound flash batch generation

**Files:**
- Modify: `app/Services/Production/FlashProductionSimulator.php`
- Modify: `app/Services/Production/FlashDateProposalService.php`
- Modify: `app/Actions/Production/GenerateFlashProductions.php`
- Test: `tests/Feature/FlashProductionSimulatorTest.php`
- Test: `tests/Feature/ProductionBenchProductionsTest.php`

- [x] Add a regression test that rejects a flash request whose calculated total batches exceeds the domain maximum before constructing proposals or productions.
- [x] Run the focused test and confirm it fails because no server-side cap exists.
- [x] Add one shared domain maximum for a flash submission and validate it in the simulator/action boundary; return a field validation error rather than allocating an unbounded proposal array.
- [x] Keep the existing positive-number validation and make the cap apply to every caller, not only browser inputs.
- [x] Run the focused production planning tests.

### Task 3: Surface invalid employee and task-operation errors

**Files:**
- Modify: `app/Livewire/ProductionBench/Production/ProductionDetail.php`
- Modify: `resources/views/livewire/production-bench/production/production-detail.blade.php`
- Test: `tests/Feature/ProductionBenchProductionsTest.php`

- [x] Add Livewire tests for an invalid employee ID and a failed complete/reopen operation, asserting an error is visible and the task remains unchanged.
- [x] Run the tests and confirm the invalid employee currently performs no assignment and task-operation errors have no rendered error target.
- [x] Reject non-empty employee IDs that do not resolve to an active employee in the current workspace.
- [x] Render the generic task-operation error key alongside the existing employee error.
- [x] Run the focused Livewire production tests; Filament check was not applicable because no `app/Filament` files changed.

### Task 4: Add manual exchange-rate recovery to receipt posting

**Files:**
- Modify: `app/Actions/Purchasing/PostGoodsReceiptLine.php`
- Modify: `app/Actions/Purchasing/ReceivePurchaseOrder.php`
- Modify: `app/Actions/Purchasing/ReceiveDirectGoodsReceipt.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/ReceiptCreate.php`
- Modify: `resources/views/livewire/production-bench/purchasing/receipt-create.blade.php`
- Test: `tests/Feature/CurrencyAwareReceiptCostingTest.php`
- Test: `tests/Feature/DirectGoodsReceiptPostingTest.php`
- Test: `tests/Feature/ProductionBenchGoodsReceiptPagesTest.php`

- [x] Add tests for provider failure with a supplied manual rate (successful posting with `provider = manual`) and provider failure without a manual rate (validation error and no receipt/stock mutation).
- [x] Run the tests and confirm the first case cannot currently be completed because receipt input has no manual-rate field.
- [x] Add an optional per-receipt manual rate input for cross-currency lines, pass it through both PO and direct receipt actions, and pass it to `ExchangeRateService::snapshot()`.
- [x] Keep identity-currency lines automatic and validate manual rates as positive decimal values.
- [x] Preserve the existing rate/date/provider/manual snapshot fields on both receipt lines and lots.
- [x] Run the focused receipt and currency tests.

### Task 5: Harden adjustment compensation and document decisions

**Files:**
- Modify: `app/Actions/Inventory/AddStockLotCostAdjustment.php`
- Test: `tests/Feature/StockLotCostAdjustmentTest.php`
- Modify: `docs/superpowers/plans/2026-08-04-currency-aware-receipt-costing.md`

- [x] Add a test that attempting to compensate the same adjustment twice is rejected and does not create a second compensating row.
- [x] Run the test and confirm repeated compensation is currently accepted.
- [x] Add a workspace-scoped existence check for an existing compensation before creating another one, while retaining reuse of the original exchange-rate snapshot.
- [x] Update the currency plan to state that Bench costing uses the workspace default currency and the picker is intentionally read-only.
- [x] Run the adjustment test suite and format modified PHP with `vendor/bin/pint --dirty --format agent`.

### Task 6: Final verification

- [x] Run the focused suites for flash planning, production details, receipts, currency costing, and stock-lot adjustments.
- [x] Run `graphify update .` after code changes.
- [x] Run the production build after frontend assets changed.
- [x] Review `git diff` and ensure the pre-existing inventory worktree changes remain untouched.

Full-suite verification: 1,581 passed, 20 skipped, with one pre-existing failure in
`GoodsReceiptDocumentUiAndInventoryTest` because the inventory query-count expectation remains at 1 while the
current implementation issues 16 movement queries. This work did not modify that inventory behavior.
