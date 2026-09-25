# Receipt Reversal and Targeted Indexes Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task. Checkboxes track execution. This document is a plan, not authorization to deploy or to modify the populated development database.

**Goal:** Remove receipt-reversal query amplification and add four justified indexes, then review the remaining index findings without converting them into blanket indexing requirements.

**Architecture:** Eager-load the costing relationships at the receipt-reversal caller. Preserve the existing costing methods, pricing selection, authorization, transactions, and index migration regression tests. Treat the remaining-index assessment as a separate deliverable; it must not delay shipping the first two changes or silently approve every outstanding finding.

**Tech Stack:** PHP 8.5, Laravel 13.30.1, PostgreSQL 17, Pest 4.7.8, Laravel Truss 1.11.1. Normal feature tests use isolated in-memory SQLite; PostgreSQL migration verification uses an explicitly disposable database.

---

## Scope and evidence

Verified on September 25, 2026:

- The local PostgreSQL schema has 69 `TRUSS-IDX-001` findings, four `TRUSS-IDX-003` warnings, and one `TRUSS-INT-001` finding. These are structural findings, not measured production latency.
- Of the 69 missing-index findings, 33 concern actor/audit fields. Audit-field status does not itself justify an exception: parent deletion and reverse queries still matter.
- Receipt-reversal fallback currently causes two receipt-line relationship reads and one cost-adjustment read per surviving candidate lot.
- `tests/Feature/ForeignKeyIndexCoverageTest.php` and `tests/Feature/IngredientEditorAccessTest.php` previously passed together: 36 tests, 754 assertions. This is a prior baseline, not proof that the proposed code below has run.
- The earlier evaluation's assertion that component tests are absent is false. No new testing dependency is needed.

Read `AGENTS.md`, `.ai/rules/index.md`, applicable rule files, and the Laravel/testing skills again if execution happens in a new session. Reconfirm installed versions and the Git diff before editing. Use Boost documentation search before relying on new package APIs.

## File map

| File | Responsibility |
| --- | --- |
| `app/Actions/Purchasing/RebuildCurrentMaterialPriceAfterReceiptReversal.php` | Load all relationships needed by fallback receipt costing |
| `tests/Feature/PurchaseOrderControlsTest.php` | Regression coverage through the real reversal action |
| `database/migrations/<Artisan-generated timestamp>_add_query_path_foreign_key_indexes.php` | Four reversible index additions; retain the generated timestamp |
| `tests/Feature/ForeignKeyIndexCoverageTest.php` | Extend the existing index manifest and round-trip coverage |
| This plan's final assessment section | Record the subsequent remaining-index review and evidence |

There is no planned change to `StockLot.php`, dependencies, UI components, domain names, or service namespaces.

## Task 1: Reproduce the receipt-reversal performance defect

**Modify:** `tests/Feature/PurchaseOrderControlsTest.php`.

- [x] Read its existing receipt-price restoration tests, especially `restores the newest still-posted receipt price after reversing a later receipt`, plus the adjustment fixtures in `tests/Feature/StockLotCostAdjustmentTest.php`.
- [x] Add the following imports to the existing test file; keep its existing imports and `RefreshDatabase` declaration:

```php
use App\Actions\Inventory\AddStockLotCostAdjustment;
use App\Enums\StockLotCostAdjustmentType;
use App\Enums\StockLotOrigin;
use App\Models\GoodsReceiptLine;
```

- [x] Add this regression test. Factories create historical receipt lines with null snapshots from the outset; do not mutate immutable posted lines or bypass their model protections. Two dataset cases exercise the same behavior with different candidate counts.

