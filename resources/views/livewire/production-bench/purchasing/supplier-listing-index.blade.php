<x-production-bench.page purchasing>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">{{ __('production_bench.common.read_only') }}</p>
        @endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.listing.plural') }}</h1>
            @if ($isBenchActive)
                <a href="{{ route('production-bench.purchasing.listings.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.listing.add') }}</a>
            @endif
        </header>
        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div data-production-bench-filters class="border-b border-[var(--color-line)] p-4">
                {{ $this->filtersForm }}
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">{{ __('production_bench.listing.material') }}</th><th class="px-4 py-3">{{ __('production_bench.supplier.singular') }}</th><th class="px-4 py-3">{{ __('production_bench.listing.purchase_format') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.listing.net_quantity') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.listing.latest_price') }}</th><th class="px-4 py-3">{{ __('production_bench.common.status') }}</th><th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($listingRows as $row)
                            @php($listing = $row['listing'])
                            <tr wire:key="supplier-listing-{{ $listing->id }}">
                                <td class="px-5 py-4 font-medium">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</td>
                                <td class="px-4 py-4"><a href="{{ route('production-bench.purchasing.supplier', $listing->supplier) }}" wire:navigate class="text-[var(--color-ink-strong)] hover:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $listing->supplier->name }}</a></td>
                                <td class="px-4 py-4">{{ $listing->purchase_format }}<span class="numeric ml-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->supplier_sku }}</span></td>
                                <td class="numeric px-4 py-4 text-right">{{ rtrim(rtrim($listing->net_quantity, '0'), '.') }} {{ $listing->net_unit }}</td>
                                <td class="px-4 py-4 text-right"><p class="text-xs font-medium text-[var(--color-ink-soft)]">{{ $row['price']['basis_label'] }}</p><p class="numeric mt-1">{{ $row['price']['entered_price'] }}</p><p class="numeric text-[var(--color-ink-soft)]">{{ __('production_bench.listing.derived', ['price' => $row['price']['derived_price']]) }}</p></td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $listing->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $listing->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></td>
                                <td class="px-5 py-4 text-right">@if ($isBenchActive)<a href="{{ route('production-bench.purchasing.listings.edit', $listing) }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.common.edit') }}</a>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.listing.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$listingRows" :per-page-label="__('production_bench.listing.per_page')" />
        </section>
    @endif
</x-production-bench.page>
