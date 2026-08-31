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
            <section data-inventory-materials aria-labelledby="inventory-materials-heading" class="overflow-hidden sk-card">
                <div class="flex flex-col gap-1 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-baseline sm:justify-between">
                    <h2 id="inventory-materials-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.stock_by_material') }}</h2>
                    <p class="text-xs text-[var(--color-ink-muted)]">
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
                                aria-label="{{ __('production_bench.inventory.filter_negative_forecast') }}"
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
                        <dd class="numeric mt-1 text-lg font-semibold {{ $inventorySummary['below_buffer'] > 0 ? 'text-[var(--color-warning-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $inventorySummary['below_buffer'] }}</dd>
                    </div>
                </dl>

                <div
                    data-production-bench-filters
                    x-data="{ filtersOpen: @js($materialFiltersActive) }"
                    class="border-b border-[var(--color-line)] p-4"
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
                        <div class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('production_bench.inventory.filters') }}">
                            @if ($materialType !== 'all')<button type="button" wire:click="$set('materialType', 'all')" class="sk-badge sk-badge-neutral">{{ $materialType === 'ingredient' ? __('production_bench.inventory.filter_ingredients') : __('production_bench.inventory.filter_packaging') }} ×</button>@endif
                            @if ($stockState !== 'all')<button type="button" wire:click="$set('stockState', 'all')" class="sk-badge sk-badge-neutral">{{ __('production_bench.inventory.filter_'.($stockState === 'negative_forecast' ? 'negative_forecast' : $stockState)) }} ×</button>@endif
                            @if ($demandFilter !== 'all')<button type="button" wire:click="$set('demandFilter', 'all')" class="sk-badge sk-badge-neutral">{{ $demandFilter === 'planned' ? __('production_bench.inventory.filter_with_demand') : __('production_bench.inventory.filter_without_demand') }} ×</button>@endif
                            @if ($categoryFilter !== '')<button type="button" wire:click="$set('categoryFilter', '')" class="sk-badge sk-badge-neutral">{{ $categoryOptions[$categoryFilter] ?? $categoryFilter }} ×</button>@endif
                            @if ($subcategoryFilter !== '')<button type="button" wire:click="$set('subcategoryFilter', '')" class="sk-badge sk-badge-neutral">{{ $subcategoryOptions[$subcategoryFilter] ?? $subcategoryFilter }} ×</button>@endif
                        </div>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1120px] text-left text-sm">
                        <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('production_bench.inventory.material') }}</th>
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
                                     Either way the tint only reinforces a text badge. --}}
                                <tr
                                    wire:key="inventory-material-{{ $row['key'] }}"
                                    @class([
                                        'bg-[var(--color-danger-soft)]/40' => $row['is_shortage'],
                                        'bg-[var(--color-warning-soft)]/40' => $row['is_below_buffer'] && ! $row['is_shortage'],
                                    ])
                                >
                                    <td class="px-5 py-3">
                                        {{-- A table row cannot be wrapped in an anchor, so the whole identity cell
                                             is one block link instead of just the name. --}}
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
                                    <td class="numeric px-5 py-3 text-right font-semibold {{ $row['is_shortage'] ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">
                                        <div class="flex flex-col items-end gap-1">
                                            <span>{{ $row['positions']['forecast'] }}</span>
                                            @if ($row['is_shortage'])
                                                <span data-negative-forecast-badge class="inline-flex rounded-full bg-[var(--color-danger-soft)] px-2 py-0.5 text-xs font-medium text-[var(--color-danger-strong)]">
                                                    {{ __('production_bench.inventory.filter_negative_forecast') }}
                                                </span>
                                            @endif
                                        </div>
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
            <section data-stock-register aria-labelledby="inventory-positions-heading" class="overflow-hidden sk-card">
                <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-baseline gap-3">
                        <h2 id="inventory-positions-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.lot_register') }}</h2>
                        <p class="text-xs text-[var(--color-ink-muted)]">{{ __('production_bench.inventory.mass_shown', ['unit' => $displayUnit]) }}</p>
                    </div>
                    @if ($canWriteInventory)
                        {{ $this->addStockAction }}
                    @endif
                </div>
                <div data-production-bench-filters class="border-b border-[var(--color-line)] p-4">
                    {{ $this->lotFiltersForm }}
                    <p id="lot-register-search-help" class="mt-2 px-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.lot_register_search_help') }}</p>
                    @if ($lotMaterialLabel)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="sk-badge sk-badge-neutral">{{ __('production_bench.inventory.lot_material') }}: {{ $lotMaterialLabel }}</span>
                            <button type="button" wire:click="clearLotMaterial" class="sk-btn sk-btn-ghost text-xs">{{ __('production_bench.inventory.clear_filters') }}</button>
                        </div>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1120px] text-left text-sm">
                        <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
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
                                    <td class="px-5 py-3">
                                        @if ($row['detail_url'])
                                            <a href="{{ $row['detail_url'] }}" wire:navigate class="group -m-1 inline-flex min-h-9 items-center gap-1.5 rounded p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
                                                <span class="font-medium text-[var(--color-ink-strong)] group-hover:text-[var(--color-accent-strong)]">{{ $lot->subjectName() }}</span>
                                                <span class="text-[var(--color-ink-soft)] group-hover:text-[var(--color-accent-strong)]" aria-hidden="true">&rarr;</span>
                                                <span class="sr-only">{{ __('production_bench.inventory.open_material_detail') }}</span>
                                            </a>
                                        @else
                                            <p class="font-medium text-[var(--color-ink-strong)]">{{ $lot->subjectName() }}</p>
                                        @endif
                                        @if ($materialCode)<p class="mt-0.5 font-mono text-xs text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
                                        <p class="mt-0.5 font-mono text-xs text-[var(--color-ink-soft)]">{{ $lot->internal_lot_code }} @if($lot->supplier_batch_number) · {{ $lot->supplier_batch_number }} @endif</p>
                                        @if($lot->expires_at)<p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.expires_on') }}: {{ $lot->expires_at->format('Y-m-d') }}</p>@endif
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
                                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $lot->status->value === 'released' ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' }}">{{ $lot->status->value === 'released' ? __('production_bench.inventory.released') : __('production_bench.inventory.quarantined') }}</span></td>
                                    <td class="numeric px-4 py-3 text-[var(--color-ink-soft)]">{{ $lot->stocked_at->format('Y-m-d') }}</td>
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
                                <tr><td colspan="9" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ $lotScope === 'open' ? __('production_bench.inventory.no_open_lots') : __('production_bench.inventory.no_lots') }}</td></tr>
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
