# Production Bench Inventory UX Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the Production Bench Inventory UX so users can reliably navigate from a material to its physical lots and supplier listings, deliberately filter the Lot register by material, and reconcile long activity periods without loading the complete history into PHP.

**Architecture:** Keep the current material-level and lot-level read models. Add explicit, tested navigation URLs to the presentation rows, a server-searched material selector for the Lot register, and one workspace-scoped query service for supplier listings on material detail. Replace PHP-wide activity reduction and material lot-ID arrays with bounded database queries. Preserve URL-bound filters and render public controls through Filament schemas.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, Pest 4, PostgreSQL production, SQLite tests, Blade, existing Soapkraft design tokens.

---

## Scope decisions

1. A **stock lot** is a physical batch. It remains the primary Inventory detail.
2. A **supplier listing** is a Purchasing catalogue entry for buying that material. Material detail gains a separate compact section for these entries. It does not duplicate editing, receipt posting, or purchasing workflows.
3. A valid HTML table row cannot be wrapped in an anchor. Therefore the complete Material identity cell becomes one large, visible link with a trailing affordance. Lot register material names link to the same detail page, while lot actions remain independent.
4. Supplier listings are paginated independently under the page name `supplier-listings`; activity remains under `activity`.
5. Main-branch integration is outside this plan. The implementation is completed and committed on `codex/production-bench-inventory-ux`, then merged only after review.

## File map

### Create

- `app/Services/Inventory/WorkspaceMaterialSupplierListingsQuery.php`: workspace-scoped, subject-scoped supplier-listing pagination.
- `tests/Feature/WorkspaceMaterialSupplierListingsQueryTest.php`: listing membership, ordering, pagination, and tenant-isolation coverage.

### Modify

- `app/Livewire/ProductionBench/InventoryIndex.php`: material detail URLs, Filament filter schemas, server-side Lot register material selection.
- `resources/views/livewire/production-bench/inventory-index.blade.php`: full material-cell affordance, Lot register material links, rendered Filament filter schemas.
- `app/Livewire/ProductionBench/InventoryMaterialDetail.php`: supplier-listing paginator and Filament period controls.
- `resources/views/livewire/production-bench/inventory-material-detail.blade.php`: supplier-listing section and rendered period schema.
- `app/Services/Inventory/MaterialActivityService.php`: database-grouped totals and lot subqueries instead of unbounded collections.
- `app/Services/Inventory/WorkspaceMaterialSettings.php`: conforming lock query.
- `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php`: remove duplicated PHPDoc.
- `lang/en/production_bench.php`: navigation and supplier-listing copy.
- `database/seeders/data/interface-translations.json`: matching interface keys.
- `tests/Feature/ProductionBenchPagesTest.php`: Stock by material and Lot register navigation/filter behavior.
- `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`: successful route journey, supplier listings, independent pagination, and period form behavior.
- `tests/Feature/MaterialActivityServiceTest.php`: exact totals plus bounded query-shape coverage.

---

### Task 0: Preserve the existing counter-review baseline

**Files:**
- Review: every path returned by `git status --short`
- Test: existing inventory-focused Pest tests

- [x] **Step 1: Commit this plan separately**

```bash
git add docs/superpowers/plans/2026-08-31-production-bench-inventory-ux-completion.md
git commit -m "docs: plan inventory ux completion"
```

- [x] **Step 2: Inspect every pre-existing dirty change**

```bash
git status --short
git diff --check
git diff --stat
git diff
```

Expected: the remaining changes are the 18 inventory counter-review files already present before this completion plan. Stop if any unrelated user change appears.

- [x] **Step 3: Verify the existing counter-review baseline**

```bash
php artisan test --compact \
  tests/Feature/MaterialActivityServiceTest.php \
  tests/Feature/ProductionBenchInventoryMaterialDetailTest.php \
  tests/Feature/ProductionBenchPagesTest.php \
  tests/Feature/WorkspaceMaterialInventoryQueryTest.php \
  tests/Feature/WorkspaceMaterialSettingTest.php \
  tests/Unit/ProductionBenchNavigationTest.php
```

