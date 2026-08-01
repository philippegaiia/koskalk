# Production Bench Supplier UX Correction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make supplier and supplier-listing management operational, concise, and discoverable, with required customer-defined supplier codes and dedicated creation/editing pages.

**Architecture:** Add supplier code through an additive migration and enforce normalization/uniqueness in `SaveSupplier`. Move supplier and listing mutations out of index/detail components into focused Livewire pages while reusing existing actions, price services, workspace scoping, and read-only entitlement checks. Keep list/detail pages read-oriented and remove promotional or future-feature copy.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Blade, Tailwind CSS 4, PostgreSQL, Pest 4.

---

### Task 1: Add required supplier codes

**Files:**
- Create: `database/migrations/2026_08_01_100000_add_code_to_suppliers_table.php`
- Modify: `app/Models/Supplier.php`
- Modify: `database/factories/SupplierFactory.php`
- Modify: `app/Actions/Purchasing/SaveSupplier.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierIndex.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierDetail.php`
- Test: `tests/Feature/SupplierCodeTest.php`
- Modify: `tests/Feature/SupplierListingManagementTest.php`
- Modify: existing supplier factories/usages affected by the required field

- [ ] **Step 1: Write failing persistence and action tests**

Cover migration backfill, required validation, uppercase/trim normalization,
allowed characters, 16-character limit, case-insensitive uniqueness within a
workspace, same code in another workspace, update uniqueness ignoring the current
supplier, and unchanged `public_id` after code edits.

```php
it('requires a workspace unique customer supplier code', function (): void {
    $supplier = app(SaveSupplier::class)->handle($owner, $workspace, [
        'code' => ' oleva_01 ',
        'name' => 'Oleva',
        'default_currency' => 'EUR',
        'is_active' => true,
    ]);

    expect($supplier->code)->toBe('OLEVA_01');

    expect(fn () => app(SaveSupplier::class)->handle($owner, $workspace, [
        'code' => 'oleva_01',
        'name' => 'Another supplier',
        'default_currency' => 'EUR',
        'is_active' => true,
    ]))->toThrow(ValidationException::class);
});
```

- [ ] **Step 2: Run the tests and confirm RED**

```bash
php artisan test --compact tests/Feature/SupplierCodeTest.php tests/Feature/SupplierListingManagementTest.php
```

Expected: FAIL because `suppliers.code` and validation do not exist.

- [ ] **Step 3: Add the migration and model support**

Create the migration with Artisan. Add nullable `code` first, backfill existing
rows deterministically as `SUP-<id>`, resolve any case-insensitive collision, then
make the column non-null. Add a workspace/code index and a PostgreSQL functional
unique index on `(workspace_id, LOWER(code))`; preserve equivalent protection in
SQLite tests. `down()` removes indexes and the column.

Add `code` to Supplier fillable data and provide coherent factory defaults using a
maximum of 16 characters.

- [ ] **Step 4: Enforce supplier-code rules in `SaveSupplier`**

Normalize with `strtoupper(trim($code))`, then validate:

```php
'code' => [
    'required',
    'string',
    'max:16',
    'regex:/^[A-Z0-9_-]+$/',
    Rule::unique('suppliers', 'code')
        ->where(fn (Builder $query): Builder => $query->where('workspace_id', $workspace->id))
        ->ignore($supplier?->id),
],
```

Because PostgreSQL string comparison is case-sensitive by default, perform the
application uniqueness check against `LOWER(code)` as well as retaining the
database functional index. Include code in SupplierIndex/SupplierDetail state only
to keep existing components compiling until Task 2 replaces their forms.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/SupplierCodeTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/ProductionBenchSupplierPagesTest.php
vendor/bin/pint --dirty --format agent
git diff --check
git add app database tests
git commit -m "feat: require supplier codes"
```

---

### Task 2: Create dedicated supplier pages and neutralize supplier copy

**Files:**
- Create: `app/Livewire/ProductionBench/Purchasing/SupplierCreate.php`
- Create: `app/Livewire/ProductionBench/Purchasing/SupplierEdit.php`
- Create: `resources/views/livewire/production-bench/purchasing/supplier-create.blade.php`
- Create: `resources/views/livewire/production-bench/purchasing/supplier-edit.blade.php`
- Create: `resources/views/production-bench/purchasing/supplier-create.blade.php`
- Create: `resources/views/production-bench/purchasing/supplier-edit.blade.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierIndex.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierDetail.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-index.blade.php`
- Modify: `resources/views/livewire/production-bench/purchasing/supplier-detail.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductionBenchSupplierFormPagesTest.php`
- Modify: `tests/Feature/ProductionBenchSupplierPagesTest.php`

- [ ] **Step 1: Write failing page tests**

Assert dedicated authenticated routes, inactive/read-only behavior, New supplier
creation and redirect, Edit supplier and stable UUID, code display/search, and the
absence of embedded forms/promotional/future-feature copy on index/detail pages.

```php
$this->actingAs($owner)
    ->get(route('production-bench.purchasing.suppliers.create'))
    ->assertOk()
    ->assertSee('New supplier');