```php
it('rebuilds adjusted receipt prices with bounded relationship reads', function (int $candidateCount): void {
    $this->travelTo(now()->setDate(2026, 9, 25)->startOfDay());
    Http::preventStrayRequests();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create([
        'default_currency' => 'EUR',
    ]);
    app(ProductionBenchAccess::class)->activate($owner, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create();
    $listing = SupplierListing::factory()
        ->for($workspace)
        ->for($supplier)
        ->for($ingredient)
        ->create(['currency' => 'EUR', 'is_active' => false]);
    $lots = [];
    $receipts = [];

    for ($position = 0; $position <= $candidateCount; $position++) {
        $createdAt = now()->subDays($candidateCount - $position);
        $receipt = GoodsReceipt::factory()->direct()
            ->for($workspace)
            ->for($supplier)
            ->create([
                'received_by_user_id' => $owner->id,
                'received_at' => $createdAt->toDateString(),
                'created_at' => $createdAt,
            ]);
        $lot = StockLot::factory()->for($workspace)->for($ingredient)->create([
            'supplier_listing_id' => $listing->id,
            'origin' => StockLotOrigin::PurchaseReceipt,
            'historical_unit_cost' => '0.010000000',
            'costing_unit_cost' => '0.010000000',
            'currency' => 'EUR',
            'costing_currency' => 'EUR',
        ]);
        $line = GoodsReceiptLine::factory()->direct()->create([
            'goods_receipt_id' => $receipt->id,
            'supplier_listing_id' => $listing->id,
            'stock_lot_id' => $lot->id,
            'previous_material_price_snapshot' => null,
            'actual_quantity' => '5000',
            'historical_total_cost' => '50',
            'costing_total_cost' => '50',
        ]);
        StockMovement::factory()->create([
            'workspace_id' => $workspace->id,
            'stock_lot_id' => $lot->id,
            'type' => StockMovementType::PurchaseReceipt,
            'quantity_delta' => '5000',
            'original_quantity' => '5',
            'original_unit' => 'kg',
            'source_type' => $line->getMorphClass(),
            'source_id' => $line->id,
        ]);
        $lots[] = $lot;
        $receipts[] = $receipt;
    }

    $winningLot = $lots[$candidateCount - 1];
    $reversedLot = $lots[$candidateCount];
    $reversedReceipt = $receipts[$candidateCount];
    app(AddStockLotCostAdjustment::class)->handle(
        actor: $owner,
        workspace: $workspace,
        lot: $winningLot,
        type: StockLotCostAdjustmentType::Shipping,
        amount: '10',
        currency: 'EUR',
        reason: 'Delivery charge included in fallback costing',
    );
    $currentPrice = CurrentMaterialPrice::query()
        ->where('workspace_id', $workspace->id)
        ->where('ingredient_id', $ingredient->id)
        ->sole();
    $currentPrice->update([
        'source_type' => MaterialPriceSource::Receipt,
        'source_id' => $reversedLot->id,
        'price_per_canonical_unit' => '0.010000000000',
        'recorded_at' => now(),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        app(ReverseGoodsReceipt::class)->handle($owner, $reversedReceipt, 'Duplicate delivery');
        $queries = collect(DB::getQueryLog())->map(
            fn (array $query): string => strtolower(str_replace(['"', '`'], '', $query['query'])),
        );
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($currentPrice->refresh()->price_per_canonical_unit)->toBe('0.012000000000');
    expect($currentPrice->source_type)->toBe(MaterialPriceSource::Receipt);
    expect((int) $currentPrice->source_id)->toBe($winningLot->id);
    expect($currentPrice->currency)->toBe('EUR');
    expect($reversedReceipt->refresh()->status)->toBe(GoodsReceiptStatus::Reversed);
    expect(bccomp((string) $reversedLot->movements()->sum('quantity_delta'), '0', 9))->toBe(0);

    $receiptRelationshipReads = $queries->filter(
        fn (string $sql): bool => preg_match(
            '/^select .* from goods_receipt_lines where (?:goods_receipt_lines\.)?stock_lot_id\b/',
            $sql,
        ) === 1,
    );
    $adjustmentReads = $queries->filter(
        fn (string $sql): bool => str_starts_with($sql, 'select ')
            && str_contains($sql, ' from stock_lot_cost_adjustments '),
    );
    expect($receiptRelationshipReads->count())->toBeLessThanOrEqual(1);
    expect($adjustmentReads->count())->toBeLessThanOrEqual(1);
})->with([2, 7]);
```

The expected price is independently specified: `(EUR 50 + EUR 10) / 5,000 g = EUR 0.012/g`. Explicit `created_at` values matter because the existing ranking prefers receipt creation time. Inactive listings and the absence of orders keep the fallback decision focused on receipts.

- [x] Run the regression before changing application code:

```bash
php artisan test --compact tests/Feature/PurchaseOrderControlsTest.php --filter='rebuilds adjusted receipt prices with bounded relationship reads'
```

Expected: the behavior assertions pass, but the relationship-query bounds fail. If fixture setup fails, correct the fixture using the installed APIs and sibling tests; do not weaken the query assertions. Confirm the SQL matcher detects the old receipt-line queries. The 2-candidate case should expose four receipt-line relationship reads and two adjustment reads; the 7-candidate case should expose fourteen and seven respectively.

## Task 2: Fix the caller and verify behavior

**Modify:** `app/Actions/Purchasing/RebuildCurrentMaterialPriceAfterReceiptReversal.php`, only the receipt-candidate query inside `newestCandidate()`.

- [x] Replace its current `with(['goodsReceipt', 'stockLot'])` with:

```php
->with([
    'goodsReceipt',
    'stockLot.goodsReceiptLine',
    'stockLot.costAdjustments',
])
```

- [x] Rerun the new regression command. Expected: both dataset cases pass and each measured relationship is loaded at most once.
- [x] Run the existing purchasing, adjustment, and currency behavior tests:

```bash
php artisan test --compact tests/Feature/PurchaseOrderControlsTest.php tests/Feature/StockLotCostAdjustmentTest.php tests/Feature/CurrencyAwareReceiptCostingTest.php
vendor/bin/pint --dirty --format agent
```

Acceptance: existing snapshot restoration, ingredient/packaging fallback, no-candidate handling, authorization, immutable receipt behavior, adjustment arithmetic, and exchange-rate behavior remain green. No pricing-selection or authorization logic changes. If formatting changes executable test code, rerun the affected test file.

This is the first independently shippable change. No model memoization, broad query rewrites, or candidate-selection optimization is included.

## Task 3: Add the four query-path indexes

**Modify:** `tests/Feature/ForeignKeyIndexCoverageTest.php`.

- [x] Add this group to `FOREIGN_KEY_INDEX_MANIFEST`, leaving existing groups and rollback tests intact:

```php
'query-paths' => [
    'migration' => 'add_query_path_foreign_key_indexes',
    'indexes' => [
        ['table' => 'workspaces', 'column' => 'owner_user_id', 'name' => 'workspaces_owner_user_id_index'],
        ['table' => 'ifra_certificates', 'column' => 'ingredient_id', 'name' => 'ifra_certificates_ingredient_id_index'],
        ['table' => 'recipe_version_costing_packaging_items', 'column' => 'recipe_version_costing_id', 'name' => 'rv_costing_packaging_costing_id_index'],
        ['table' => 'production_batch_presets', 'column' => 'workspace_id', 'name' => 'production_batch_presets_workspace_id_index'],
    ],
],
```

- [x] Run `php artisan test --compact tests/Feature/ForeignKeyIndexCoverageTest.php`. Expected: the new group fails because its indexes/migration do not exist.
- [x] Before creating the migration, read the actual four tables through Boost `database-schema`, rerun `truss:doctor`, and confirm no equivalent leading indexes have been added since this plan. Use full index definitions, not compact Truss output that omits non-unique indexes.
- [x] Create the migration through Artisan:

```bash
php artisan make:migration add_query_path_foreign_key_indexes --no-interaction
```

- [x] Set the generated file's content to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->index('owner_user_id', 'workspaces_owner_user_id_index');
        });
        Schema::table('ifra_certificates', function (Blueprint $table): void {
            $table->index('ingredient_id', 'ifra_certificates_ingredient_id_index');
        });
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->index('recipe_version_costing_id', 'rv_costing_packaging_costing_id_index');
        });
        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->index('workspace_id', 'production_batch_presets_workspace_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('production_batch_presets', function (Blueprint $table): void {
            $table->dropIndex('production_batch_presets_workspace_id_index');
        });
        Schema::table('recipe_version_costing_packaging_items', function (Blueprint $table): void {
            $table->dropIndex('rv_costing_packaging_costing_id_index');
        });
        Schema::table('ifra_certificates', function (Blueprint $table): void {
            $table->dropIndex('ifra_certificates_ingredient_id_index');
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropIndex('workspaces_owner_user_id_index');
        });
    }
};
```

The short packaging-cost name is intentional: the conventional name would be 70 characters. All four additions have direct query-path evidence; no benefit estimate is based on the small development dataset.

- [x] Run the index test again, then Pint:

```bash
php artisan test --compact tests/Feature/ForeignKeyIndexCoverageTest.php
vendor/bin/pint --dirty --format agent
```

## Task 4: Verify PostgreSQL behavior and finish the implementation

