@php($numberLocale = auth()->user()?->number_locale)
<section class="sk-card overflow-hidden" aria-labelledby="{{ $showStockHeading ? 'finished-stock-heading' : 'finished-products-heading' }}">
    @if ($showStockHeading)
        <h2 id="finished-stock-heading" class="border-b border-[var(--color-line)] px-5 py-4 text-lg font-semibold">{{ __('production_bench.inventory.finished_stock_by_size') }}</h2>
    @endif
    <x-sticky-table-scroll>
        <table class="w-full min-w-[480px] text-left text-sm">
            <thead wire:ignore.self data-sticky-table-header class="relative z-20 whitespace-nowrap bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                <tr>
                    <th scope="col" class="px-5 py-3">{{ __('production_bench.production.product') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('batch_log.fields.product_size') }}</th>
                    @unless ($showStockHeading)
                        <th scope="col" class="px-4 py-3">{{ __('production_bench.inventory.sku') }}</th>
                    @endunless
                    <th scope="col" class="px-4 py-3 text-right">{{ __('production_bench.inventory.available') }}</th>
                    <th scope="col" class="px-5 py-3 text-right">{{ __('production_bench.inventory.quarantined') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-[var(--color-line)]">
                @foreach ($finishedStock as $balance)
                    <tr>
                        <th scope="row" class="px-5 py-3 font-medium">
                            {{ $balance->recipe->name }}
                            @unless ($showStockHeading)
                                @php($organization = collect([$balance->recipe->brand?->name, $balance->recipe->productCollection?->name])->filter()->implode(' · '))
                                @if ($organization !== '')
                                    <span class="block text-xs text-[var(--color-ink-soft)]">{{ $organization }}</span>
                                @endif
                            @endunless
                        </th>
                        <td class="px-4 py-3">
                            @if (! $showStockHeading && $balance->productSize)
                                <a href="{{ route('production-bench.production.index', ['date_basis' => 'manufactured', 'size' => $balance->productSize->public_id]) }}" wire:navigate class="font-medium text-[var(--color-accent-strong)] underline">{{ $balance->productSize->label($numberLocale) }}</a>
                            @else
                                {{ $balance->productSize?->label($numberLocale) ?? __('production_bench.inventory.size_not_recorded') }}
                            @endif
                        </td>
                        @unless ($showStockHeading)
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono">{{ $balance->productSize?->sku ?? '—' }}</span>
                                    @if ($canEditSku && $balance->productSize)
                                        <button type="button" wire:click="mountAction('editSku', { sizeId: {{ $balance->product_size_id }} })" class="sk-btn sk-btn-ghost text-xs">
                                            {{ __('production_bench.inventory.edit_sku') }}
                                        </button>
                                    @endif
                                </div>
                            </td>
                        @endunless
                        <td class="numeric px-4 py-3 text-right">{{ \App\Support\NumberLocale::formatDecimal($balance->available, 0, $numberLocale) }}</td>
                        <td class="numeric px-5 py-3 text-right">{{ \App\Support\NumberLocale::formatDecimal($balance->quarantined, 0, $numberLocale) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-sticky-table-scroll>
    <x-table-pagination :paginator="$finishedStock" per-page-model="finishedPerPage" :per-page-label="__('production_bench.inventory.sizes_per_page')" />
</section>
