<div class="mx-auto max-w-7xl space-y-8">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Production Bench is not active.</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">Production Bench</a>
        </section>
    @else
        @if ($isReadOnly)
            <p role="status" class="rounded-xl bg-[var(--color-warning-soft)] px-4 py-3 text-sm text-[var(--color-warning-strong)]">Read-only. Resume Production Bench to make changes.</p>
        @endif

        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><p class="sk-eyebrow">Purchasing</p><h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">Supplier listings</h1></div>
            @if ($isBenchActive)
                <a href="{{ route('production-bench.purchasing.listings.create') }}" wire:navigate class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-center text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Add listing</a>
            @endif
        </header>
        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div class="grid gap-3 border-b border-[var(--color-line)] p-5 md:grid-cols-2 xl:grid-cols-4">
                <label class="space-y-1 md:col-span-2"><span class="text-sm font-medium">Search supplier listings</span><input wire:model.live.debounce.300ms="search" type="search" placeholder="Supplier, material, SKU, purchase format…" class="sk-input w-full"></label>
                <label class="space-y-1"><span class="text-sm font-medium">Filter by supplier</span><select wire:model.live="supplierId" class="sk-input w-full"><option value="">All suppliers</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
                <div class="grid grid-cols-2 gap-2"><label class="space-y-1"><span class="text-sm font-medium">Material type</span><select wire:model.live="materialType" class="sk-input w-full"><option value="all">All materials</option><option value="ingredient">Ingredients</option><option value="packaging">Packaging</option></select></label><label class="space-y-1"><span class="text-sm font-medium">Listing state</span><select wire:model.live="status" class="sk-input w-full"><option value="active">Active</option><option value="all">All states</option><option value="inactive">Inactive</option></select></label></div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">Material</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Purchase format</th><th class="px-4 py-3 text-right">Net quantity</th><th class="px-4 py-3 text-right">Latest price</th><th class="px-5 py-3">State</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($listingRows as $row)
                            @php($listing = $row['listing'])
                            <tr wire:key="supplier-listing-{{ $listing->id }}">
                                <td class="px-5 py-4 font-medium">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</td>
                                <td class="px-4 py-4"><a href="{{ route('production-bench.purchasing.supplier', $listing->supplier) }}" wire:navigate class="text-[var(--color-ink-strong)] hover:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $listing->supplier->name }}</a></td>
                                <td class="px-4 py-4">{{ $listing->purchase_format }}<span class="numeric ml-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->supplier_sku }}</span></td>
                                <td class="numeric px-4 py-4 text-right">{{ rtrim(rtrim($listing->net_quantity, '0'), '.') }} {{ $listing->net_unit }}</td>
                                <td class="px-4 py-4 text-right"><p class="text-xs font-medium text-[var(--color-ink-soft)]">{{ $row['price']['basis_label'] }}</p><p class="numeric mt-1">{{ $row['price']['entered_price'] }}</p><p class="numeric text-[var(--color-ink-soft)]">Derived: {{ $row['price']['derived_price'] }}</p></td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $listing->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $listing->is_active ? 'Active' : 'Inactive' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">No supplier listings.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$listingRows" per-page-label="Supplier listings per page" />
        </section>
    @endif
</div>