- [x] Use a new disposable PostgreSQL database named with the existing safety prefix `koskalk_fk_index_roundtrip_`. Inspect `tests/Support/TestDatabaseSafety.php` and its configuration before running tests. Never point `RefreshDatabase` or rollback tests at the populated development database. Do not modify the safety guard or print credentials.
- [x] Run `tests/Feature/ForeignKeyIndexCoverageTest.php` against that disposable database with `DB_CONNECTION=pgsql`, its disposable `DB_DATABASE`, an empty `DB_URL`, and `VERIFY_POSTGRESQL_INDEXES=true`. Pass connection credentials through the existing environment. Confirm the effective database name before execution; the application's safety guard must remain enabled.
- [x] On that disposable database, verify only the new migration's down/up round trip. Compare fresh PostgreSQL index definitions immediately before and after `up()`: exactly the four planned additions, no altered/dropped indexes or constraints. Establish a fresh Truss baseline there before using `truss:diff`; do not interpret an older repository baseline as this migration's delta.
- [x] Compare fresh doctor output against the same database immediately before and after the new migration. Expected missing-FK findings: 69 to 65 if the schema has not changed since the audit. Explain any changed baseline instead of forcing the old count. Confirm Truss did not use SQLite fallback.
- [x] Run the affected feature tests together after all implementation changes:

```bash
php artisan test --compact tests/Feature/PurchaseOrderControlsTest.php tests/Feature/StockLotCostAdjustmentTest.php tests/Feature/CurrencyAwareReceiptCostingTest.php tests/Feature/ForeignKeyIndexCoverageTest.php
```

- [x] Run `graphify update .` after application/test code changes, inspect `git diff --check`, and review the final diff for unrelated changes. Report the affected-test results and ask the user to run `php artisan test --compact` for full-suite verification, as required by this repository.
- [x] Report whether PostgreSQL verification completed. A green SQLite run is not a substitute if the disposable PostgreSQL environment is unavailable.

Deployment is separate from test verification. Before applying the migration to a populated target, assess table sizes and write traffic. The ordinary migration is suitable only when its index-build write blocking is acceptable; use a separately reviewed concurrent-build deployment approach if necessary. Do not claim the local table sizes establish production safety.

## Task 5: Separate follow-up — account for every remaining index finding

This task is a read-only assessment, not a preapproved migration or blanket exception list. It does not block completion of Tasks 1–4.

- [x] Capture a fresh live PostgreSQL schema and doctor report. After the four indexes, the original baseline leaves 65 findings: 33 actor/audit fields plus 32 others. The stock-lot adjustment foreign key is one of those 32, not the entire remainder.
- [x] Append a complete disposition table to this document during that assessment. Use one row per remaining foreign-key finding, identified by schema/table and full FK column tuple. Required columns: existing relevant index definitions, actual query call sites, parent-delete/update behavior, available volume/query-plan evidence, disposition, and concrete revisit condition.
- [x] Give every finding one explicit disposition: **propose index**, **accept current coverage**, **defer with reason**, or **requires representative workload evidence**. The last category is unresolved evidence, not an accepted exception. A missing production benchmark must not be reported as proof of harmlessness.
- [x] Review actor/audit fields individually. Check real reverse filters and user deletion behavior; do not assume those queries are unused based on column names. Revisit write-heavy history tables separately from small reference tables.
- [x] For `stock_lot_cost_adjustments.stock_lot_id`, compare the existing `(workspace_id, stock_lot_id)` index against the relationship's lot-only filter using read-only EXPLAIN. The earlier local plan used the existing composite; that does not establish its efficiency at production scale. Leave a new lot-leading index deferred unless representative evidence or a clear operational requirement justifies it.
- [x] Review the four redundant-index warnings separately. Compare predicates, uniqueness, index method, included columns, and usage before proposing removal. No removal is included in this plan.
- [x] Record the primary-key finding separately from FK exceptions: `ingredient_function_ingredient` already has a unique pair of non-null columns. Do not add a surrogate key solely to silence the doctor.

### Gate for a later comprehensive schema guard

Design and implement the comprehensive test only after the disposition table is complete. That later change must:

- Preserve the current named-index and migration round-trip tests.
- Recognize composite FK coverage, composite/unique indexes, and partial-index limitations. Do not demand that each member of a composite foreign key independently lead an index.
- Keep accepted exceptions in `tests/Support` when the inventory is large, each with a reason and a revisit condition. Do not treat unresolved investigations as permanent exceptions.
- Reject unexplained new gaps, stale exceptions for removed relationships, and exceptions made obsolete by adequate coverage.
- Verify PostgreSQL-specific metadata against PostgreSQL. Do not pretend that the ordinary SQLite suite proves PostgreSQL predicate/index semantics.
- Demonstrate detection with isolated test fixtures or a disposable database: remove a required index and observe a failure, restore it and observe a pass. Never perform this exercise on populated application data.

This gate deliberately avoids introducing an incomplete schema-policy implementation now. The follow-up assessment supplies the facts needed to specify and test it correctly.

## Maintenance guidance

When a future feature benefits from it, extract the ingredient editor's form schema/reference preparation or the inventory filtering query behind a clear interface. Do not mandate a refactor on the next incidental edit and do not move helpers into traits just to lower line counts. No standing refactoring rule is created by this plan.

## Completion criteria

- [x] Receipt-reversal fallback has bounded relationship-query counts for both tested candidate volumes and restores the independently specified adjusted price.
- [x] Four explicitly named indexes have working up/down/up coverage; existing coverage is preserved.
- [x] Affected tests pass and PostgreSQL verification is either completed or accurately reported as outstanding.
- [x] No dependency changes, automatic mass indexing, index removal, model-wide caching changes, namespace moves, or domain renames are included.
- [x] Remaining-index assessment is reported separately; no comprehensive-coverage claim is made before that work is complete.

## Remaining-index assessment — live PostgreSQL, 25 September 2026

This is the Task 5 assessment, not authorization to add the proposed indexes. Read-only observations used PostgreSQL 17.0 on the populated local database `koskalk_restore_20260722_023001`; no migration, DELETE, UPDATE, ANALYZE command, or hypothetical index was applied to it. A fresh `CACHE_STORE=array php artisan truss:doctor --format=json --no-interaction` explicitly reported connection `pgsql`: **69 TRUSS-IDX-001, four TRUSS-IDX-003, one TRUSS-INT-001**. Excluding the four additions specified above leaves **65 distinct single-column FK tuples: 33 actor/audit and 32 other**. The populated database had not received those additions when inspected; 65 is the projected remainder, not a claim that its live doctor count already decreased.