Livewire::test(SupplierCreate::class)
    ->set('code', 'OIL_FR_01')
    ->set('name', 'French Oils')
    ->set('defaultCurrency', 'EUR')
    ->call('save')
    ->assertHasNoErrors()
    ->assertRedirect();
```

- [ ] **Step 2: Confirm RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php
```

- [ ] **Step 3: Implement focused create/edit components**

Both components delegate to `SaveSupplier`, use the same state names and field
grouping, and enforce ProductionBenchAccess server-side. Create redirects to the
new supplier detail. Edit resolves only a current-workspace supplier, saves without
changing `public_id`, and redirects to detail. Cancel links return without writes.

Add routes:

```php
Route::view('/purchasing/suppliers/new', 'production-bench.purchasing.supplier-create')
    ->name('purchasing.suppliers.create');
Route::view('/purchasing/suppliers/{supplier}/edit', 'production-bench.purchasing.supplier-edit')
    ->name('purchasing.suppliers.edit');
```

Place `/new` before `/{supplier}`.

- [ ] **Step 4: Simplify index/detail pages**

Remove mutation properties/methods and forms from SupplierIndex/SupplierDetail.
Index shows title, Add supplier, filters, and rows containing code. Detail shows
facts, Edit supplier, Add listing, and listings only. Remove sales-oriented
paragraphs and quotation/order/receipt placeholder cards. Neutral empty states are
`No suppliers.` and `No supplier listings.`.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchEntitlementTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
git add app resources routes tests
git commit -m "refactor: focus supplier management pages"
```

---

### Task 3: Create a dedicated supplier-listing page

**Files:**
- Create: `app/Livewire/ProductionBench/Purchasing/SupplierListingCreate.php`
- Create: `resources/views/livewire/production-bench/purchasing/supplier-listing-create.blade.php`
- Create: `resources/views/production-bench/purchasing/supplier-listing-create.blade.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierDetail.php`
- Modify: `app/Livewire/ProductionBench/Purchasing/SupplierListingIndex.php`
- Modify: their Blade views
- Modify: `routes/web.php`
- Test: `tests/Feature/ProductionBenchSupplierListingCreatePageTest.php`
- Modify: `tests/Feature/ProductionBenchSupplierPagesTest.php`

- [ ] **Step 1: Write failing listing-page tests**

Cover global creation requiring Supplier, supplier-detail creation with supplier
preselected and immutable, successful return destinations, cancel, price preview,
mass/packaging units, workspace isolation, read-only rejection, and absence of the
embedded listing form from supplier detail.

- [ ] **Step 2: Confirm RED**

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchSupplierPagesTest.php
```

- [ ] **Step 3: Implement one reusable component and route**

Use an optional supplier public ID query/route parameter. The global Add listing
route displays a required searchable Supplier field. The supplier-detail route
passes the supplier and renders it as locked context. Delegate writes to
`SaveSupplierListing`; reuse `SupplierListingPriceCalculator` and existing exact
price-preview/presentation helpers.

Add named routes for global create and supplier-scoped create. Successful scoped
creation returns to supplier detail; global creation returns to listings index.

- [ ] **Step 4: Remove embedded listing state/form and simplify copy**

SupplierDetail becomes read-only apart from links. SupplierListingIndex gains a
primary Add listing link. Retain search/filter/pagination and exact price output.
Remove explanatory paragraphs that repeat headings; retain only short pricing help.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/SupplierListingManagementTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
git add app resources routes tests
git commit -m "refactor: add focused supplier listing creation"
```

---

### Task 4: Final copy and regression verification

**Files:**
- Modify: supplier/listing Blade views as required by copy audit
- Modify: relevant supplier/listing Pest tests

- [ ] **Step 1: Add copy and route regressions**

Assert operational pages do not contain promotional introductions, `coming later`,
future quotation/order/receipt cards, embedded create forms, or the removed salesy
phrases. Assert Add supplier, Edit supplier, and Add listing links resolve.

- [ ] **Step 2: Run focused and complete verification**

```bash
php artisan test --compact tests/Feature/SupplierCodeTest.php tests/Feature/SupplierListingManagementTest.php tests/Feature/ProductionBenchSupplierPagesTest.php tests/Feature/ProductionBenchSupplierFormPagesTest.php tests/Feature/ProductionBenchSupplierListingCreatePageTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchEntitlementTest.php
npm run build
vendor/bin/pint --dirty --format agent
git diff --check
graphify update .
```

- [ ] **Step 3: Commit any final corrections**

```bash
git add app database resources routes tests graphify-out
git commit -m "fix: complete supplier workspace correction"
```

The handoff pauses at the actual Suppliers and Supplier listings pages before Task
4 of the broader purchasing plan begins.