Expected: all baseline tests PASS before adding new behavior.

- [x] **Step 4: Commit the existing counter-review fixes without altering them**

```bash
git add app/Actions/Inventory/SaveMaterialBuffer.php app/Livewire/ProductionBench/InventoryIndex.php app/Livewire/ProductionBench/InventoryMaterialDetail.php app/Services/Inventory/MaterialActivityService.php app/Services/Inventory/WorkspaceMaterialInventoryQuery.php app/Services/Inventory/WorkspaceMaterialSettings.php database/seeders/data/interface-translations.json docs/superpowers/plans/2026-08-30-production-bench-inventory-ux.md lang/en/production_bench.php resources/views/livewire/production-bench/inventory-index.blade.php resources/views/livewire/production-bench/inventory-material-detail.blade.php resources/views/production-bench/inventory-stock.blade.php resources/views/production-bench/inventory.blade.php tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php tests/Feature/WorkspaceMaterialSettingTest.php
git commit -m "fix: address inventory ux review findings"
```

- [x] **Step 5: Confirm a clean implementation baseline**

```bash
git status --short
```

Expected: no output.

---

### Task 1: Make material navigation explicit and prove the complete journey

**Files:**
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Test: `tests/Feature/ProductionBenchPagesTest.php`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [x] **Step 1: Add a failing Stock by material navigation test**

Add a test that creates a tracked ingredient and packaging item, renders `InventoryIndex` in `materials` mode, and asserts both named detail URLs are present in the rendered HTML. Make an authenticated GET request to each URL and assert the correct material name is rendered.

```php
it('links every material identity to its accessible detail page', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $supplier = Supplier::factory()->for($workspace)->create();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $packaging = PackagingItem::factory()->for($workspace)->create(['name' => 'Amber bottle']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    SupplierListing::factory()->for($workspace)->for($supplier)->for($packaging, 'packagingItem')->create();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'materials'])
        ->assertSee(route('production-bench.inventory.material.ingredient', $ingredient), false)
        ->assertSee(route('production-bench.inventory.material.packaging', $packaging), false)
        ->assertSee(__('production_bench.inventory.open_material_detail'), false);

    $this->get(route('production-bench.inventory.material.ingredient', $ingredient))
        ->assertSee('Olive oil');
    $this->get(route('production-bench.inventory.material.packaging', $packaging))
        ->assertSee('Amber bottle');
});
```

- [x] **Step 2: Run the test and verify the red state**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php --filter='links every material identity'
```

Expected: FAIL because the new explicit affordance copy is absent.

- [x] **Step 3: Add `detail_url` to every material presentation row**

In `formatMaterialPage()`, derive the named route from the already-hydrated subject and attach it to the row. Keep route construction out of Blade.

```php
$row['detail_url'] = $row['subject'] instanceof Ingredient
    ? route('production-bench.inventory.material.ingredient', $row['subject'])
    : route('production-bench.inventory.material.packaging', $row['subject']);
```

- [x] **Step 4: Turn the complete identity cell into the link**

Replace the name-only anchor with one block anchor containing name, code, unit, badges, and a trailing arrow. Use existing accent, focus, danger, and warning tokens. Do not make `<tr>` itself a link and do not add a ninth table column.

```blade
<a
    href="{{ $row['detail_url'] }}"
    wire:navigate
    class="group -m-2 flex min-h-11 items-start justify-between gap-3 rounded-lg p-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
