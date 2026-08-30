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
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $mode === 'stock' ? __('production_bench.inventory.stock_title') : ($mode === 'requirements' ? __('production_bench.inventory.requirements_title') : __('production_bench.navigation.inventory')) }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ $mode === 'stock' ? __('production_bench.inventory.stock_help') : ($mode === 'requirements' ? __('production_bench.inventory.requirements_help') : __('production_bench.inventory.help')) }}</p>
        </header>

        @if ($mode === 'overview')
            <section data-inventory-overview aria-labelledby="inventory-overview-heading" class="overflow-hidden sk-card">
                <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h2 id="inventory-overview-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.requirements_title') }}</h2>
                    <a href="{{ route('production-bench.inventory.requirements') }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.inventory.requirements') }}</a>
                </div>

                <dl class="grid grid-cols-2 divide-x divide-y divide-[var(--color-line)] border-b border-[var(--color-line)] sm:grid-cols-4 sm:divide-y-0">
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.stock') }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ $inventorySummary['lots'] }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.quarantined') }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ $inventorySummary['quarantined'] }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.production.shortage') }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold {{ $inventorySummary['shortages'] > 0 ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $inventorySummary['shortages'] }}</dd>
                    </div>
                    <div class="px-5 py-3">
                        <dt class="text-xs font-medium text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.incoming') }}</dt>
                        <dd class="numeric mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">{{ $inventorySummary['incoming'] }}</dd>
                    </div>
                </dl>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.required') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.incoming') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('production_bench.inventory.forecast') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @forelse ($overviewShortages as $row)
                                @php($subject = $row['subject'])
                                @php($materialCode = $subject instanceof \App\Models\PackagingItem ? $subject->material_code : null)
                                <tr>
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $subject instanceof \App\Models\Ingredient ? $subject->localizedDisplayName() : $subject->name }}</p>
                                        @if ($materialCode)<p class="mt-0.5 font-mono text-xs text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
                                        <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ $row['display_unit'] }}</p>
                                    </td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['required'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['available'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['incoming'] }}</td>
                                    <td class="numeric px-5 py-3 text-right font-semibold text-[var(--color-danger-strong)]">{{ $row['positions']['forecast'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_forecast') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @elseif ($mode === 'requirements')
            <section aria-labelledby="production-requirements-heading" class="overflow-hidden sk-card">
                <div class="flex items-center justify-between gap-4 border-b border-[var(--color-line)] px-5 py-4">
                    <h2 id="production-requirements-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.requirements_title') }}</h2>
                    <p class="text-xs text-[var(--color-ink-muted)]">{{ __('production_bench.inventory.mass_shown', ['unit' => $displayUnit]) }}</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.required') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.reserved') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                                <th class="px-4 py-3 text-right">{{ __('production_bench.inventory.incoming') }}</th>
                                <th class="px-5 py-3 text-right">{{ __('production_bench.inventory.forecast') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--color-line)]">
                            @forelse ($forecast as $row)
                                @php($subject = $row['subject'])
                                @php($materialCode = $subject instanceof \App\Models\PackagingItem ? $subject->material_code : null)
                                <tr class="{{ $row['is_shortage'] ? 'bg-[var(--color-danger-soft)]/40' : '' }}">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $subject instanceof \App\Models\Ingredient ? $subject->localizedDisplayName() : $subject->name }}</p>
                                        @if ($materialCode)<p class="mt-0.5 font-mono text-xs text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
                                        <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ $row['display_unit'] }}</p>
                                    </td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['required'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['reserved'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['available'] }}</td>
                                    <td class="numeric px-4 py-3 text-right">{{ $row['positions']['incoming'] }}</td>
                                    <td class="numeric px-5 py-3 text-right font-semibold {{ $row['is_shortage'] ? 'text-[var(--color-danger-strong)]' : 'text-[var(--color-ink-strong)]' }}">{{ $row['positions']['forecast'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_forecast') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <section data-stock-register aria-labelledby="inventory-positions-heading" class="overflow-hidden sk-card">
                <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-baseline gap-3">
                        <h2 id="inventory-positions-heading" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.inventory.stock_positions') }}</h2>
                        <p class="text-xs text-[var(--color-ink-muted)]">{{ __('production_bench.inventory.mass_shown', ['unit' => $displayUnit]) }}</p>
                    </div>
                    @if (! $isReadOnly)
                        {{ $this->addStockAction }}
                    @endif
                </div>
                <div data-production-bench-filters class="border-b border-[var(--color-line)] p-4">
                    {{ $this->filtersForm }}
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[880px] text-left text-sm">
                        <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                            <tr>
                                <th class="px-5 py-3">{{ __('production_bench.inventory.item_lot') }}</th>
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
                                @php($materialCode = $lot->packagingItem?->material_code)
                                <tr id="lot-{{ $lot->public_id }}" wire:key="stock-lot-{{ $lot->id }}">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-[var(--color-ink-strong)]">{{ $lot->subjectName() }}</p>
                                        @if ($materialCode)<p class="mt-0.5 font-mono text-xs text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif
                                        <p class="mt-0.5 font-mono text-xs text-[var(--color-ink-soft)]">{{ $lot->internal_lot_code }} @if($lot->supplier_batch_number) · {{ $lot->supplier_batch_number }} @endif</p>
                                        @if($lot->goodsReceiptLine?->goodsReceipt)
                                            @php($originReceipt = $lot->goodsReceiptLine->goodsReceipt)
                                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">
                                                <a href="{{ route('production-bench.purchasing.receipts.show', $originReceipt) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.inventory.receipt_origin') }}</a>
                                                · {{ $originReceipt->source->value === 'direct' ? __('production_bench.receipt.direct_source') : __('production_bench.receipt.order_source') }}
                                                · {{ $originReceipt->supplier->name }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $lot->status->value === 'released' ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]' }}">{{ $lot->status->value === 'released' ? __('production_bench.inventory.released') : __('production_bench.inventory.quarantined') }}</span></td>
                                    <td class="numeric px-4 py-3 text-[var(--color-ink-soft)]">{{ $lot->stocked_at->format('Y-m-d') }}</td>
                                    @foreach (['physical', 'quarantined', 'reserved', 'available'] as $position)
                                        <td class="numeric px-4 py-3 text-right">{{ $row['positions'][$position] }}</td>
                                    @endforeach
                                    <td class="px-5 py-3 text-right">
                                        @if (! $isReadOnly)
                                            <button wire:click="{{ $lot->status->value === 'released' ? 'quarantine' : 'release' }}({{ $lot->id }})" wire:loading.attr="disabled" type="button" class="inline-flex min-h-9 items-center px-2 text-xs font-medium text-[var(--color-accent-strong)] hover:underline">{{ $lot->status->value === 'released' ? __('production_bench.inventory.quarantine') : __('production_bench.inventory.release') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.inventory.no_lots') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <x-table-pagination :paginator="$lots" :per-page-label="__('production_bench.inventory.stock_positions')" />
            </section>
        @endif

        <x-filament-actions::modals />
    @endif
</x-production-bench.page>
