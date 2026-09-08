<x-production-bench.page active="inventory" :subnavigation="$mode">
    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <header>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $mode === 'stock' ? __('production_bench.inventory.lot_register') : __('production_bench.inventory.stock_by_material') }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ $mode === 'stock' ? __('production_bench.inventory.stock_help') : __('production_bench.inventory.materials_help') }}</p>
        </header>

        @if ($mode === 'materials')
            <section data-inventory-materials aria-labelledby="inventory-materials-heading" class="@container overflow-clip sk-card">
                {{-- The card title repeated the page <h1> word for word: this screen shows one
                     section at a time, so both read "Stock by material" ~40px apart. Kept as the
                     region's accessible name but hidden from the eye, which is how
                     production-index.blade.php:56 already resolves the same collision. --}}
                <div class="flex border-b border-[var(--color-line)] px-5 py-4">
                    <h2 id="inventory-materials-heading" class="sr-only">{{ __('production_bench.inventory.stock_by_material') }}</h2>
                    <p class="text-xs text-[var(--color-ink-soft)]">
                        {{ trans_choice('production_bench.inventory.materials_count', $materials->total()) }}
                        · {{ trans_choice('production_bench.inventory.without_demand_count', $inventorySummary['unplanned']) }}
                    </p>
                </div>

                <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] border-b border-[var(--color-line)] sm:grid-cols-4 sm:divide-y-0">
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.materials') }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ $inventorySummary['materials'] }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.production.shortage') }}</dt>
                        <dd class="mt-1">
                            {{-- The tile is a shortcut into the same state the filter panel offers, so it
                                 toggles stockState rather than only reporting the count. --}}
                            <button
                                type="button"
                                wire:click="toggleShortageFilter"
                                data-inventory-shortage-filter
                                aria-pressed="{{ $stockState === 'negative_forecast' ? 'true' : 'false' }}"
                                {{-- WCAG 2.5.3 Label in Name: what the eye reads here is the number, so
                                     the accessible name has to carry it too. Announcing only the state
                                     made a screen reader say "Negative forecast" for a control that
                                     visibly reads "3" — and used the filter panel's wording while the
                                     tile above it says "Shortage". The term matches the <dt>. --}}
                                aria-label="{{ __('production_bench.production.shortage') }} ({{ $inventorySummary['shortages'] }})"
                                @class([
                                    'numeric text-lg font-semibold rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]',
                                    'text-[var(--color-danger-strong)] hover:underline' => $inventorySummary['shortages'] > 0,
                                    'text-[var(--color-ink-strong)]' => $inventorySummary['shortages'] === 0,
                                ])
                            >{{ $inventorySummary['shortages'] }}</button>
                        </dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.incoming') }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ $inventorySummary['incoming'] }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.below_buffer') }}</dt>
                        <dd class="mt-1">
                            <button
                                type="button"
                                wire:click="toggleBelowBufferFilter"
                                data-inventory-below-buffer-filter
                                aria-pressed="{{ $stockState === 'below_buffer' ? 'true' : 'false' }}"
                                aria-label="{{ __('production_bench.inventory.below_buffer') }} ({{ $inventorySummary['below_buffer'] }})"
                                @class([
                                    'numeric text-lg font-semibold rounded focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]',
                                    'text-[var(--color-warning-strong)] hover:underline' => $inventorySummary['below_buffer'] > 0,
                                    'text-[var(--color-ink-strong)]' => $inventorySummary['below_buffer'] === 0,
                                ])
                            >{{ $inventorySummary['below_buffer'] }}</button>
                        </dd>
                    </div>
                </dl>

                {{-- Filament renders its dropdown panel as `position: absolute; z-index: 20` and
                     does not teleport it, so it competes directly with the sticky `z-20` thead
                     below — and loses, because the thead comes later in the DOM. Making the
                     filter wrapper its own stacking context above the header lifts every
                     dropdown inside it, whatever z-index the panel itself carries. --}}
                <div
                    data-production-bench-filters
                    x-data="{ filtersOpen: @js($materialFiltersActive) }"
                    class="relative z-30 border-b border-[var(--color-line)] p-4"
                >
                    {{ $this->materialFiltersForm }}

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="sk-btn sk-btn-ghost"
                            aria-controls="material-advanced-filters"
                            x-bind:aria-expanded="filtersOpen.toString()"
                            x-on:click="filtersOpen = ! filtersOpen"
                        >
                            {{ __('production_bench.common.filters') }}
                        </button>

                        @if ($materialFiltersActive)
                            <button type="button" wire:click="clearMaterialFilters" class="sk-btn sk-btn-ghost">
                                {{ __('production_bench.inventory.clear_filters') }}
                            </button>
                        @endif
                    </div>

                    <div id="material-advanced-filters" class="mt-3" x-cloak x-show="filtersOpen">
                        {{ $this->materialAdvancedFiltersForm }}
                    </div>

                    <p id="inventory-search-help" class="mt-3 px-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.search_help') }}</p>

                    @if ($materialFiltersActive)
                        {{-- `role="group"`: an `aria-label` on a bare <div> is dropped, because a
                             generic div has no role to carry a name. --}}
                        <div role="group" aria-label="{{ __('production_bench.inventory.filters') }}" class="mt-3 flex flex-wrap gap-2">
                            @if ($materialType !== 'all')<button type="button" wire:click="$set('materialType', 'all')" class="sk-badge sk-badge-neutral">{{ $materialType === 'ingredient' ? __('production_bench.inventory.filter_ingredients') : __('production_bench.inventory.filter_packaging') }} ×</button>@endif
                            {{-- `negative_forecast` is the state's internal name; the word shown
                                 is the tile's, not a second "Negative forecast" of its own. --}}
                            @if ($stockState !== 'all')<button type="button" wire:click="$set('stockState', 'all')" class="sk-badge sk-badge-neutral">{{ $stockState === 'negative_forecast' ? __('production_bench.production.shortage') : __('production_bench.inventory.filter_'.$stockState) }} ×</button>@endif
                            @if ($demandFilter !== 'all')<button type="button" wire:click="$set('demandFilter', 'all')" class="sk-badge sk-badge-neutral">{{ $demandFilter === 'planned' ? __('production_bench.inventory.filter_with_demand') : __('production_bench.inventory.filter_without_demand') }} ×</button>@endif
                            @if ($categoryFilter !== '')<button type="button" wire:click="$set('categoryFilter', '')" class="sk-badge sk-badge-neutral">{{ $categoryOptions[$categoryFilter] ?? $categoryFilter }} ×</button>@endif
                            @if ($subcategoryFilter !== '')<button type="button" wire:click="$set('subcategoryFilter', '')" class="sk-badge sk-badge-neutral">{{ $subcategoryOptions[$subcategoryFilter] ?? $subcategoryFilter }} ×</button>@endif
                        </div>
                    @endif
                </div>

                {{-- No height cap: capping the box fixed the list length at roughly one
                     screen no matter what "Rows per page" said, which made the selector
                     pointless. The page scrolls instead, so the thead sticks to the
                     viewport and the selector decides how long the list actually is.

                     Horizontal scrolling is the only thing left to trade. A wrapper that
                     scrolls in X is still a scroll container, and `sticky top-0` resolves
                     against the nearest one — so while it is there, the header pins to
                     the wrapper (which is exactly content-height and never scrolls) and
                     silently does nothing. The wrapper therefore only scrolls while the
                     card is narrower than the table; once the table fits, overflow goes
                     back to `visible` and the header sticks to the viewport. `overflow-clip`
                     on the card keeps the rounded corners without creating the scrollport
                     that `overflow-hidden` would.

                     The threshold matches the table's floor, and the floor is measured: this
                     table needs 834px before any cell wraps, so 880px keeps a little air and
                     means the header sticks on any card at least that wide. --}}
                <div class="overflow-x-auto @min-[55rem]:overflow-x-visible">
                    <table class="w-full min-w-[880px] text-left text-sm">
                        <thead class="sticky top-0 z-20 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)] shadow-[0_1px_0_0_var(--color-line)]">
                            <tr>
                                <th class="sticky left-0 z-30 border-r border-[var(--color-line)] bg-[var(--color-panel-muted)] px-5 py-3">{{ __('production_bench.inventory.material') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.physical') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.quarantined') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.incoming') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.required') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('production_bench.inventory.forecast') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @forelse ($materials as $row)
                                {{-- Below buffer is its own fact rather than a weaker shortage, so it
                                     gets its own tint. Shortage wins when both hold, because a row
                                     that cannot cover planned demand is the more urgent of the two.
                                     The tint carries the state on its own: a shortage already reads
                                     as a signed negative forecast, and below buffer keeps a dot. --}}
                                <tr
                                    wire:key="inventory-material-{{ $row['key'] }}"
                                    @class([
                                        'bg-[var(--color-danger-soft)]/40' => $row['is_shortage'],
                                        'bg-[var(--color-warning-soft)]/40' => $row['is_below_buffer'] && ! $row['is_shortage'],
                                    ])
                                >
                                    {{-- The identity cell stays put while the numeric columns scroll under
                                         it, so it needs its own opaque fill: the row tint is /40 and would
                                         let the scrolling values show through. color-mix bakes the tint
                                         into the panel colour at the same ratio, keeping it token-driven. --}}
                                    <td
                                        @class([
                                            'sticky left-0 z-10 border-r border-[var(--color-line)] px-5 py-3',
                                            'bg-[var(--color-panel)]' => ! $row['is_shortage'] && ! $row['is_below_buffer'],
                                            'bg-[color-mix(in_oklab,var(--color-danger-soft)_40%,var(--color-panel))]' => $row['is_shortage'],
                                            'bg-[color-mix(in_oklab,var(--color-warning-soft)_40%,var(--color-panel))]' => $row['is_below_buffer'] && ! $row['is_shortage'],
                                        ])
                                    >
                                        {{-- A table row cannot be wrapped in an anchor, so the whole identity cell
                                             is one block link instead of just the name. --}}
                                        <a
                                            href="{{ $row['detail_url'] }}"
                                            wire:navigate
                                            class="group -m-2 flex min-h-11 items-start justify-between gap-3 rounded-lg p-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                        >
                                            <span class="min-w-0">
                                                <span class="block font-medium text-[var(--color-ink-strong)] group-hover:text-[var(--color-accent-strong)]">{{ $row['name'] }}@if ($row['is_below_buffer'])<span class="ml-1.5 inline-block size-2 rounded-full bg-[var(--color-warning-strong)] align-middle" title="{{ __('production_bench.inventory.filter_below_buffer') }}" aria-hidden="true"></span><span class="sr-only">{{ __('production_bench.inventory.filter_below_buffer') }}</span>@endif</span>
                                                @if ($row['material_code'])
                                                    <span class="mt-0.5 block font-mono text-xs text-[var(--color-ink-soft)]">{{ $row['material_code'] }}</span>
                                                @endif
                                                <span class="mt-0.5 block text-xs text-[var(--color-ink-soft)]">{{ $row['display_unit'] }}</span>
                                            </span>
                                            <span class="mt-0.5 shrink-0 text-[var(--color-ink-soft)] group-hover:text-[var(--color-accent-strong)]" aria-hidden="true">&rarr;</span>
                                            <span class="sr-only">{{ __('production_bench.inventory.open_material_detail') }}</span>
                                        </a>
                                    </td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['physical'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['available'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['reserved'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['quarantined'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['incoming'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['required'] }}</td>
                                    {{-- A shortage already reads as a signed negative number in danger
                                         text, so the number is the label and a repeated pill only
                                         competed with it for space in the cell. --}}
                                    <td class="numeric px-5 py-3 text-right font-semibold {{ $row['is_shortage'] ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">
                                        {{ $row['positions']['forecast'] }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ $materialFiltersActive ? __('production_bench.inventory.no_materials_match') : __('production_bench.inventory.no_materials') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($materials)
                    <x-table-pagination :paginator="$materials" :per-page-label="__('production_bench.inventory.stock_by_material')" />
                @endif
            </section>
        @else
            <section data-stock-register aria-labelledby="inventory-positions-heading" class="@container overflow-clip sk-card">
                {{-- Same collision as the materials card: the visible <h2> echoed the <h1>.
                     Hidden, keeping the region named. --}}
                <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-baseline gap-3">
                        <h2 id="inventory-positions-heading" class="sr-only">{{ __('production_bench.inventory.lot_register') }}</h2>
                        <p class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.mass_shown', ['unit' => $displayUnit]) }}</p>
                    </div>
                    @if ($canWriteInventory)
                        {{ $this->addStockAction }}
                    @endif
                </div>
                {{-- Same stacking context the material filters need: Filament renders dropdown
                     panels as absolutely positioned `z-20` siblings and does not teleport them, so
                     they would otherwise lose to the sticky `z-20` thead further down the DOM. --}}
                <div
                    data-production-bench-filters
                    x-data="{ filtersOpen: @js($lotFiltersActive) }"
                    class="relative z-30 border-b border-[var(--color-line)] p-4"
                >
                    {{ $this->lotFiltersForm }}
                    <p id="lot-register-search-help" class="mt-2 px-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.lot_register_search_help') }}</p>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="sk-btn sk-btn-ghost"
                            aria-controls="lot-advanced-filters"
                            x-bind:aria-expanded="filtersOpen.toString()"
                            x-on:click="filtersOpen = ! filtersOpen"
                        >
                            {{ __('production_bench.common.filters') }}
                        </button>

                        {{-- Outside the disclosure on purpose: it used to appear only with a
                             material selected and cleared only that, so filtering by supplier
                             and date left nothing to undo. --}}
                        @if ($lotFiltersActive)
                            <button type="button" wire:click="clearLotFilters" class="sk-btn sk-btn-ghost">{{ __('production_bench.inventory.clear_filters') }}</button>
                        @endif

                        {{-- Dismissible like the chips on the materials tab: it sits beside a
                             "Clear filters" button that resets everything, so leaving this one
                             undismissable would make it the only filter you cannot undo on
                             its own. --}}
                        @if ($lotMaterialLabel)
                            <button type="button" wire:click="clearLotMaterial" class="sk-badge sk-badge-neutral">{{ __('production_bench.inventory.lot_material') }}: {{ $lotMaterialLabel }} ×</button>
                        @endif
                    </div>

                    <div id="lot-advanced-filters" class="mt-3" x-cloak x-show="filtersOpen">
                        {{ $this->lotAdvancedFiltersForm }}
                    </div>
                </div>
                {{-- Nine columns and up to five stacked lines per row, and the same trade as
                     the materials tab: no height cap, so the page scrolls, the thead sticks to
                     the viewport and "Rows per page" decides the length. The wrapper only
                     scrolls horizontally while the card is narrower than the table, because a
                     scroll container of any kind becomes what `sticky top-0` resolves against.
                     Floor is measured: 952px before anything wraps, so 992px with air. --}}
                <div class="overflow-x-auto @min-[62rem]:overflow-x-visible">
                    <table class="w-full min-w-[992px] text-left text-sm">
                        <thead class="sticky top-0 z-20 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)] shadow-[0_1px_0_0_var(--color-line)]">
                            <tr>
                                <th class="sticky left-0 z-30 border-r border-[var(--color-line)] bg-[var(--color-panel-muted)] px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
                                <th class="px-4 py-3">{{ __('production_bench.inventory.lot_supplier') }}</th>
                                <th class="px-4 py-3">{{ __('production_bench.common.status') }}</th>
                                <th class="px-4 py-3">{{ __('production_bench.inventory.stocked_on') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.physical') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.quarantined') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                                <th class="px-5 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @forelse ($lots as $row)
                                @php($lot = $row['lot'])
                                @php($supplier = $lot->goodsReceiptLine?->goodsReceipt?->supplier ?? $lot->supplierListing?->supplier)
                                @php($originReceipt = $lot->goodsReceiptLine?->goodsReceipt)
                                @php($materialCode = $lot->packagingItem?->material_code ?? $lot->ingredient?->workspaceCodes?->firstWhere('workspace_id', $workspace->id)?->material_code)
                                <tr id="lot-{{ $lot->public_id }}" wire:key="stock-lot-{{ $lot->id }}">
                                    {{-- Opaque fill: the cell sits over the quantity columns as they
                                         scroll beneath it. Lot rows carry no tint of their own, so
                                         the plain panel colour is enough here. --}}
                                    <td class="sticky left-0 z-10 border-r border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-3">
                                        {{-- One block link over the whole identity cell, like the materials
                                             tab: a row cannot be wrapped in an anchor, so the cell is the
                                             largest target available and the name, the codes, the batch
                                             and the expiry all lead to the material instead of only the
                                             name and the arrow.

                                             Deliberately the cell and not the whole row. The row already
                                             carries two other destinations — the receipt/supplier link and
                                             the quarantine/release action in the last column — and a
                                             row-wide target would have to swallow both. The quantity
                                             columns stay plain text so numbers remain selectable. --}}
                                        @if ($row['detail_url'])
                                            <a href="{{ $row['detail_url'] }}" wire:navigate class="group -m-2 flex min-h-11 items-start justify-between gap-3 rounded-lg p-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
                                        @else
                                            <div>
                                        @endif
                                            <span class="min-w-0">
                                                <span class="block font-medium text-[var(--color-ink-strong)] group-hover:text-[var(--color-accent-strong)]">{{ $lot->subjectName() }}</span>
                                                @if ($materialCode)<span class="mt-0.5 block font-mono text-xs text-[var(--color-ink-soft)]">{{ $materialCode }}</span>@endif
                                                <span class="mt-0.5 block font-mono text-xs text-[var(--color-ink-soft)]">{{ $lot->internal_lot_code }}@if($lot->supplier_batch_number) · {{ $lot->supplier_batch_number }}@endif</span>
                                                @if($lot->expires_at)<span class="mt-1 block text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.expires_on') }}: {{ $lot->expires_at->format('Y-m-d') }}</span>@endif
                                            </span>
                                        @if ($row['detail_url'])
                                            <span class="mt-0.5 shrink-0 text-[var(--color-ink-soft)] group-hover:text-[var(--color-accent-strong)]" aria-hidden="true">&rarr;</span>
                                            <span class="sr-only">{{ __('production_bench.inventory.open_material_detail') }}</span>
                                        </a>
                                        @else
                                            </div>
                                        @endif
                                        {{-- Outside the block link: it goes to the receipt, not the
                                             material, and an anchor cannot nest inside an anchor. --}}
                                        @if ($originReceipt)
                                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">
                                                <a href="{{ route('production-bench.purchasing.receipts.show', $originReceipt) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.inventory.receipt_origin') }}</a>
                                                · {{ $originReceipt->source->value === 'direct' ? __('production_bench.receipt.direct_source') : __('production_bench.receipt.order_source') }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-sm text-[var(--color-ink-soft)]">
                                        @if ($originReceipt && $supplier)
                                            <a href="{{ route('production-bench.purchasing.receipts.show', $originReceipt) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $supplier->name }}</a>
                                        @else
                                            {{ $supplier?->name ?? __('production_bench.inventory.supplier_unknown') }}
                                        @endif
                                    </td>
                                    {{-- The pill restated what the row action in the last column
                                         already offers: a released lot says "Quarantine", a
                                         quarantined one says "Release". A dot carries the same
                                         state in a tenth of the width and hands the space to the
                                         stocked-on date, which was breaking over two lines.
                                         Colour alone is not a cue (WCAG 1.4.1), so the state is
                                         still spelled out — for a screen reader here, and for
                                         anyone hovering the dot. --}}
                                    <td class="px-4 py-3">
                                        @php($isReleased = $lot->status->value === 'released')
                                        @php($lotStatusLabel = $isReleased ? __('production_bench.inventory.released') : __('production_bench.inventory.quarantined'))
                                        <span class="inline-flex items-center" title="{{ $lotStatusLabel }}">
                                            {{-- The base tones, not `-strong`: at this size the strong
                                                 pair collapsed into two dark spots (#00422e vs
                                                 #8a3f04, both above 7:1 on the panel). Green at
                                                 #257055 and amber at #b45307 are 120° apart in hue
                                                 and both near 5:1, so they stay distinct at a glance
                                                 while clearing the 3:1 non-text minimum. --}}
                                            <span class="size-2.5 rounded-full {{ $isReleased ? 'bg-[var(--color-success)]' : 'bg-[var(--color-warning)]' }}" aria-hidden="true"></span>
                                            <span class="sr-only">{{ $lotStatusLabel }}</span>
                                        </span>
                                    </td>
                                    <td class="numeric whitespace-nowrap px-4 py-3 text-[var(--color-ink-soft)]">{{ $lot->stocked_at->format('Y-m-d') }}</td>
                                    @foreach (['physical', 'quarantined', 'reserved', 'available'] as $position)
                                        <td class="numeric px-4 py-3 text-right">{{ $row['positions'][$position] }}</td>
                                    @endforeach
                                    <td class="px-5 py-3 text-right">
                                        @if ($canWriteInventory)
                                            <button wire:click="{{ $lot->status->value === 'released' ? 'quarantine' : 'release' }}({{ $lot->id }})" wire:loading.attr="disabled" type="button" class="inline-flex min-h-9 items-center px-2 text-xs font-medium text-[var(--color-accent-strong)] hover:underline">{{ $lot->status->value === 'released' ? __('production_bench.inventory.quarantine') : __('production_bench.inventory.release') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                {{-- `no_open_lots` reads "for this material" and is shared with the
                                     material detail screen, where that wording is correct. Here it
                                     was shown for any empty open scope, including with no material
                                     chosen at all. Naming the filters covers both cases, since the
                                     material selection is itself a filter. --}}
                                <tr><td colspan="9" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ $lotFiltersActive ? __('production_bench.inventory.no_lots_match') : __('production_bench.inventory.no_lots') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-table-pagination :paginator="$lots" :per-page-label="__('production_bench.inventory.lot_register')" />
            </section>
        @endif

        <x-filament-actions::modals />
    @endif
</x-production-bench.page>