>
    <span class="min-w-0">
        <span class="block font-medium text-[var(--color-ink-strong)] group-hover:text-[var(--color-accent-strong)]">{{ $row['name'] }}</span>
        @if ($row['material_code'])
            <span class="mt-0.5 block font-mono text-xs text-[var(--color-ink-soft)]">{{ $row['material_code'] }}</span>
        @endif
        <span class="mt-0.5 block text-xs text-[var(--color-ink-soft)]">{{ $row['display_unit'] }}</span>
        @unless ($row['has_demand'])
            <span class="mt-1 inline-flex rounded-full bg-[var(--color-field-muted)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_planned_demand') }}</span>
        @endunless
        @if ($row['is_below_buffer'])
            <span class="mt-1 inline-flex rounded-full bg-[var(--color-warning-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-warning-strong)]">{{ __('production_bench.inventory.filter_below_buffer') }}</span>
        @endif
    </span>
    <span class="mt-0.5 shrink-0 text-[var(--color-ink-soft)] group-hover:text-[var(--color-accent-strong)]" aria-hidden="true">→</span>
    <span class="sr-only">{{ __('production_bench.inventory.open_material_detail') }}</span>
</a>
```

- [x] **Step 5: Add translation keys**

Add `inventory.open_material_detail` with English source text `Open material inventory detail` to both translation sources.

- [x] **Step 6: Run the navigation tests**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
```

Expected: PASS.

- [x] **Step 7: Commit the navigation slice**

```bash
git add app/Livewire/ProductionBench/InventoryIndex.php resources/views/livewire/production-bench/inventory-index.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
git commit -m "fix: make inventory materials reliably navigable"
```

---

### Task 2: Add a real material selector and detail links to Lot register

**Files:**
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Test: `tests/Feature/ProductionBenchPagesTest.php`

- [x] **Step 1: Write a failing material-selection test**

Create two ingredients with lots. Call a new `selectLotMaterial()` method using `ingredient:<public_id>`, assert the URL-bound type and ID properties, and assert only the selected ingredient's lot remains.

```php
it('selects a material explicitly in the lot register and links it to detail', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->for($user, 'owner')->create();
    app(ProductionBenchAccess::class)->activate($user, $workspace);
    $olive = Ingredient::factory()->create(['display_name' => 'Olive oil']);
    $coconut = Ingredient::factory()->create(['display_name' => 'Coconut oil']);
    StockLot::factory()->for($workspace)->for($olive)->create();
    StockLot::factory()->for($workspace)->for($coconut)->create();

    $this->actingAs($user);

    Livewire::test(InventoryIndex::class, ['mode' => 'stock'])
        ->set('lotScope', 'all')
        ->call('selectLotMaterial', 'ingredient:'.$olive->public_id)
        ->assertSet('lotMaterialType', 'ingredient')
        ->assertSet('lotMaterial', $olive->public_id)
        ->assertSee('Olive oil')
        ->assertDontSee('Coconut oil')
        ->assertSee(route('production-bench.inventory.material.ingredient', $olive), false);
});
```

- [x] **Step 2: Run the test and verify the red state**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php --filter='selects a material explicitly'
```

Expected: FAIL because `selectLotMaterial()` does not exist and the lot material name is plain text.

- [x] **Step 3: Add bounded material search methods**

Add a 30-result server search that returns compound option IDs. Scope packaging to the workspace. Limit ingredients to tracked workspace materials by using `WorkspaceMaterialInventoryQuery` rather than exposing the global catalogue indiscriminately.

```php
/** @return array<string, string> */
public function lotMaterialSearchResults(string $search): array
{
    return app(WorkspaceMaterialInventoryQuery::class)
        ->materialOptions($this->workspace(), trim($search), self::OPTION_LIMIT);
}

