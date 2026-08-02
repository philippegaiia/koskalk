<x-production-bench.page purchasing>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Inactive</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">Production Bench</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">Read-only. Resume to edit.</p>
        @endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Supplier listings</h1>
            @if ($isBenchActive)
                <a href="{{ route('production-bench.purchasing.listings.create') }}" wire:navigate class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-center text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Add listing</a>
            @endif
        </header>
        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div data-production-bench-filters class="border-b border-[var(--color-line)] p-4">
                {{ $this->filtersForm }}
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">Material</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Purchase format</th><th class="px-4 py-3 text-right">Net quantity</th><th class="px-4 py-3 text-right">Latest price</th><th class="px-4 py-3">Status</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($listingRows as $row)
                            @php($listing = $row['listing'])
                            <tr wire:key="supplier-listing-{{ $listing->id }}">
                                <td class="px-5 py-4 font-medium">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</td>
                                <td class="px-4 py-4"><a href="{{ route('production-bench.purchasing.supplier', $listing->supplier) }}" wire:navigate class="text-[var(--color-ink-strong)] hover:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $listing->supplier->name }}</a></td>
                                <td class="px-4 py-4">{{ $listing->purchase_format }}<span class="numeric ml-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->supplier_sku }}</span></td>
                                <td class="numeric px-4 py-4 text-right">{{ rtrim(rtrim($listing->net_quantity, '0'), '.') }} {{ $listing->net_unit }}</td>
                                <td class="px-4 py-4 text-right"><p class="text-xs font-medium text-[var(--color-ink-soft)]">{{ $row['price']['basis_label'] }}</p><p class="numeric mt-1">{{ $row['price']['entered_price'] }}</p><p class="numeric text-[var(--color-ink-soft)]">Derived: {{ $row['price']['derived_price'] }}</p></td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $listing->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $listing->is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td class="px-5 py-4 text-right">@if ($isBenchActive)<a href="{{ route('production-bench.purchasing.listings.edit', $listing) }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">Edit</a>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">No listings.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$listingRows" per-page-label="Supplier listings per page" />
        </section>
    @endif
</x-production-bench.page>