The source of truth for each row is live `pg_constraint` / `pg_get_constraintdef`, `pg_index` / `pg_get_indexdef`, and read-only COUNT(*) through Boost. All 65 findings here are single-column FKs; this inventory says nothing about whether an individual member of a composite FK needs its own index. Installed code inspected was Laravel 13.30.1 and Filament Actions 5.7.8. Graph navigation identified the costing community (15), tenant/user community (25), and high-connectivity IngredientEditor / ProductionRun nodes before source inspection.

### How to interpret the inventory

There are **7 propose index, 4 defer with reason, 54 requires representative workload evidence, and 0 accepted FK-coverage exceptions**. A scoped query can be covered while an unscoped reverse relationship or referential-integrity operation remains unproven. Neither an index suffix nor a small local row count proves adequate FK coverage. The proposals are justified by concrete application operations that read these keys without the existing leading column; their production benefit and deployment method still need validation.

The 33 actor rows were checked against app-wide column predicates, model reverse relationships, and the user deletion entry points. `app/Filament/Resources/Users/Pages/EditUser.php:16` and `app/Filament/Resources/Users/Tables/UsersTable.php:69` expose real hard-delete actions. `app/Models/User.php` has no soft-delete trait or model deletion prohibition. Consequently every SET NULL actor FK can require a cross-workspace child lookup during account deletion; `production_runs.batch_number_assigned_by_user_id` instead RESTRICTs deletion, but must still find references. Normal belongsTo display queries hit the parent users primary key; they do not justify a child actor index. Conversely, no reverse call found in this search is not proof that operational SQL, future reporting, or package extensions never use it.

**All actor rows remain unresolved** until user-deletion frequency, retention volume, allowed blocking time, and representative referential-action plans are known. In particular, the append-heavy stock movements, consumption, reservations, production runs/journal, and enrichment history must be assessed independently of small reference/configuration tables. Existing `User::packagingItems()` and `User::createdMediaLabels()` are recorded rather than misclassified as nonexistent reverse relationships. The reviewed market-label predicate is IS NOT NULL after an ingredient constraint, not a reviewer equality query.

Index definitions below include every live index containing the subject FK column, even when that column is not first; UNIQUE/primary-pair semantics are preserved. “No index contains this FK” explicitly records that there is no directly relevant definition, not that the table has no indexes. All shown indexes use btree, have no INCLUDE columns, and are unconditional unless a WHERE predicate is printed. Volume is the observed local COUNT(*); **production cardinality, skew, query frequency, write rate and deletion SLO are unknown for every row**. No representative plans were available except the expressly nonrepresentative adjustment comparison below.

### Full disposition table