public function selectLotMaterial(?string $selection): void
{
    if (! is_string($selection) || ! str_contains($selection, ':')) {
        $this->lotMaterial = '';
        $this->lotMaterialType = '';
        $this->resetPage('stock-lots');

        return;
    }

    [$type, $publicId] = explode(':', $selection, 2);
    abort_unless(in_array($type, ['ingredient', 'packaging'], true), 422);

    $this->resolveLotMaterial($type, $publicId);
    $this->lotMaterialType = $type;
    $this->lotMaterial = $publicId;
    $this->resetPage('stock-lots');
}
```

Add the corresponding query-service method. It reuses the tracked-material query, loads only the bounded option subjects, and preserves localized ingredient labels.

```php
/** @return array<string, string> */
public function materialOptions(Workspace $workspace, string $search = '', int $limit = 30): array
{
    $rows = $this->query($workspace, [
        'search' => $search,
        'sort' => 'name',
        'direction' => 'asc',
    ])->limit(max(1, min($limit, 50)))->get();

    $subjects = $this->loadSubjects($rows, $workspace);

    return $rows->mapWithKeys(function (object $row) use ($subjects): array {
        $subject = $subjects[$row->subject_type.':'.$row->subject_id] ?? null;

        if (! $subject instanceof Ingredient && ! $subject instanceof PackagingItem) {
            return [];
        }

        $publicId = $subject->public_id;
        $label = $subject instanceof Ingredient
            ? (string) $subject->localizedDisplayName()
            : $subject->name;

        return [$row->subject_type.':'.$publicId => $label];
    })->all();
}
```

`resolveLotMaterial()` must return only an ingredient tracked by the current workspace or a packaging item belonging to it. A forged foreign public ID must result in 404 and must not change the filter.

- [x] **Step 4: Add a searchable Filament `Select` to the Lot register filter schema**

Use a non-native searchable select with `getSearchResultsUsing()`, `getOptionLabelUsing()`, and `afterStateUpdated()` calling `selectLotMaterial()`. Keep `lotMaterial` and `lotMaterialType` as the durable URL state.

```php
Select::make('lotMaterialSelection')
    ->label(__('production_bench.inventory.lot_material'))
    ->placeholder(__('production_bench.inventory.filter_material_placeholder'))
    ->searchable()
    ->getSearchResultsUsing(fn (string $search): array => $this->lotMaterialSearchResults($search))
    ->getOptionLabelUsing(fn (): ?string => $this->lotMaterialSelectionLabel())
    ->live()
    ->afterStateUpdated(fn (?string $state) => $this->selectLotMaterial($state));
```

- [x] **Step 5: Link each Lot register material identity**

Build the detail URL from `$lot->ingredient` or `$lot->packagingItem` and wrap only the subject name in a named-route anchor. Preserve receipt links and Release/Quarantine buttons.

- [x] **Step 6: Add hostile and cross-workspace selection tests**

Test a packaging public ID from another workspace and an invalid compound key. Assert 404 or validation failure and unchanged visible results.

- [x] **Step 7: Run the Lot register tests**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php
```

Expected: PASS.

- [x] **Step 8: Commit the Lot register slice**

```bash
git add app/Livewire/ProductionBench/InventoryIndex.php app/Services/Inventory/WorkspaceMaterialInventoryQuery.php resources/views/livewire/production-bench/inventory-index.blade.php tests/Feature/ProductionBenchPagesTest.php
git commit -m "feat: select and open materials from lot register"
```

---

### Task 3: Show related supplier purchasing listings on material detail

