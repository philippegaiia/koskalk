<div class="mx-auto max-w-7xl space-y-8">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

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
            <div>
                <p class="numeric sk-eyebrow">{{ $supplier->code }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $supplier->name }}</h1>
            </div>
            <div class="flex flex-wrap gap-3">
                @if ($isBenchActive)
                    <a href="{{ route('production-bench.purchasing.suppliers.edit', $supplier) }}" wire:navigate class="rounded-full border border-[var(--color-line-strong)] px-5 py-2.5 text-center text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel-strong)]">Edit supplier</a>
                    <a href="{{ route('production-bench.purchasing.suppliers.listings.create', $supplier) }}" wire:navigate class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-center text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Add listing</a>
                @endif
            </div>
        </header>

        <section class="grid gap-4 lg:grid-cols-3">
            <article class="sk-card p-5">
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Details</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-[var(--color-ink-soft)]">Status</dt><dd class="mt-1">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">Currency</dt><dd class="numeric mt-1">{{ $supplier->default_currency }}</dd></div>
                </dl>
            </article>
            <article class="sk-card p-5">
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Main contact</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-[var(--color-ink-soft)]">Name</dt><dd class="mt-1">{{ $supplier->contact_name ?: '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">Email</dt><dd class="mt-1">@if ($supplier->email)<a href="mailto:{{ $supplier->email }}" class="text-[var(--color-accent-strong)]">{{ $supplier->email }}</a>@else — @endif</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">Telephone</dt><dd class="mt-1">{{ $supplier->phone ?: '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">Website</dt><dd class="mt-1 break-all">@if ($supplier->website)<a href="{{ $supplier->website }}" class="text-[var(--color-accent-strong)]">{{ $supplier->website }}</a>@else — @endif</dd></div>
                </dl>
            </article>
            <article class="sk-card p-5">
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Address</h2>
                <address class="mt-4 text-sm not-italic leading-6">
                    @forelse (collect([$supplier->address_line_1, $supplier->address_line_2, $supplier->city, $supplier->region, $supplier->postal_code, $supplier->country_code])->filter() as $addressPart)
                        <span class="block">{{ $addressPart }}</span>
                    @empty
                        <span class="text-[var(--color-ink-soft)]">—</span>
                    @endforelse
                </address>
            </article>
        </section>

        @if ($supplier->notes)
            <section class="sk-card p-5"><h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">Notes</h2><p class="mt-4 whitespace-pre-line text-sm leading-6">{{ $supplier->notes }}</p></section>
        @endif

        <section class="overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)]">
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] p-5 sm:flex-row sm:items-end sm:justify-between">
                <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">Listings</h2>
                <label class="space-y-1"><span class="text-sm font-medium">Status</span><select wire:model.live="listingStatus" class="sk-input"><option value="active">Active</option><option value="all">All</option><option value="inactive">Inactive</option></select></label>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">Material</th><th class="px-4 py-3">Purchase format</th><th class="px-4 py-3 text-right">Net quantity</th><th class="px-5 py-3 text-right">Price</th><th class="px-5 py-3">Status</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($listingRows as $row)
                            @php($listing = $row['listing'])
                            <tr wire:key="listing-{{ $listing->id }}">
                                <td class="px-5 py-4 font-medium">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</td>
                                <td class="px-4 py-4">{{ $listing->purchase_format }}@if ($listing->supplier_sku)<span class="numeric ml-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->supplier_sku }}</span>@endif</td>
                                <td class="numeric px-4 py-4 text-right">{{ rtrim(rtrim($listing->net_quantity, '0'), '.') }} {{ $listing->net_unit }}</td>
                                <td class="px-5 py-4 text-right"><p class="text-xs font-medium text-[var(--color-ink-soft)]">{{ $row['price']['basis_label'] }}</p><p class="numeric mt-1">{{ $row['price']['entered_price'] }}</p><p class="numeric text-[var(--color-ink-soft)]">Derived: {{ $row['price']['derived_price'] }}</p></td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $listing->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $listing->is_active ? 'Active' : 'Inactive' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">No listings.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$listingRows" per-page-label="Supplier listings per page" />
        </section>
    @endif
</div>
