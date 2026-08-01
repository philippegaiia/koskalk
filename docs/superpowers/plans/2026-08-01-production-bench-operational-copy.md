# Production Bench Operational Copy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce every Production Bench view to factual, task-oriented copy.

**Architecture:** Change visible labels and helper text only. Preserve existing Livewire state, Filament schemas, actions, routes, calculations, and access rules. Lock the copy standard with rendered-page Pest tests.

**Tech Stack:** Laravel 13, Livewire 4, Filament 5 forms, Blade, Pest 4.

---

### Task 1: Add operational-copy regressions

**Files:**
- Modify: `tests/Feature/ProductionBenchPagesTest.php`
- Modify: `tests/Feature/ProductionBenchSupplierPagesTest.php`
- Modify: `tests/Feature/ProductionBenchSupplierFormPagesTest.php`
- Modify: `tests/Feature/ProductionBenchSupplierListingCreatePageTest.php`

- [ ] **Step 1: Write failing rendered-copy assertions**

Assert concise labels such as `Inactive`, `Read-only. Resume to edit.`, `Opening stock`, `Search`, `Status`, and `No lots.`. Assert the rendered pages do not contain introductions, reassurance, obvious-control explanations, future-feature language, or the longer phrases being replaced.

- [ ] **Step 2: Run tests to verify failure**

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
```

Expected: failures on the old visible wording.

### Task 2: Apply concise operational wording

**Files:**
- Modify: `resources/views/components/production-bench/navigation.blade.php`
- Modify: `resources/views/components/production-bench/purchasing-navigation.blade.php`
- Modify: `resources/views/livewire/production-bench/home-index.blade.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-listing-index.blade.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierCreate.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierEdit.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`

- [ ] **Step 1: Replace view copy**

Apply the approved copy rule from the design document. Keep only essential stock definitions, supplier-code constraints, purchase-format examples, minimum-order meaning, and calculated price.

- [ ] **Step 2: Run focused tests to verify green**

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php
```

Expected: all tests pass.

### Task 3: Verify and commit

**Files:**
- Modify: `graphify-out/*`

- [ ] **Step 1: Run the complete Production Bench suite**

```bash
php artisan test --compact tests/Feature/SupplierCodeTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchEntitlementTest.php
```

- [ ] **Step 2: Run quality checks**

```bash
vendor/bin/filacheck --fix
vendor/bin/pint --dirty --format agent
npm run build
git diff --check
graphify update .
```

- [ ] **Step 3: Commit**

```bash
git add app resources tests docs graphify-out
git commit -m "fix: reduce production bench copy"
```
