@if ($filteredSizeLabel !== null)
    <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-[var(--color-accent-soft)] px-3 py-1.5 text-sm font-medium text-[var(--color-accent-strong)]">{{ $filteredSizeLabel }}</span>
        <button type="button" wire:click="clearProductSizeFilter" class="sk-btn sk-btn-ghost text-xs">{{ __('production_bench.production.clear_size_filter') }}</button>
    </div>
@endif

@if ($historyTotals->total())
    <section aria-labelledby="production-totals-heading" class="sk-card overflow-hidden">
        <h2 id="production-totals-heading" class="border-b border-[var(--color-line)] px-5 py-4 text-lg font-semibold">{{ __('production_bench.production.production_totals') }}</h2>
        <x-sticky-table-scroll>
            <table class="w-full min-w-[360px] text-left text-sm">
                <thead wire:ignore.self data-sticky-table-header class="relative z-20 whitespace-nowrap bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                    <tr>
                        <th scope="col" class="px-5 py-3">{{ __('production_bench.production.product') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('batch_log.fields.product_size') }}</th>
                        <th scope="col" class="px-5 py-3 text-right">{{ __('batch_log.fields.units_produced') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    @foreach ($historyTotals as $total)
                        <tr>
                            <th scope="row" class="px-5 py-3 font-medium">{{ $total->product_name ?? __('production_bench.production.unknown_product') }}</th>
                            <td class="px-4 py-3">{{ $total->size_label ?? __('production_bench.inventory.size_not_recorded') }}</td>
                            <td class="numeric px-5 py-3 text-right">{{ \App\Support\NumberLocale::formatDecimal($total->units, 0, $numberLocale) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-sticky-table-scroll>
        @if ($historyTotals->hasPages())
            <x-table-pagination :paginator="$historyTotals" :show-per-page="false" />
        @endif
    </section>
@endif

<section aria-labelledby="completed-runs-heading" class="sk-card overflow-hidden">
    <h2 id="completed-runs-heading" class="border-b border-[var(--color-line)] px-5 py-4 text-lg font-semibold">{{ __('production_bench.production.completed_runs') }}</h2>
    @if ($productions->isEmpty())
        <p class="px-5 py-8 text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.production.no_productions') }}</p>
    @else
        <x-sticky-table-scroll>
            <table class="w-full min-w-[560px] text-left text-sm">
                <thead wire:ignore.self data-sticky-table-header class="relative z-20 whitespace-nowrap bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                    <tr>
                        <th scope="col" class="px-5 py-3">{{ __('production_bench.production.manufacture_date') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('production_bench.production.product') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('production_bench.production.batch_number') }}</th>
                        <th scope="col" class="px-5 py-3 text-right">{{ __('batch_log.fields.units_produced') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    @foreach ($productions as $production)
                        <tr wire:key="completed-production-{{ $production->id }}-{{ $production->packed_lot_id }}">
                            <td class="whitespace-nowrap px-5 py-3 font-mono">{{ $production->manufacture_date->format('Y-m-d') }}</td>
                            <th scope="row" class="px-4 py-3 font-medium">
                                {{ $production->displayRecipeName() }}
                                <span class="block text-xs text-[var(--color-ink-soft)]">{{ $production->packed_size_label ?? $production->product_size_label_snapshot ?? __('production_bench.inventory.size_not_recorded') }}</span>
                            </th>
                            <td class="px-4 py-3">
                                <a href="{{ route('production-bench.production.show', $production) }}" wire:navigate class="font-mono text-[var(--color-accent-strong)] underline">{{ $production->packed_lot_code ?? $production->displayIdentifier() }}</a>
                            </td>
                            <td class="numeric px-5 py-3 text-right">{{ \App\Support\NumberLocale::formatDecimal($production->packed_units ?? $production->actual_output_units, 0, $numberLocale) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-sticky-table-scroll>
        <x-table-pagination :paginator="$productions" :per-page-label="__('production_bench.production.per_page')" />
    @endif
</section>