**Files:**
- Create: `app/Services/Inventory/WorkspaceMaterialSupplierListingsQuery.php`
- Create: `tests/Feature/WorkspaceMaterialSupplierListingsQueryTest.php`
- Modify: `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Modify: `lang/en/production_bench.php`
- Modify: `database/seeders/data/interface-translations.json`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [x] **Step 1: Write the failing query-service tests**

Cover active and inactive listings for the selected subject, ingredient and packaging subjects, active-first ordering, supplier-name ordering, pagination, and exclusion of another workspace's listing.

```php
it('paginates only supplier listings for the workspace material', function (): void {
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Alpha Oils']);
    $active = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['is_active' => true]);
    $inactive = SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create(['is_active' => false]);
    SupplierListing::factory()->for($otherWorkspace)->for(Supplier::factory()->for($otherWorkspace))->for($ingredient)->create();

    $page = app(WorkspaceMaterialSupplierListingsQuery::class)
        ->paginate($workspace, $ingredient, perPage: 10, pageName: 'supplier-listings');

    expect($page->pluck('id')->all())->toBe([$active->id, $inactive->id]);
});
```

- [x] **Step 2: Run the service test and verify the red state**

Run:

```bash
php artisan test --compact tests/Feature/WorkspaceMaterialSupplierListingsQueryTest.php
```

Expected: FAIL because the query service does not exist.

- [x] **Step 3: Implement the focused query service**

```php
final class WorkspaceMaterialSupplierListingsQuery
{
    public function paginate(
        Workspace $workspace,
        Ingredient|PackagingItem $subject,
        int $perPage = 10,
        string $pageName = 'supplier-listings',
    ): LengthAwarePaginator {
        return SupplierListing::query()
            ->where('workspace_id', $workspace->id)
            ->when(
                $subject instanceof Ingredient,
                fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
                fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
            )
            ->with('supplier')
            ->orderByDesc('is_active')
            ->orderBy(
                Supplier::query()
                    ->select('name')
                    ->whereColumn('suppliers.id', 'supplier_listings.supplier_id')
                    ->limit(1),
            )
            ->orderBy('id')
            ->paginate($perPage, ['*'], $pageName);
    }
}
```

- [x] **Step 4: Write the failing detail-page test**

Use a listing-only tracked ingredient with no stock lot. Assert its supplier name, SKU, supplier item name, purchase format, active/inactive state, and supplier-detail URL are visible. Assert a foreign-workspace listing is absent.

- [x] **Step 5: Wire the paginator into `InventoryMaterialDetail`**

Inject the new query service and `SupplierListingPricePresentation`. Transform each row to `{listing, price}` using the established Purchasing presentation service. Use a separate `supplierListingsPerPage` allow-listed to `[10, 25, 50]` and page name `supplier-listings`.

- [x] **Step 6: Render a separate Supplier listings section**

Place it after Open lots and before Period activity. Columns:

1. Supplier, linked to `production-bench.purchasing.supplier`.
2. Supplier item name and SKU.
3. Purchase format and net quantity.
4. Latest entered/derived price using the existing price presenter.
5. Active or inactive status.

The empty state must say that no purchasing listings are configured and must not imply that stock is empty.

- [x] **Step 7: Add translation keys**

Add `inventory.related_supplier_listings`, `inventory.related_supplier_listings_help`, and `inventory.no_supplier_listings` to both translation sources.

- [x] **Step 8: Run supplier-listing and detail tests**

Run:

```bash
php artisan test --compact tests/Feature/WorkspaceMaterialSupplierListingsQueryTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
```

Expected: PASS.

- [x] **Step 9: Commit the supplier-listing slice**

```bash
git add app/Services/Inventory/WorkspaceMaterialSupplierListingsQuery.php app/Livewire/ProductionBench/InventoryMaterialDetail.php resources/views/livewire/production-bench/inventory-material-detail.blade.php lang/en/production_bench.php database/seeders/data/interface-translations.json tests/Feature/WorkspaceMaterialSupplierListingsQueryTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
git commit -m "feat: show supplier listings on material inventory detail"
```

---

### Task 4: Bound activity reconciliation for long histories

**Files:**
- Modify: `app/Services/Inventory/MaterialActivityService.php`
- Test: `tests/Feature/MaterialActivityServiceTest.php`

- [x] **Step 1: Write a failing query-shape regression test**

Create movements with positive and negative deltas for multiple movement types. Listen to database queries during `forPeriod()`. Assert totals remain exact and no query selects every raw `type, quantity_delta` row without aggregation.

```php
it('aggregates period totals in bounded database groups', function (): void {
    $queries = collect();
    DB::listen(fn (QueryExecuted $query) => $queries->push(Str::lower($query->sql)));

    $summary = app(MaterialActivityService::class)->forPeriod(
        $workspace,
        $ingredient,
        CarbonImmutable::parse('2026-01-01')->startOfDay(),
        CarbonImmutable::parse('2026-12-31')->endOfDay(),
    );

    expect($summary['reconciliation_delta'])->toBe('0.000000000')
        ->and($queries->contains(fn (string $sql): bool => str_contains($sql, 'group by')
            && str_contains($sql, 'quantity_delta')))->toBeTrue();
});
```

- [x] **Step 2: Run the test and verify the red state**

Run:

```bash
php artisan test --compact tests/Feature/MaterialActivityServiceTest.php --filter='aggregates period totals in bounded database groups'
```

Expected: FAIL because `groupTotals()` currently calls `get(['type', 'quantity_delta'])` for every movement.

- [x] **Step 3: Replace lot ID arrays with a reusable subquery**

Return a query selecting `stock_lots.id` and pass it directly to `whereIn()`. Do not call `pluck()`.

```php
private function lotIdQuery(Workspace $workspace, Ingredient|PackagingItem $subject): Builder
{
    return StockLot::query()
        ->select('stock_lots.id')
        ->where('workspace_id', $workspace->id)
        ->when(
            $subject instanceof Ingredient,
            fn (Builder $query): Builder => $query->where('ingredient_id', $subject->id),
            fn (Builder $query): Builder => $query->where('packaging_item_id', $subject->id),
        );
}
```

Use this subquery in `physicalAt()`, `movementQuery()`, `groupTotals()`, and `openLots()`.

- [x] **Step 4: Aggregate by movement type and sign in SQL**

Group by `type` and a portable `CASE` sign bucket so corrections with the same type but opposite signs remain classified correctly. The result set is bounded to at most twice the enum case count.

```php
$signExpression = "CASE WHEN quantity_delta < 0 THEN 'negative' ELSE 'non_negative' END";

