# Material Detail Progressive Disclosure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the inventory material detail into a decision-first operational page that keeps stock position and open lots immediately visible while progressively disclosing supporting purchasing and activity data.

**Architecture:** Keep the existing Livewire queries, actions, pagination, routes, permissions, and localized values intact. Recompose the Blade view around one stock summary card, the always-visible open-lots table, and native `<details>` disclosures whose open state is synchronized with Alpine so Livewire updates do not collapse a section the user is working in. Reuse existing translation keys and data already supplied by `InventoryMaterialDetail::render()`; this change adds no queries and no new interface copy.

**Tech Stack:** Laravel 13, Livewire 4, Blade, Alpine.js, Tailwind CSS 4, Pest 4

---

## Scope and file map

- Modify `resources/views/livewire/production-bench/inventory-material-detail.blade.php`: establish the decision hierarchy and disclosure behavior.
- Modify `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`: cover hierarchy, default disclosure state, server-rendered reopening, and the single lot-register link.
- Do not change `app/Livewire/ProductionBench/InventoryMaterialDetail.php`: the view already receives every value and paginator required by the design.
- Do not add translation keys: reuse `current_position`, `available`, `forecast`, `buffer_stock`, `buffer_none`, `common.details`, `related_supplier_listings`, `period_activity`, `net_change`, and the existing help copy.
- Preserve the three tables, their responsive floors and sticky-header thresholds, both paginators, the period filter form, reconciliation, buffer actions, tenant checks, and read-only behavior.
- Leave the free anonymous soap calculator and the external CMS boundary outside this page change.

## Final information hierarchy

1. Material identity, code, and back link.
2. Stock summary: Available and Forecast as primary values, with buffer status and controls in the same card.
3. A closed Current position details disclosure containing Physical, Reserved, Quarantined, Incoming, and Required.
4. Open lots, fully visible, with the page's single View all lots link.
5. Supplier listings, closed by default with its total count visible in the summary.
6. Period activity, closed by default with the selected period and Net change visible in the summary.

### Task 1: Pin the decision-first hierarchy with a failing feature test

**Files:**
- Modify: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] **Step 1: Add a DOM helper for semantic assertions**

Add this helper above `materialDetailWorkspace()` at the bottom of the test file:

```php
function materialDetailXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML($html);

    return new DOMXPath($document);
}
```

- [ ] **Step 2: Add the failing hierarchy test**

Add this test after the existing tracked-ingredient detail test:

```php
it('prioritizes stock decisions and progressively discloses supporting material data', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Decision oil']);
    $supplier = Supplier::factory()->for($workspace)->create(['name' => 'Decision supplier']);
    SupplierListing::factory()->for($workspace)->for($supplier)->for($ingredient)->create();
    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);
    WorkspaceMaterialSetting::factory()->for($workspace)->for($ingredient)->create([
        'buffer_quantity' => '500.000000000',
    ]);
    $this->actingAs($user);

    $html = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->html();
    $xpath = materialDetailXPath($html);

    expect($xpath->query('//*[@data-material-stock-summary]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-material-stock-summary]//*[@data-position-primary]')->length)->toBe(2)
        ->and($xpath->query('//*[@data-material-stock-summary]//*[@data-material-buffer]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-material-position-breakdown]//*[@data-position-secondary]')->length)->toBe(5)
        ->and($xpath->query('//details[@data-material-position-breakdown][not(@open)]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-material-supplier-listings][not(@open)]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-material-activity][not(@open)]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-material-open-lots]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-material-view-all-lots]')->length)->toBe(1)
        ->and($xpath->query('//details[@data-material-activity]//summary//*[normalize-space()="Net change"]')->length)->toBe(1);
});
```

- [ ] **Step 3: Run the new test and verify the structural failure**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php --filter='prioritizes stock decisions'
```

Expected: FAIL because the stock summary and disclosure markers do not exist and the page still renders two View all lots links.

### Task 2: Combine stock position and buffer into one operational summary

**Files:**
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php:12-58`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] **Step 1: Remove the duplicate header action**

Replace the page header with this single-column identity block:

```blade
<header>
    <a href="{{ route('production-bench.inventory') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.inventory.stock_by_material') }}</a>
    <h1 class="mt-3 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $materialName }}</h1>
    @if ($materialCode)<p class="mt-1 font-mono text-sm text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
</header>
```

The contextual link remains in Open lots, where its destination is clear.

- [ ] **Step 2: Replace the separate Current position and Buffer stock cards**

Use one section with two prominent values, the buffer controls, and a native secondary disclosure:

```blade
<section data-material-stock-summary class="sk-card overflow-hidden" aria-labelledby="current-position-heading">
    <div class="border-b border-[var(--color-line)] px-5 py-4">
        <h2 id="current-position-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.current_position') }}</h2>
        <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.current_position_help', ['unit' => $displayUnit]) }}</p>
    </div>

    <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.72fr)]">
        <dl class="grid grid-cols-2 divide-x divide-[var(--color-line)]">
            @foreach (['available', 'forecast'] as $key)
                <div data-position-primary="{{ $key }}" class="px-5 py-5">
                    <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.'.$key) }}</dt>
                    <dd class="numeric mt-1 text-2xl font-semibold {{ $key === 'forecast' && str_starts_with($position[$key], '-') ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $position[$key] }}</dd>
                </div>
            @endforeach
        </dl>

        <div data-material-buffer class="border-t border-[var(--color-line)] px-5 py-5 lg:border-l lg:border-t-0">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between lg:flex-col lg:items-stretch">
                <div>
                    <h3 id="buffer-heading" class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.buffer_stock') }}</h3>
                    @if ($buffer !== null)
                        <p class="mt-1 text-sm {{ $bufferBelow ? 'text-[var(--color-warning-strong)]' : 'text-[var(--color-ink-soft)]' }}">
                            {{ $bufferBelow ? __('production_bench.inventory.below_buffer_detail') : __('production_bench.inventory.above_buffer_detail') }}
                            <span class="numeric font-semibold">{{ $buffer }} {{ $displayUnit }}</span>
                        </p>
                    @else
                        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.buffer_none') }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    {{ $this->editBufferAction }}
                    {{ $this->clearBufferAction }}
                </div>
            </div>
        </div>
    </div>

    <details
        data-material-position-breakdown
        x-data="{ open: false }"
        :open="open"
        @toggle="open = $el.open"
        class="border-t border-[var(--color-line)]"
    >
        <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)]">
            {{ __('production_bench.inventory.current_position') }} · {{ __('production_bench.common.details') }}
        </summary>
        <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] border-t border-[var(--color-line)] sm:grid-cols-5 sm:divide-y-0">
            @foreach (['physical', 'reserved', 'quarantined', 'incoming', 'required'] as $key)
                <div data-position-secondary="{{ $key }}" class="px-5 py-4">
                    <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.'.$key) }}</dt>
                    <dd class="numeric mt-1 text-base font-semibold text-[var(--color-ink-strong)]">{{ $position[$key] }}</dd>
                </div>
            @endforeach
        </dl>
    </details>
</section>
```

- [ ] **Step 3: Mark the always-visible operational table and its single link**

Add `data-material-open-lots` to the existing Open lots `<section>`. Add `data-material-view-all-lots` to the existing View all lots link inside that section. Do not change the table rows, status text, sticky header, or responsive floor.

- [ ] **Step 4: Rerun the hierarchy test**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php --filter='prioritizes stock decisions'
```

Expected: supplier and activity assertions still FAIL because those sections are not disclosures yet; stock summary, breakdown, open-lots, and single-link assertions PASS.

### Task 3: Convert supplier listings and period activity into resilient disclosures

**Files:**
- Modify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php:110-222`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] **Step 1: Wrap Supplier listings in a native details element**

Replace its outer `<section>` and heading block with this disclosure opening:

```blade
@php($supplierListingsInitiallyOpen = $supplierListings->currentPage() > 1)
<details
    data-material-supplier-listings
    x-data="{ open: @js($supplierListingsInitiallyOpen) }"
    :open="open"
    @toggle="open = $el.open"
    @if ($supplierListingsInitiallyOpen) open @endif
    class="group @container overflow-clip sk-card"
>
    <summary class="cursor-pointer px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)] group-open:border-b group-open:border-[var(--color-line)]">
        <span class="flex items-start justify-between gap-4">
            <span>
                <span id="supplier-listings-heading" role="heading" aria-level="2" class="block text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.related_supplier_listings') }}</span>
                <span class="mt-1 block text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.related_supplier_listings_help') }}</span>
            </span>
            <span class="numeric shrink-0 rounded-full bg-[var(--color-panel-muted)] px-2.5 py-1 text-xs font-semibold text-[var(--color-ink-strong)]">{{ $supplierListings->total() }}</span>
        </span>
    </summary>
```

Keep the existing table and pagination immediately after the summary, then replace the section's closing `</section>` with `</details>`.

- [ ] **Step 2: Wrap Period activity in a native details element**

Replace its outer `<section>` and heading block with:

```blade
@php($activityInitiallyOpen = $periodPreset !== '30' || $customFrom !== '' || $customTo !== '' || $movements->currentPage() > 1)
<details
    data-material-activity
    x-data="{ open: @js($activityInitiallyOpen) }"
    :open="open"
    @toggle="open = $el.open"
    @if ($activityInitiallyOpen) open @endif
    class="group @container overflow-clip sk-card"
>
    <summary class="cursor-pointer px-5 py-4 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)] group-open:border-b group-open:border-[var(--color-line)]">
        <span class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <span>
                <span id="activity-heading" role="heading" aria-level="2" class="block text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.period_activity') }}</span>
                <span class="mt-1 block text-sm text-[var(--color-ink-soft)]">{{ $periodLabel }}</span>
            </span>
            <span class="sm:text-right">
                <span class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.net_change') }}</span>
                <span class="numeric ml-2 text-sm font-semibold text-[var(--color-ink-strong)]">{{ $activity['net_change'] }} {{ $displayUnit }}</span>
            </span>
        </span>
    </summary>
```

Keep the existing filter form, activity metrics, reconciliation, movement table, and pagination after the summary. Replace the closing `</section>` with `</details>`.

