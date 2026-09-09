<x-production-bench.page active="inventory" subnavigation="materials">
    @if (! $isActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('production-bench.inventory') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">← {{ __('production_bench.inventory.stock_by_material') }}</a>
                <h1 class="mt-3 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $materialName }}</h1>
                @if ($materialCode)<p class="mt-1 font-mono text-sm text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
            </div>
        </header>

        <section data-material-stock-summary class="sk-card overflow-hidden" aria-labelledby="current-position-heading">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <h2 id="current-position-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.current_position') }}</h2>
                <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.current_position_help', ['unit' => $displayUnit]) }}</p>
            </div>

            <dl class="grid divide-y divide-[var(--color-line)] sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                <div data-position-primary="available" class="px-5 py-5 sm:px-6 sm:py-6">
                    <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.available') }}</dt>
                    <dd class="numeric mt-1 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $position['available'] }}</dd>
                </div>
                <div data-position-primary="forecast" class="bg-[var(--color-panel-muted)] px-5 py-5 sm:px-6 sm:py-6">
                    <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.forecast') }}</dt>
                    <dd class="numeric mt-1 text-2xl font-semibold {{ str_starts_with($position['forecast'], '-') ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $position['forecast'] }}</dd>
                    <div data-material-forecast-equation class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--color-ink-soft)]">
                        <span>{{ __('production_bench.inventory.available') }} <span class="numeric font-medium text-[var(--color-ink-strong)]">{{ $position['available'] }}</span></span>
                        <span>+</span>
                        <span>{{ __('production_bench.inventory.incoming') }} <span class="numeric font-medium text-[var(--color-ink-strong)]">{{ $position['incoming'] }}</span></span>
                        <span>-</span>
                        <span>{{ __('production_bench.inventory.required') }} <span class="numeric font-medium text-[var(--color-ink-strong)]">{{ $position['required'] }}</span></span>
                        <span>=</span>
                        <span>{{ __('production_bench.inventory.forecast') }} <span class="numeric font-medium text-[var(--color-ink-strong)]">{{ $position['forecast'] }}</span></span>
                    </div>
                </div>
            </dl>

            <div data-material-position-breakdown class="border-t border-[var(--color-line)]">
                <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] sm:grid-cols-3 lg:grid-cols-5 lg:divide-y-0">
                    @foreach (['physical', 'reserved', 'quarantined', 'incoming', 'required'] as $key)
                        <div data-position-secondary="{{ $key }}" class="px-5 py-4">
                            <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.'.$key) }}</dt>
                            <dd class="numeric mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ $position[$key] }}</dd>
                            @if ($key === 'incoming')
                                <dd class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.incoming_help') }}</dd>
                            @endif
                        </div>
                    @endforeach
                </dl>
            </div>

            <div data-material-buffer class="border-t border-[var(--color-line)] p-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <h2 id="buffer-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.buffer_stock') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.buffer_stock_help') }}</p>
                        @if ($buffer !== null)
                            <p class="mt-2 text-sm {{ $bufferBelow ? 'text-[var(--color-warning-strong)]' : 'text-[var(--color-ink-soft)]' }}">
                                {{ $bufferBelow ? __('production_bench.inventory.below_buffer_detail') : __('production_bench.inventory.above_buffer_detail') }}
                                <span class="numeric font-semibold">{{ $buffer }} {{ $displayUnit }}</span>
                            </p>
                        @else
                            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.buffer_none') }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        {{ $this->editBufferAction }}
                        {{ $this->clearBufferAction }}
                    </div>
                </div>
            </div>

        </section>

        {{-- `overflow-clip`, not the `overflow-hidden` the neighbouring cards use: hidden makes the
             card a scroll container, and `sticky top-0` then resolves against the card rather than
             the viewport, so the header would sit still while the page scrolled past it. Clip keeps
             the rounded corners without creating a scrollport. `@container` lets the wrapper below
             drop `overflow-x` once the card is wide enough for the table's floor. --}}
        <section data-material-open-lots class="@container overflow-clip sk-card" aria-labelledby="open-lots-heading">
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="open-lots-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.open_lots') }}</h2>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.open_lots_help') }}</p>
                </div>
                <a data-material-view-all-lots href="{{ $lotRegisterUrl }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.inventory.view_all_lots') }} →</a>
            </div>
            <x-sticky-table-scroll>
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead wire:ignore.self data-sticky-table-header class="relative z-20 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)] shadow-[0_1px_0_0_var(--color-line)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.inventory.lot_supplier') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.common.status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.physical') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.stocked_on') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($openLots as $row)
                            @php($lot = $row['lot'])
                            @php($supplier = $lot->goodsReceiptLine?->goodsReceipt?->supplier ?? $lot->supplierListing?->supplier)
                            <tr wire:key="material-open-lot-{{ $lot->id }}">
                                <td class="px-5 py-3">
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $lot->subjectName() }}</p>
                                    <p class="mt-0.5 font-mono text-sm text-[var(--color-ink-strong)]">{{ $lot->internal_lot_code }}</p>
                                    @if ($lot->supplier_batch_number)<p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.supplier_batch') }}: {{ $lot->supplier_batch_number }}</p>@endif
                                    @if ($lot->expires_at)<p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.expires_on') }}: {{ $lot->expires_at->format('Y-m-d') }}</p>@endif
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $supplier?->name ?? __('production_bench.inventory.supplier_unknown') }}</td>
                                <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $lot->status->value === 'released' ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' }}">{{ $lot->status->value === 'released' ? __('production_bench.inventory.released') : __('production_bench.inventory.quarantined') }}</span></td>
                                <td class="numeric px-4 py-3 text-right">{{ $row['positions']['physical'] }}</td>
                                <td class="numeric px-4 py-3 text-right">{{ $row['positions']['reserved'] }}</td>
                                <td class="numeric px-4 py-3 text-right">{{ $row['positions']['available'] }}</td>
                                <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">{{ $lot->stocked_at->format('Y-m-d') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_open_lots') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-sticky-table-scroll>
        </section>

        @php($supplierListingsOpen = $supplierListings->currentPage() > 1)
        <details
            data-material-supplier-listings
            wire:key="material-supplier-listings-disclosure"
            wire:ignore.self
            x-data="{ open: $el.open, serverOpen: {{ $supplierListingsOpen ? 'true' : 'false' }} }"
            x-effect="serverOpen = ($wire.paginators['supplier-listings'] ?? 1) > 1; if (serverOpen) open = true"
            x-bind:open="open"
            x-on:toggle="open = $el.open"
            class="@container overflow-clip sk-card"
            aria-labelledby="supplier-listings-heading"
            @if ($supplierListingsOpen) open @endif
        >
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 border-b border-[var(--color-line)] px-5 py-4 [&::-webkit-details-marker]:hidden" x-bind:aria-expanded="open.toString()">
                <span class="flex min-w-0 flex-col gap-1">
                    <span id="supplier-listings-heading" role="heading" aria-level="2" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.related_supplier_listings') }}</span>
                    <span class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.related_supplier_listings_help') }}</span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <span class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $supplierListings->total() }}</span>
                    <span aria-hidden="true" class="text-lg leading-none text-[var(--color-ink-soft)]">⌄</span>
                </span>
            </summary>
            <x-sticky-table-scroll>
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead wire:ignore.self data-sticky-table-header class="relative z-20 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)] shadow-[0_1px_0_0_var(--color-line)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.supplier.singular') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.listing.supplier_item_name') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.listing.purchase_format') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.listing.net_quantity') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.listing.latest_price') }}</th>
                            <th class="px-5 py-3">{{ __('production_bench.common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($supplierListings as $row)
                            @php($listing = $row['listing'])
                            <tr wire:key="material-supplier-listing-{{ $listing->id }}">
                                <td class="px-5 py-4">
                                    <a href="{{ route('production-bench.purchasing.supplier', $listing->supplier) }}" wire:navigate class="font-medium text-[var(--color-ink-strong)] hover:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $listing->supplier->name }}</a>
                                </td>
                                <td class="px-4 py-4">
                                    <p class="text-[var(--color-ink-strong)]">{{ $listing->supplier_item_name }}</p>
                                    @if ($listing->supplier_sku)<p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ $listing->supplier_sku }}</p>@endif
                                </td>
                                <td class="px-4 py-4 text-[var(--color-ink-soft)]">{{ $listing->purchase_format }}</td>
                                <td class="numeric px-4 py-4 text-right">{{ rtrim(rtrim((string) $listing->net_quantity, '0'), '.') }} {{ $listing->net_unit }}</td>
                                <td class="px-4 py-4 text-right">
                                    <p class="text-xs font-medium text-[var(--color-ink-soft)]">{{ $row['price']['basis_label'] }}</p>
                                    <p class="numeric mt-1">{{ $row['price']['entered_price'] }}</p>
                                    <p class="numeric text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.listing.derived', ['price' => $row['price']['derived_price']]) }}</p>
                                </td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $listing->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $listing->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_supplier_listings') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-sticky-table-scroll>
            <x-table-pagination
                :paginator="$supplierListings"
                {{-- The section heading already says "Supplier listings" directly above; this one
                     says what the select does, and tells the two paginators on this page apart. --}}
                :per-page-label="__('production_bench.listing.per_page')"
                per-page-model="supplierListingsPerPage"
                :per-page-options="[10, 25, 50]"
            />
        </details>

        @php($activityDisclosureOpen = $periodPreset !== '30' || filled($customFrom) || filled($customTo) || $movements->currentPage() > 1)
        <details
            data-material-activity
            wire:key="material-activity-disclosure"
            wire:ignore.self
            x-data="{ open: $el.open, serverOpen: {{ $activityDisclosureOpen ? 'true' : 'false' }} }"
            x-effect="serverOpen = $wire.periodPreset !== '30' || ($wire.customFrom ?? '') !== '' || ($wire.customTo ?? '') !== '' || ($wire.paginators['activity'] ?? 1) > 1; if (serverOpen) open = true"
            x-bind:open="open"
            x-on:toggle="open = $el.open"
            class="@container overflow-clip sk-card"
            aria-labelledby="activity-heading"
            @if ($activityDisclosureOpen) open @endif
        >
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 border-b border-[var(--color-line)] px-5 py-4 [&::-webkit-details-marker]:hidden" x-bind:aria-expanded="open.toString()">
                <span class="flex min-w-0 flex-col gap-1">
                    <span id="activity-heading" role="heading" aria-level="2" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.period_activity') }}</span>
                    <span class="text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.period_activity_help') }}</span>
                </span>
                <span class="flex shrink-0 flex-col items-end gap-0.5 text-right">
                    <span class="text-xs text-[var(--color-ink-soft)]">{{ $periodLabel }}</span>
                    <span class="flex items-baseline gap-1.5">
                        <span class="text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.net_change') }}</span>
                        <span class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $activity['net_change'] }} {{ $displayUnit }}</span>
                    </span>
                </span>
                <span data-material-activity-chevron aria-hidden="true" class="mt-1 shrink-0 text-lg leading-none text-[var(--color-ink-soft)] transition-transform duration-150 motion-reduce:transition-none" x-bind:class="{ 'rotate-180': open }">⌄</span>
            </summary>
            {{-- Filament renders its dropdown panel as `position: absolute; z-index: 20` and does not
                 teleport it, so it competes with the sticky `z-20` thead further down the DOM — and
                 loses, because that comes later. Its own stacking context above the header lifts
                 every dropdown inside it, the same fix the index filter panel carries. --}}
            <div class="relative z-30 border-b border-[var(--color-line)] p-4">
                {{ $this->activityFiltersForm }}
                <p class="mt-3 text-xs text-[var(--color-ink-soft)]">{{ $periodLabel }}</p>
            </div>
            <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] sm:grid-cols-4 lg:grid-cols-8 lg:divide-y-0">
                @foreach (['opening_physical', 'received', 'production_consumed', 'other_inbound', 'other_outbound', 'adjustments', 'net_change', 'closing_physical'] as $key)
                    <div class="px-5 py-4">
                        <dt data-activity-metric-label class="line-clamp-2 min-h-8 text-xs font-medium leading-4 text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.'.$key) }}</dt>
                        <dd class="numeric mt-1 text-base font-semibold text-[var(--color-ink-strong)]">{{ $activity[$key] }}</dd>
                    </div>
                @endforeach
            </dl>
            <div class="border-t border-[var(--color-line)] bg-[var(--color-panel-muted)] px-5 py-4 text-sm">
                <p class="font-medium text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.reconciliation') }}</p>
                <p class="numeric mt-2 text-[var(--color-ink-soft)]">{{ $activity['opening_physical'] }} + {{ $activity['received'] }} + {{ $activity['other_inbound'] }} − {{ $activity['production_consumed'] }} − {{ $activity['other_outbound'] }} + {{ $activity['adjustments'] }} = {{ $activity['closing_physical'] }}</p>
                <p class="mt-2 text-xs {{ $activity['reconciliation_ok'] ? 'text-[var(--color-success-strong)]' : 'text-[var(--color-danger-strong)]' }}">{{ __('production_bench.inventory.reconciliation_delta', ['delta' => $activity['reconciliation_delta']]) }}</p>
            </div>
            <x-sticky-table-scroll>
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead wire:ignore.self data-sticky-table-header class="relative z-20 bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)] shadow-[0_1px_0_0_var(--color-line)]">
                        <tr>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.date') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.inventory.activity_group') }}</th>
                            <th class="px-4 py-3">{{ __('production_bench.inventory.activity_type') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.quantity_delta') }}</th>
                            <th class="px-5 py-3">{{ __('production_bench.inventory.source') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($movements as $entry)
                            @php($movement = $entry['movement'])
                            @php($sourceUrl = $this->sourceUrl($movement))
                            @php($sourceLabel = $this->sourceLabel($movement))
                            <tr wire:key="material-activity-{{ $movement->id }}">
                                <td class="numeric px-5 py-3 text-[var(--color-ink-soft)]">{{ $movement->occurred_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $this->groupLabel($entry['group']) }}</td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)]">{{ $this->movementTypeLabel($movement->type) }}</td>
                                <td class="numeric px-4 py-3 text-right {{ str_starts_with($entry['quantity_delta'], '-') ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $entry['quantity_delta'] }}</td>
                                <td class="px-5 py-3">@if ($sourceUrl)<a href="{{ $sourceUrl }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ $sourceLabel }}</a>@else<span class="text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.source_not_available') }}</span>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_material_activity') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-sticky-table-scroll>
            <x-table-pagination :paginator="$movements" :per-page-label="__('production_bench.inventory.movements_per_page')" />
        </details>
    @endif

    <x-filament-actions::modals />
</x-production-bench.page>