| Schema/table and complete FK tuple | Existing relevant index definitions | Actual query/write sites and reverse-lookup evidence | Parent delete/update behavior | Volume / plan evidence | Disposition | Concrete revisit condition |
| --- | --- | --- | --- | --- | --- | --- |
| `public.beta_invites (invited_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/BetaInviteService.php:64 writes inviter; app/Models/BetaInvite.php:24 belongsTo reads user PK; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 2; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure beta_invites lookup on invited_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.current_material_prices (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/CurrentMaterialPriceService.php:257 writes attribution; prices selected by workspace/material; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 21; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure current_material_prices lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.department_employee (employee_id)` | `department_employee_department_id_employee_id_unique`: UNIQUE btree (department_id, employee_id) | app/Models/Employee.php:34 departments(); app/Livewire/ProductionBench/Production/SettingsIndex.php:677 eager-loads departments for employees | REFERENCES employees(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 4; no representative plan; production volume/rate unknown. | **propose index** | Add employee-leading index in a separate reviewed migration; measure employee settings query and employee-delete cascade on representative membership counts. |
| `public.exports (user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | vendor/filament/actions/src/Exports/Models/Export.php:56 user() reads parent PK; no app reverse filter found | REFERENCES users(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before enabling large export retention or user deletion at scale, measure user-FK lookup and cleanup; establish retention volume. |
| `public.failed_import_rows (import_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | vendor/filament/actions/src/Imports/Http/Controllers/DownloadImportFailureCsv.php:75/:82 calls Import::failedRows() | REFERENCES imports(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | When imports are used with substantial failure counts, EXPLAIN failure download and import cleanup; add import-leading index if scan cost is material. |
| `public.goods_receipts (received_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Purchasing/ReceivePurchaseOrder.php:247 and ReceiveDirectGoodsReceipt.php:135 write receiver; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 23; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure goods_receipts lookup on received_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.goods_receipts (reversed_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Purchasing/ReverseGoodsReceipt.php:82 writes reverser after locking receipt; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 23; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure goods_receipts lookup on reversed_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ifra_certificate_limits (ifra_product_category_id)` | `ifra_certificate_limits_ifra_certificate_id_ifra_product_catego`: UNIQUE btree (ifra_certificate_id, ifra_product_category_id) | app/Models/IfraProductCategory.php:29 limits reverse relation; IngredientDataEntryService loads limits by certificate | REFERENCES ifra_product_categories(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure category retirement cascade and any reverse category-limit report at representative certificate counts. |
| `public.imports (user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | vendor/filament/actions/src/Imports/Models/Import.php:62 user() reads parent PK; no app reverse filter found | REFERENCES users(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before retaining large import history or deleting users, measure user-FK cleanup and define retention. |
| `public.ingredient_allergen_entries (allergen_id)` | `ingredient_allergen_entries_ingredient_id_allergen_id_unique`: UNIQUE btree (ingredient_id, allergen_id) | app/Models/Allergen.php:30 reverse entries relation; app/Models/Ingredient.php:188 ingredient-scoped normal reads | REFERENCES allergen_catalog(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 4; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure allergen-led usage lookup or catalog deletion cascade once catalog/import volume is representative. |
| `public.ingredient_enrichment_batch_items (applied_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/IngredientEnrichment/ApplyApprovedIngredientEnrichment.php:74 writes actor; app/Models/IngredientEnrichmentBatchItem.php:104 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_enrichment_batch_items lookup on applied_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_enrichment_batch_items (approved_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/IngredientEnrichment/ApproveIngredientEnrichmentItem.php:104 writes actor; app/Models/IngredientEnrichmentBatchItem.php:99 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_enrichment_batch_items lookup on approved_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_enrichment_batch_items (edited_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/IngredientEnrichment/EditIngredientEnrichmentProposal.php:133 writes actor; app/Models/IngredientEnrichmentBatchItem.php:109 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_enrichment_batch_items lookup on edited_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_enrichment_batch_items (ingredient_id)` | `ingredient_enrichment_items_batch_ingredient_unique`: UNIQUE btree (ingredient_enrichment_batch_id, ingredient_id) | app/Services/PlatformIngredientDeletionService.php:97 counts by ingredient alone before deletion | REFERENCES ingredients(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **propose index** | Implement ingredient-leading index separately for administrative dependency checks; verify realistic enrichment-history count plan. |
| `public.ingredient_enrichment_batch_items (ingredient_intake_item_id)` | `ingredient_enrichment_items_batch_intake_item_unique`: UNIQUE btree (ingredient_enrichment_batch_id, ingredient_intake_item_id) | app/Actions/IngredientIntake/RemoveIngredientIntakeRow.php:33 and ResolveIngredientIntakeDuplicate.php:91 filter by intake item; app/Models/IngredientIntakeItem.php:49 reverse relation | REFERENCES ingredient_intake_items(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **propose index** | Implement intake-item-leading index separately; validate row removal/duplicate resolution over retained enrichment history. |
| `public.ingredient_enrichment_batch_items (rejected_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/IngredientEnrichment/RejectIngredientEnrichmentItem.php:40 writes actor; app/Models/IngredientEnrichmentBatchItem.php:94 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_enrichment_batch_items lookup on rejected_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_enrichment_batches (requested_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/IngredientEnrichment/IngredientEnrichmentBatchService.php:50 writes requester; app/Models/IngredientEnrichmentBatch.php:61 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 12; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_enrichment_batches lookup on requested_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_function_ingredient (assigned_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/IngredientFunctionAssignmentService.php:59-74 reads/writes attribution on ingredient-scoped pivot; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 47; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_function_ingredient lookup on assigned_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_intake_batches (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/IngredientIntake/CreateIngredientIntakeBatch.php:72 writes creator; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 3; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_intake_batches lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_intake_items (existing_ingredient_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/PlatformIngredientDeletionService.php:100 OR dependency count on existing/promoted ingredient | REFERENCES ingredients(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 10; no representative plan; production volume/rate unknown. | **propose index** | Implement this and promoted-ingredient index together; inspect BitmapOr or equivalent representative OR-count plan. |
| `public.ingredient_intake_items (promoted_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/IngredientIntake/PromoteIngredientIntakeItem.php:168 writes promoter; app/Models/IngredientIntakeItem.php:54 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 10; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_intake_items lookup on promoted_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredient_intake_items (promoted_ingredient_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/PlatformIngredientDeletionService.php:101 OR dependency count on existing/promoted ingredient | REFERENCES ingredients(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 10; no representative plan; production volume/rate unknown. | **propose index** | Implement with existing-ingredient index; verify both disjuncts and parent restriction checks. |
| `public.ingredient_market_labels (reviewed_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. Normal read narrows with UNIQUE btree (ingredient_id, market_code). | app/Services/IngredientMarketLabelService.php:99 filters IS NOT NULL within ingredient->marketLabels(); ingredient-leading unique narrows this, no reviewer equality lookup found | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 29; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredient_market_labels lookup on reviewed_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.ingredients (taxonomy_reviewed_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/IngredientEnrichment/ApplyPlatformIngredientEnrichment.php:211 writes reviewer; UserIngredientAuthoringService.php:812 clears it; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 173; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure ingredients lookup on taxonomy_reviewed_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.media_asset_label (media_label_id)` | `media_asset_label_pkey`: UNIQUE btree (media_asset_id, media_label_id) | app/Models/MediaLabel.php:30 assets() reverse relation; app/Services/MediaLabelService.php:86-89 hard-deletes label from UI | REFERENCES media_labels(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 5; no representative plan; production volume/rate unknown. | **propose index** | Add label-leading index separately to support real label removal cascade; test representative label fan-out. |
| `public.media_assets (uploaded_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/MediaAssetUploadService.php:57 writes uploader; :135/:152 checks loaded asset ownership, not SQL uploader filter; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 34; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure media_assets lookup on uploaded_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.media_labels (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/User.php:74 createdMediaLabels() is a reverse lookup; no invocation found in app; app/Services/MediaLabelService.php:55 writes actor | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 7; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure media_labels lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.packaging_items (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/User.php:59 packagingItems() is a reverse lookup; workspace lists use workspace_id; actor relationship invocation not found | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 4; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure packaging_items lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.product_family_ifra_categories (ifra_product_category_id)` | `product_family_ifra_categories_product_family_id_ifra_product_c`: UNIQUE btree (product_family_id, ifra_product_category_id) | app/Models/ProductFamilyIfraCategory.php::ifraProductCategory belongsTo; current mapping path uses ProductTypeIfraCategory, no app reverse family-category use found | REFERENCES ifra_product_categories(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **defer with reason** | Legacy family mapping has no demonstrated hot reverse query; revisit before reactivating it or bulk category deletion; production retained rows are unknown. |
| `public.product_type_ifra_categories (ifra_amendment_id)` | `product_type_ifra_category_unique`: UNIQUE btree (product_type_id, ifra_amendment_id, ifra_product_category_id)<br>`product_type_ifra_default_unique`: UNIQUE btree (product_type_id, ifra_amendment_id) WHERE (is_default = true) | app/Models/IfraAmendment.php:31 reverse mappings; app/Services/ProductTypeIfraOptionsBuilder.php selects by product type/amendment | REFERENCES ifra_amendments(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 44; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure amendment-wide mapping/report/delete workload; type-leading index serves type-scoped path only. |
| `public.product_type_ifra_categories (ifra_product_category_id)` | `product_type_ifra_category_unique`: UNIQUE btree (product_type_id, ifra_amendment_id, ifra_product_category_id) | app/Models/IfraProductCategory.php:34 reverse mappings; ProductTypeIfraOptionsBuilder uses type-scoped mappings | REFERENCES ifra_product_categories(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 44; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure category-led mapping report and category deletion cascade at representative mapping volume. |
| `public.product_types (default_ifra_product_category_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/ProductType.php has no current default-category relation/fillable field; current options derive from ProductTypeIfraCategory | REFERENCES ifra_product_categories(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 54; no representative plan; production volume/rate unknown. | **defer with reason** | Legacy column: revisit before restoring direct default-category usage or category cleanup; retained production references must be inventoried. |
| `public.product_types (product_family_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/ProductType.php:41 uses productFamilies() many-to-many; no current direct family FK read found | REFERENCES product_families(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 54; no representative plan; production volume/rate unknown. | **defer with reason** | Legacy direct family link: revisit if used again or family deletion is introduced; CASCADE remains active despite new pivot. |
| `public.production_batch_ingredients (ingredient_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/PlatformIngredientDeletionService.php:96 counts whereBelongsTo ingredient; IngredientCatalogConsolidationService.php:19 retargets ingredient references | REFERENCES ingredients(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **propose index** | Add ingredient-leading index separately for dependency/consolidation operations; validate at historical snapshot volume. |
| `public.production_batch_packaging_items (packaging_item_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/ProductionBatch.php:64 reads packaging by batch; snapshot creation in app/Services/ProductionSnapshotService.php | REFERENCES packaging_items(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure packaging deletion SET NULL across historical snapshots; revisit with packaging usage report or archival growth. |
| `public.production_batches (recipe_id)` | `production_batches_user_id_recipe_id_manufacture_date_index`: btree (user_id, recipe_id, manufacture_date) | app/Http/Controllers/RecipeController.php:156-160 combines recipe_id and user_id, covered by user-leading composite; app/Models/Recipe.php:149 exposes recipe-only relation | REFERENCES recipes(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before product deletion or recipe-only historical listing, compare FK scan to recipe-leading index on representative retained history. |
| `public.production_batches (recipe_version_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/RecipeVersion.php:110 productionBatches() reverse relation; ProductionSnapshotService writes frozen version reference | REFERENCES recipe_versions(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure saved-history deletion SET NULL and any version-only batch listing; add version-leading index if operationally needed. |
| `public.production_consumption (recorded_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/SaveProductionActuals.php:168 writes recorder; app/Models/ProductionConsumption.php:49 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_consumption lookup on recorded_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_documents (attached_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Inventory/AttachProductionDocument.php:59 writes actor; app/Models/ProductionDocument.php:44 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 2; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_documents lookup on attached_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_documents (workspace_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Inventory/AttachProductionDocument.php:58 assigns workspace; document reads use documentable morph via models, not workspace filter found | REFERENCES workspaces(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 2; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before workspace deletion at scale, measure cascade across all documentable types and retained document count. |
| `public.production_journal_entries (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/SaveProductionJournalEntry.php:64 writes actor; entries retrieved by production run; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_journal_entries lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_runs (aborted_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/AbortProduction.php:110 writes actor; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_runs lookup on aborted_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_runs (batch_number_assigned_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/Production/ProductionRunNumberService.php:95 writes actor; app/Models/ProductionRun.php:113 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_runs lookup on batch_number_assigned_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_runs (cancelled_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/CancelProduction.php:81 writes actor; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_runs lookup on cancelled_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_runs (completed_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/Production/ProductionCompletionService.php:204 writes actor; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_runs lookup on completed_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_runs (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/CreateProductionDraft.php:245 writes actor; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_runs lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.production_runs (started_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/StartProduction.php:119 writes actor; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure production_runs lookup on started_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.purchase_orders (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Purchasing/CreatePurchaseOrder.php:84 writes actor; procurement selection is workspace-scoped; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 11; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure purchase_orders lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.recipe_version_costings (user_id)` | `recipe_version_costings_recipe_version_id_user_id_unique`: UNIQUE btree (recipe_version_id, user_id) | app/Models/User.php:64 recipeVersionCostings() reverse relation; app/Services/RecipeVersionCostingSynchronizer.php:90/:188/:433/:451 combines version and user | REFERENCES users(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 20; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Version-scoped costings use unique composite; measure user-only reads/user deletion at representative saved-history volume. |
| `public.recipes (brand_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/Brand.php:26 recipes() reverse relation; app/Models/Recipe.php brand belongsTo | REFERENCES brands(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before brand deletion or brand-led product listing grows, measure SET NULL and reverse lookup, including workspace predicate. |
| `public.recipes (created_by)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Services/RecipeVersionRecordService.php:29 writes creator; app/Models/Recipe.php:109 belongsTo; User::recipes uses owner_id instead; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure recipes lookup on created_by before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.recipes (locked_by)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/Recipe.php:129 belongsTo; app/Services/RecipeWorkbenchViewDataBuilder.php:189 reads loaded attribution; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure recipes lookup on locked_by before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.recipes (product_family_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/Recipe.php productFamily belongsTo; app/Services/ProductClassificationService.php:17 checks loaded family identity | REFERENCES product_families(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 15; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before family deletion or family-only reporting, assess potentially large product cascade and safe operational policy. |
| `public.regulatory_regime_allergens (allergen_id)` | `regulatory_regime_allergens_regulatory_regime_id_allergen_id_un`: UNIQUE btree (regulatory_regime_id, allergen_id) | app/Models/Allergen.php:35 reverse rules; app/Services/InciGenerationService.php uses regime-scoped rules | REFERENCES allergen_catalog(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 164; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure allergen retirement cascade/reverse rule report across all regimes; regime-leading index is not a blanket FK exception. |
| `public.regulatory_regime_substance_rules (substance_id)` | `regulatory_regime_substance_rules_regulatory_regime_id_substanc`: UNIQUE btree (regulatory_regime_id, substance_id) | app/Models/Substance.php:44 reverse rules; app/Services/SubstanceComplianceService.php reads regime-scoped rules | REFERENCES substance_catalog(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 18; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure substance retirement cascade and substance-led rule inventory as regimes grow. |
| `public.stock_lot_cost_adjustments (compensates_adjustment_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Inventory/AddStockLotCostAdjustment.php:91-95 checks compensation WITH workspace_id and stock_lot_id; app/Models/StockLotCostAdjustment.php blocks model deletion | REFERENCES stock_lot_cost_adjustments(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **defer with reason** | Composite narrows actual duplicate-compensation lookup; revisit if per-lot history grows enough to scan heavily, compensation-only lookup appears, or direct/maintenance deletion is supported. |
| `public.stock_lot_cost_adjustments (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Inventory/AddStockLotCostAdjustment.php writes actor; app/Models/StockLotCostAdjustment.php::createdBy belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure stock_lot_cost_adjustments lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.stock_lot_cost_adjustments (stock_lot_id)` | `stock_lot_cost_adjustments_workspace_id_stock_lot_id_index`: btree (workspace_id, stock_lot_id) | app/Models/StockLot.php:141 costAdjustments() filters lot only; receipt reversal eager-loads relation; comparison EXPLAIN below | REFERENCES stock_lots(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 0; EXPLAIN comparison below; production volume/rate unknown. | **requires representative workload evidence** | Keep lot-leading addition deferred pending representative tenant/lot distribution, adjustment growth, buffers/latency or a required stock-lot deletion SLO. |
| `public.stock_lots (released_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Inventory/ReleaseStockLot.php:25 writes actor; app/Models/StockLot.php:123 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 41; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure stock_lots lookup on released_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.stock_movements (actor_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Inventory/AdjustStockLot.php:128 writes actor; app/Models/StockMovement.php:77 belongsTo; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 46; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure stock_movements lookup on actor_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.stock_reservations (created_by_user_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Actions/Production/PrepareProductionStock.php:486 writes actor; reservations retrieved by workspace/run/requirement/lot; no actor reverse SQL filter/relation found in app search | REFERENCES users(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 32; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure stock_reservations lookup on created_by_user_id before user deletion at representative retention volume; revisit immediately if actor-filtered audit/reporting is introduced. |
| `public.user_entitlements (plan_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/Plan.php:37 entitlements reverse relation; app/Services/Billing/PaddleBillingService.php:117 uses user-scoped plan inequality | REFERENCES plans(id) ON DELETE RESTRICT; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 2; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure plan-wide reporting/deletion restriction and user-scoped billing at realistic subscribers per plan; low cardinality may favor scan. |
| `public.users (active_workspace_id)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/User.php::activeWorkspace belongsTo; app/Services/WorkspaceProvisioner.php:77 updates a known user | REFERENCES workspaces(id) ON DELETE SET NULL; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 6; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before workspace removal at scale, measure SET NULL scan across users; revisit for active-workspace user listing. |
| `public.users (locale)` | No index contains this FK; existing indexes do not provide a leading FK lookup. | app/Models/SupportedLocale.php::booted manages default/active flags, not deletion prohibition; no app user locale-equality listing found | REFERENCES supported_locales(code) ON UPDATE CASCADE ON DELETE RESTRICT; parent operation must locate referencing rows. | Local COUNT(*) 6; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Before renaming/removing a locale or locale-targeted user querying, measure CASCADE-update/RESTRICT-delete lookup on user volume; locale selectivity matters. |
| `public.workspace_ingredient_codes (ingredient_id)` | `workspace_ingredient_codes_material_unique`: UNIQUE btree (workspace_id, ingredient_id) | app/Models/Ingredient.php:231 workspaceCodes() reverse relationship; normal codes selected by workspace/ingredient pair | REFERENCES ingredients(id) ON DELETE CASCADE; UPDATE NO ACTION; parent operation must locate referencing rows. | Local COUNT(*) 1; no representative plan; production volume/rate unknown. | **requires representative workload evidence** | Measure cross-workspace ingredient-code loads or ingredient deletion cascade as workspace count grows; workspace-leading pair covers scoped lookup only. |

### Stock-lot adjustment plan comparison

`app/Models/StockLot.php::costAdjustments()` emits a lot-only predicate; eager loading uses an IN list. The relationship has no workspace constraint added by the adjustment model. Read-only `EXPLAIN (ANALYZE, BUFFERS)` on the real local table compared:

```sql
SELECT * FROM stock_lot_cost_adjustments WHERE stock_lot_id IN (1, 2);
SELECT * FROM stock_lot_cost_adjustments WHERE workspace_id = 1 AND stock_lot_id IN (1, 2);
```

The lot-only query used a Bitmap Index Scan on `stock_lot_cost_adjustments_workspace_id_stock_lot_id_index`, followed by a Bitmap Heap Scan; index condition was `stock_lot_id = ANY (...)`, estimated cost 5.57..10.91, actual rows 0, shared hit 1, execution 0.030 ms. The workspace-and-lot query used an Index Scan on the same index, estimated cost 0.14..8.16, actual rows 0, shared hits 5, execution 0.062 ms. The table's actual COUNT(*) was **zero**. These two executions establish that PostgreSQL can use the existing composite for a suffix condition here; their timings cannot rank alternatives or establish production efficiency. No forced planner setting was used.

Keep the lot-leading addition deferred pending actual multi-workspace distribution and retained adjustments per lot, measured buffers/latency for realistic eager-load batches, or a defined lot-deletion operational requirement. Separately, the compensation duplicate check in `AddStockLotCostAdjustment.php:91-95` already supplies both workspace and lot before checking `compensates_adjustment_id`; this is why that separate FK is deferred rather than automatically proposed. The adjustment model blocks model-level deletion, but direct database referential operations are still governed by its live RESTRICT constraint.

### Four redundant-index warnings — retain all four

The definitions were compared using live `pg_get_indexdef`. Every pair below is valid btree, with no expression key, WHERE predicate or INCLUDE column; column ordering is as printed. The language and recipe wider indexes are UNIQUE, so their uniqueness semantics must be preserved. No removal is approved. `pg_stat_user_indexes.idx_scan` observations are local cumulative counters; `pg_stat_database.stats_reset` was null, so the observation window is unknown and zero does not establish disuse.

| Warning index | Potential covering index | Observed local idx_scan (short / wide) | Disposition and revisit |
| --- | --- | --- | --- |
| `ingredients_category_index`: btree(category) | `ingredients_category_subcategory_index`: btree(category, subcategory) | 0 / 212 | Requires representative workload evidence. Structurally a prefix candidate; compare category-only plans, sizes/cache footprint and production usage over a known window before proposing a separate removal. |
| `language_lines_group_index`: btree("group") | `language_lines_group_key_unique`: UNIQUE btree("group", key) | 117 / 6352 | Requires representative workload evidence. Short index has recorded use; benchmark translation group loads and preserve group+key uniqueness before any removal proposal. |
| `media_asset_usages_usable_type_usable_id_index`: btree(usable_type, usable_id) | `media_asset_usage_target_role_index`: btree(usable_type, usable_id, role) | 0 / 0 | Requires representative workload evidence. Capture morph-target cleanup/read workload; both zero counters with unknown observation window are not evidence of safety. |
| `recipes_workspace_id_index`: btree(workspace_id) | `recipes_workspace_product_reference_unique`: UNIQUE btree(workspace_id, product_reference) | 0 / 0 | Requires representative workload evidence. Compare tenant listing/count/delete plans, write cost and index sizes; retain product-reference uniqueness and all current indexes now. |

The common absence of special index clauses was read from definitions rather than inferred from names. Production usage, sizes and cache pressure remain unavailable; these warnings are candidates for later review, not verified redundant-cost savings.

### Missing primary key — separate decision

`ingredient_function_ingredient` has no declared PRIMARY KEY. Live attributes show both `ingredient_id` and `ingredient_function_id` are NOT NULL, and the live UNIQUE btree `ingredient_function_ingredient_ingredient_id_ingredient_functio` enforces their pair. A separate btree on `ingredient_function_id` supports the reverse relationship. The pivot is accessed through `Ingredient::functions()`, `IngredientFunction::ingredients()`, and `IngredientFunctionAssignmentService` using the relationship pair and pivot attributes.

**Accept the current pair identity for this assessment; do not add a surrogate key just to silence TRUSS-INT-001.** This is not an FK-index exception: its separate `assigned_by_user_id` finding remains in the 65-row table. Revisit if tooling/replication requires a declared PK, rows gain independently addressable lifecycle/identity, or another table must reference an assignment; then evaluate declaring the existing pair as PK as well as a surrogate. No current evidence requires either change.

### Follow-up priorities and evidence gaps

1. In a separately authorized index change, evaluate the seven concrete candidates: `department_employee.employee_id`, `media_asset_label.media_label_id`, `ingredient_enrichment_batch_items.ingredient_id`, `ingredient_enrichment_batch_items.ingredient_intake_item_id`, `ingredient_intake_items.existing_ingredient_id`, `ingredient_intake_items.promoted_ingredient_id`, and `production_batch_ingredients.ingredient_id`. Keep the two OR-query ingredient indexes together in evaluation; validate realistic dependency counts, intake operations and cascade plans.
2. Establish a user/workspace deletion performance requirement and production-like retention data before accepting any actor-field exception. Do not execute destructive deletion benchmarks on the populated database; use representative disposable data and include FK trigger time/locking, not only SELECT speed.
3. Reassess lot-only adjustment reads under representative skew and batch sizes; preserve the current composite meanwhile. Review active import failure-download volumes separately from currently empty local import tables.
4. Inventory the retained data and actual lifecycle of the three legacy family/default-category links before either indexing or retiring them. Do not remove their constraints as part of performance work.
5. Only design the later comprehensive schema guard after resolving or explicitly governing the 54 evidence-needed rows. The table is a complete finding inventory, **not** a blanket exception manifest or a proof that all remaining indexes are unnecessary.

Only this assessment was appended by its reviewer. The read-only review did not change application code, tests, database state, existing plan checkboxes, or index policy.


## Execution results — 2026-09-25

Completed on branch `codex/receipt-reversal-indexes`. Luna max implemented Tasks 1–4; Astra medium completed the remaining-index assessment and independently approved the implementation for specification compliance and correctness. No takeover was necessary.

- Receipt regression first failed against the original action: 2/7 surviving candidates produced 4/14 receipt-line relationship reads and 2/7 adjustment reads. After nested eager loading, both dataset cases pass with at most one read per measured relationship and the independently expected adjusted price/source.
- Combined affected SQLite feature tests: **67 passed, 876 assertions** across PurchaseOrderControlsTest, StockLotCostAdjustmentTest, CurrencyAwareReceiptCostingTest and ForeignKeyIndexCoverageTest.
- Disposable PostgreSQL index coverage: **16 passed, 653 assertions**. The existing database safety guard remained enabled.
- Independent PostgreSQL migration comparison confirmed **exactly four index additions**, no other schema changes, and successful rollback/reapply restoring the original indexed schema. Fresh Truss snapshots reported **fallback=false** and doctor findings decreased **69 to 65** on that disposable database.
- Pint and `git diff --check` passed. The local graph was refreshed after code changes.
- Assessment of the 65 remaining findings: **7 propose index, 4 defer with reason, 54 require representative workload evidence**. No additional indexes or index removals were implemented.

The populated development database was not migrated; its missing-FK count remains 69. Changes remain uncommitted for review. Full-suite verification is requested in the delivery message using `php artisan test --compact`; the full suite has not been run as part of this task.


## Subsequent completion — 2026-09-25

The fallback now selects at most one candidate per source and orders equal timestamps by numeric record ID. Later manual costing edits remain protected. A legacy date-normalization regression is covered. The three affected feature files passed on both SQLite and disposable PostgreSQL: 52 tests, 249 assertions each. The user also reported the full suite passing before this follow-up refactor.

The user subsequently authorized applying the four-index migration to the populated local development database. It ran successfully on `koskalk_restore_20260722_023001`; a fresh live schema comparison showed exactly four index additions, and doctor findings decreased from 69 to 65. No separately hosted production database was changed. These results supersede the earlier pending-migration status above.


## Authorized follow-ups completed — 2026-09-25

The preceding completed work was committed as `aac70b09` before beginning these follow-ups. At the user's request, Luna max implemented and Astra medium independently reviewed both changes; no implementation takeover was required.

- Snapshot source validity is memoized only within one subject's snapshot-candidate calculation, keyed by source type and nullable source ID. Both true and false results are reused; price, currency, timestamp and ranking remain evaluated per snapshot. A real-reversal regression first failed with two repeated validation reads and now passes with one. Reusing the action after source deactivation verifies that validity does not persist across reversals.
- All seven previously proposed indexes were rechecked against the live PostgreSQL schema and current application call sites, implemented in `2026_09_25_074929_add_followup_query_path_foreign_key_indexes.php`, and included in the existing explicit-index coverage and rollback/reapply tests. No other indexes or constraints were changed.
- SQLite validation passed: 53 purchasing/costing tests with 257 assertions, plus 18 index tests with 737 assertions. The combined disposable PostgreSQL run passed 71 tests with 1,003 assertions. Pint and diff checks passed.
- Following authorization, the seven-index migration was applied to populated local development database `koskalk_restore_20260722_023001`. Fresh live snapshots, with no fallback, showed exactly seven index additions and no other schema changes. Missing-FK-index findings decreased from 65 to 58. The historical assessment above remains the record of the earlier schema; its seven proposed additions are now implemented. The remaining 58 comprise four deferred findings and 54 requiring representative workload evidence.

No separately hosted production database was migrated, and no production speedup benchmark is claimed. The full suite should be rerun for these latest follow-ups.