The server-rendered `open` attribute makes non-default query state visible before Alpine starts. Alpine then mirrors the native toggle state so a Livewire update inside an open section does not collapse it.

- [ ] **Step 3: Rerun the hierarchy test**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php --filter='prioritizes stock decisions'
```

Expected: PASS.

### Task 4: Cover disclosure reopening and existing behavior

**Files:**
- Modify: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`
- Test: `tests/Feature/ProductionBenchInventoryMaterialDetailTest.php`

- [ ] **Step 1: Add a failing server-state reopening test**

```php
it('opens supporting disclosures when their URL-backed content is active', function (): void {
    ['user' => $user, 'workspace' => $workspace] = materialDetailWorkspace();
    $ingredient = Ingredient::factory()->create(['display_name' => 'Disclosure oil']);
    $supplier = Supplier::factory()->for($workspace)->create();

    foreach (range(1, 11) as $index) {
        SupplierListing::factory()
            ->for($workspace)
            ->for($supplier)
            ->for($ingredient)
            ->create(['supplier_item_name' => "Listing {$index}"]);
    }

    $lot = StockLot::factory()->for($workspace)->for($ingredient)->released()->create();
    StockMovement::factory()->for($lot, 'stockLot')->create([
        'workspace_id' => $workspace->id,
        'type' => StockMovementType::OpeningBalance,
        'quantity_delta' => '1000',
    ]);
    $this->actingAs($user);

    $component = Livewire::test(InventoryMaterialDetail::class, [
        'subject' => $ingredient->public_id,
        'subjectType' => 'ingredient',
    ])->call('gotoPage', 2, 'supplier-listings');
    $supplierDetails = materialDetailXPath($component->html())
        ->query('//details[@data-material-supplier-listings]')
        ->item(0);

    expect($supplierDetails->hasAttribute('open'))->toBeTrue();

    $component->set('periodPreset', '365');
    $activityDetails = materialDetailXPath($component->html())
        ->query('//details[@data-material-activity]')
        ->item(0);

    expect($activityDetails->hasAttribute('open'))->toBeTrue();
});
```

- [ ] **Step 2: Run the reopening test**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php --filter='opens supporting disclosures'
```

Expected: PASS once Task 3 is complete.

- [ ] **Step 3: Run the complete material-detail feature file**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php
```

Expected: PASS. Existing coverage must still prove localization, exact decimal presentation, buffer authorization and persistence, tenant isolation, both paginators, reconciliation, source links, and all three sticky-table contracts.

### Task 5: Verify rendering, accessibility, and responsive behavior

**Files:**
- Verify: `resources/views/livewire/production-bench/inventory-material-detail.blade.php`
- Verify: generated Vite assets

- [ ] **Step 1: Format the changed PHP test**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: exit code 0.

- [ ] **Step 2: Run localization and material-detail tests after formatting**

Run:

```bash
php artisan test --compact tests/Feature/ProductionBenchInventoryMaterialDetailTest.php tests/Feature/ProductionBenchLocalizationTest.php
```

Expected: PASS with English and French headings and disclosure summaries intact.

- [ ] **Step 3: Build production assets**

Run:

```bash
npm run build
```

Expected: exit code 0. Confirm the generated application stylesheet contains the disclosure classes used by the Blade view.

- [ ] **Step 4: Inspect the authenticated material page at the supported extremes**

Open the example material route through the authenticated application shell and check this matrix:

| Condition | Expected result |
| --- | --- |
| 320px reflow | Stock summary becomes one readable column; no page-level horizontal scroll; tables scroll only inside their wrappers when opened. |
| 1440px viewport | Available, Forecast, and Buffer share the first card; Open lots follows immediately; Supplier listings and Period activity remain compact summaries. |
| 200% zoom | Every summary and action remains reachable, wraps without clipping, and preserves the same reading order. |
| French locale | Summary labels and metric values wrap without fixed-height clipping. |
| Keyboard only | Tab reaches each `<summary>` and buffer action in source order; Enter and Space toggle each disclosure; every focus indicator remains visible. |
| Accessibility tree | Each summary exposes a button-like disclosure control with its name and expanded/collapsed state; closed content is absent until expanded. |

- [ ] **Step 5: Recheck the three sticky table thresholds when expanded**

For Open lots, Supplier listings, and Period activity, verify the table wrapper retains horizontal scrolling below its current floor and switches to visible overflow only after the table fits. Scroll the page vertically at a wide viewport and confirm each expanded table header sticks to the viewport; do not change the existing 57rem/900px, 54rem/860px, or 48rem/760px pairs unless rendered measurement disproves them.

- [ ] **Step 6: Refresh the repository graph and audit scope**

Run:

```bash
graphify update .
git diff --check
git diff -- app/Livewire/ProductionBench/InventoryMaterialDetail.php
```

Expected: Graphify succeeds, the diff has no whitespace errors, and the Livewire class diff is empty.

- [ ] **Step 7: Run the complete suite before committing**

Run:

```bash
php artisan test --compact
```

Expected: the complete suite passes with only the repository's existing skips.