DB::table('stock_movements')
    ->where('workspace_id', $workspace->id)
    ->whereIn('stock_lot_id', $this->lotIdQuery($workspace, $subject))
    ->whereBetween('occurred_at', [$from, $to])
    ->select('type')
    ->selectRaw("{$signExpression} AS sign_bucket")
    ->selectRaw('SUM(quantity_delta) AS quantity_delta')
    ->groupBy('type')
    ->groupByRaw($signExpression)
    ->get()
    ->each(function (object $row) use (&$totals): void {
        $delta = bcadd((string) $row->quantity_delta, '0', 9);
        $group = $this->groupFor($row->type, $delta);

        $totals[$group] = in_array($group, ['production_consumed', 'other_outbound'], true)
            ? bcadd($totals[$group], bccomp($delta, '0', 9) < 0 ? bcmul($delta, '-1', 9) : '0', 9)
            : bcadd($totals[$group], $delta, 9);
    });
```

Pass the subject or the lot-ID subquery into the helpers rather than materializing IDs.

- [x] **Step 5: Add a high-cardinality correctness test**

Create more than one activity page and enough lots to expose accidental `pluck()` or non-paginated model hydration. Assert the activity paginator contains only the configured page size while reconciliation covers all movements.

- [x] **Step 6: Run the service and detail tests**

Run:

```bash
php artisan test --compact tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
```

Expected: PASS on SQLite. The generated query must also remain valid PostgreSQL SQL.

- [x] **Step 7: Commit the activity slice**

```bash
git add app/Services/Inventory/MaterialActivityService.php tests/Feature/MaterialActivityServiceTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
git commit -m "perf: bound material activity reconciliation"
```

---

### Task 5: Move public inventory controls onto Filament schemas

**Files:**
- Modify: `app/Livewire/ProductionBench/InventoryIndex.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Modify: `app/Livewire/ProductionBench/InventoryMaterialDetail.php`
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Test: `tests/Feature/ProductionBenchPagesTest.php`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [x] **Step 1: Write failing form-state tests**

Use Livewire to change search, sort, category, dependent subcategory, Lot scope, Lot material, period preset, and custom dates. Assert the same URL-bound public properties and filtered results as before. These tests protect behavior while the rendering substrate changes.

- [x] **Step 2: Run the focused tests and record the baseline**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
```

Expected: existing behavior tests PASS; new schema-presence assertions FAIL.

- [x] **Step 3: Add `materialFiltersForm()` and `lotFiltersForm()`**

Follow the established `SupplierListingIndex` pattern with `TextInput`, `Select`, `DatePicker`, `Grid`, `live()`, and reset-page callbacks. Keep the existing URL-bound properties as the canonical state paths so bookmarked URLs do not change.

Material form fields:

- `search`, debounce 300 ms.
- `sort` and `direction`.
- `materialType`.
- `stockState`.
- `demandFilter`.
- searchable `categoryFilter`.
- searchable `subcategoryFilter`, disabled until category is set, options constrained by category.

Lot form fields:

- `search`, debounce 300 ms.
- `lotMaterialSelection` using Task 2 methods.
- `lotScope`, `lotStatus`, `lotSupplier`, `lotOrigin`.
- `lotStockedFrom`, `lotStockedUntil`, `lotExpiry`, `lotSort`.

Every live field must reset only the relevant named paginator.

- [x] **Step 4: Render the named schemas in Blade**

Replace handwritten input/select blocks with:

```blade
{{ $this->materialFiltersForm }}
```

and:

```blade
{{ $this->lotFiltersForm }}
```

Keep applied-filter chips outside the schemas because they are navigation shortcuts, not input fields.

- [x] **Step 5: Add `activityFiltersForm()` to material detail**

Use a non-native period `Select` and two conditional `DatePicker` fields. Preserve the existing `period`, `from`, and `to` URL aliases and custom-period validation. Reset only the `activity` paginator when period state changes.

- [x] **Step 6: Render and test the period schema**

Replace the handwritten period inputs with `{{ $this->activityFiltersForm }}`. Assert 30-day, 365-day, and custom states still update totals without changing current position.

- [x] **Step 7: Run tests and Filament compatibility checks**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
vendor/bin/filacheck --fix
```

Expected: tests PASS; Filacheck reports no unresolved issues.

- [x] **Step 8: Commit the form-substrate slice**

```bash
git add app/Livewire/ProductionBench/InventoryIndex.php app/Livewire/ProductionBench/InventoryMaterialDetail.php resources/views/livewire/production-bench/inventory-index.blade.php resources/views/livewire/production-bench/inventory-material-detail.blade.php tests/Feature/ProductionBenchPagesTest.php tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
git commit -m "refactor: render inventory filters with filament"
```

---

### Task 6: Close remaining reviewed standards gaps

**Files:**
- Modify: `app/Services/Inventory/WorkspaceMaterialSettings.php`
- Modify: `app/Services/Inventory/WorkspaceMaterialInventoryQuery.php`
- Modify: `resources/views/livewire/production-bench/inventory-index.blade.php`
- Test: `tests/Feature/WorkspaceMaterialSettingTest.php`
- Test: `tests/Feature/ProductionBenchPagesTest.php`

- [x] **Step 1: Add a below-buffer visual-state assertion**

Create a material below its configured buffer but without negative forecast. Assert the row contains the established warning-soft background and the text badge.

- [x] **Step 2: Run the test and verify the red state**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchPagesTest.php --filter='below buffer'
```

Expected: FAIL because only shortage rows currently receive a background.

- [x] **Step 3: Apply independent semantic row states**

Use danger-soft for negative forecast and warning-soft for below-buffer-only rows. Danger takes precedence when both are true. Keep the text badge so color is never the only signal.

- [x] **Step 4: Make the settings lock conforming**

Change the locked lookup to:

```php
$existing = WorkspaceMaterialSetting::query()
    ->withoutGlobalScopes()
    ->where($keys)
    ->lockForUpdate()
    ->first();
```

Retain `assertWritable()` inside the transaction and `attempts: 5`.

- [x] **Step 5: Remove the duplicated adjacent PHPDoc block**

Keep one complete array-shape declaration above the corresponding method in `WorkspaceMaterialInventoryQuery`.

- [x] **Step 6: Run the focused tests**

Run:

```bash
php artisan test --compact tests/Feature/WorkspaceMaterialSettingTest.php tests/Feature/WorkspaceMaterialInventoryQueryTest.php tests/Feature/ProductionBenchPagesTest.php
```

Expected: PASS.

- [x] **Step 7: Commit the standards slice**

```bash
git add app/Services/Inventory/WorkspaceMaterialSettings.php app/Services/Inventory/WorkspaceMaterialInventoryQuery.php resources/views/livewire/production-bench/inventory-index.blade.php tests/Feature/WorkspaceMaterialSettingTest.php tests/Feature/ProductionBenchPagesTest.php
git commit -m "fix: harden inventory ux implementation"
```

---

### Task 7: Verify, inspect, and prepare the branch for integration

**Files:**
- Verify all files listed in this plan.
- Update: `graphify-out/` through the project command.

- [x] **Step 1: Run the complete affected suite**

```bash
php artisan test --compact \
  tests/Feature/MaterialActivityServiceTest.php \
  tests/Feature/ProductionBenchInventoryMaterialDetailTest.php \
  tests/Feature/ProductionBenchPagesTest.php \
  tests/Feature/WorkspaceMaterialCatalogTest.php \
  tests/Feature/WorkspaceMaterialInventoryQueryTest.php \
  tests/Feature/WorkspaceMaterialSettingTest.php \
  tests/Feature/WorkspaceMaterialSupplierListingsQueryTest.php \
  tests/Unit/ProductionBenchNavigationTest.php
```

Expected: all tests PASS with zero failures.

- [x] **Step 2: Format modified PHP**

```bash
vendor/bin/pint --dirty --format agent
```

Expected: exit code 0.

- [x] **Step 3: Run Filament compatibility analysis**

```bash
vendor/bin/filacheck --fix
```

Expected: no unresolved deprecated or incompatible Filament usage.

- [x] **Step 4: Validate the translation catalogue**

```bash
php artisan test --compact tests/Feature/InterfaceTranslationCatalogueTest.php
```

Expected: PASS.

- [x] **Step 5: Refresh the code graph**

```bash
graphify update .
```

Expected: the new supplier-listing query service and updated inventory relationships appear in `graphify-out/`.

- [x] **Step 6: Inspect the final branch state**

```bash
git diff --check
git status --short
git log --oneline --decorate -10
```

Expected: no whitespace errors; only intentional generated graph changes remain uncommitted before the final commit.

- [x] **Step 7: Commit verification artifacts**

```bash
git add graphify-out
git commit -m "chore: refresh inventory code graph"
```

- [x] **Step 8: Perform actual-app acceptance, not mockup acceptance**

Open the Laravel application from a checkout serving `codex/production-bench-inventory-ux`. Do not validate against `.superpowers/brainstorm/*.html`.

Verify manually:

1. Stock by material opens an ingredient and packaging detail from the full identity cell.
2. A listing-only ingredient shows supplier listings even with no open lots.
3. Open lots show supplier names and View all lots opens the filtered Lot register.
4. Lot register can select another material using the combobox.
5. Lot register material names return to material detail.
6. Last 30 days, Last 365 days, and custom periods update activity but not current position.
7. Read-only users see details but no buffer or lot-state mutation actions.

- [x] **Step 9: Request the complete suite**

After the affected suite passes, ask the project owner to run:

```bash
php artisan test --compact
```

Do not merge to `main` until that result and visual acceptance are confirmed.

---

## Completion criteria

- The material-detail journey is exercised from the rendered Stock by material page through a successful HTTP response.
- Material identity has a clear, keyboard-focusable affordance in both inventory tables.
- Lot register offers a searchable, workspace-scoped material selector with durable URL state.
- Material detail distinguishes physical lots from supplier purchasing listings.
- Listing-only materials remain useful on detail pages.
- Activity rows are paginated and summary processing is bounded by movement groups, not movement count.
- Public inventory inputs render through Filament schemas.
- Negative forecast and below-buffer states remain visually and semantically distinct.
- All current counter-review fixes are committed on `codex/production-bench-inventory-ux`; no implementation is left only in a dirty worktree.
