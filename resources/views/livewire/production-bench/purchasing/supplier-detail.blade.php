<x-production-bench.page active="purchasing" subnavigation="suppliers">
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
            <div>
                <p class="numeric sk-eyebrow">{{ $supplier->code }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ $supplier->name }}</h1>
            </div>
            <div class="flex flex-wrap gap-3">
                @if ($isBenchActive)
                    <a href="{{ route('production-bench.purchasing.suppliers.edit', $supplier) }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('production_bench.supplier.edit') }}</a>
                    <a href="{{ route('production-bench.purchasing.suppliers.listings.create', $supplier) }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.listing.add') }}</a>
                @endif
            </div>
        </header>

        <section class="grid gap-4 lg:grid-cols-3">
            <article class="sk-card p-5">
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.supplier.details') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-[var(--color-ink-soft)]">{{ __('production_bench.common.status') }}</dt><dd class="mt-1">{{ $supplier->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">{{ __('production_bench.common.currency') }}</dt><dd class="numeric mt-1">{{ $supplier->default_currency }}</dd></div>
                </dl>
            </article>
            <article class="sk-card p-5">
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.supplier.main_contact') }}</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-[var(--color-ink-soft)]">{{ __('production_bench.common.name') }}</dt><dd class="mt-1">{{ $supplier->contact_name ?: '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">{{ __('production_bench.supplier.email') }}</dt><dd class="mt-1">@if ($supplier->email)<a href="mailto:{{ $supplier->email }}" class="text-[var(--color-accent-strong)]">{{ $supplier->email }}</a>@else — @endif</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">{{ __('production_bench.supplier.telephone') }}</dt><dd class="mt-1">{{ $supplier->phone ?: '—' }}</dd></div>
                    <div><dt class="text-[var(--color-ink-soft)]">{{ __('production_bench.supplier.website') }}</dt><dd class="mt-1 break-all">@if ($supplier->website)<a href="{{ $supplier->website }}" class="text-[var(--color-accent-strong)]">{{ $supplier->website }}</a>@else — @endif</dd></div>
                </dl>
            </article>
            <article class="sk-card p-5">
                <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.supplier.address') }}</h2>
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
            <section class="sk-card p-5"><h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.notes') }}</h2><p class="mt-4 whitespace-pre-line text-sm leading-6">{{ $supplier->notes }}</p></section>
        @endif

        <section class="overflow-hidden sk-card">
            <div class="flex flex-col gap-3 border-b border-[var(--color-line)] p-5 sm:flex-row sm:items-end sm:justify-between">
                <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.listing.listings') }}</h2>
                <label class="space-y-1"><span class="text-sm font-medium">{{ __('production_bench.common.status') }}</span><select wire:model.live="listingStatus" class="sk-input"><option value="active">{{ __('production_bench.common.active') }}</option><option value="all">{{ __('production_bench.common.all') }}</option><option value="inactive">{{ __('production_bench.common.inactive') }}</option></select></label>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">{{ __('production_bench.listing.material') }}</th><th class="px-4 py-3">{{ __('production_bench.listing.purchase_format') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.listing.net_quantity') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.listing.price') }}</th><th class="px-4 py-3">{{ __('production_bench.common.status') }}</th><th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($listingRows as $row)
                            @php($listing = $row['listing'])
                            @php($materialCode = $listing->ingredient?->workspaceCodes?->first()?->material_code ?? $listing->packagingItem?->material_code)
                            <tr id="listing-{{ $listing->public_id }}" wire:key="listing-{{ $listing->id }}">
                                <td class="px-5 py-4"><p class="font-medium">{{ $listing->ingredient?->localizedDisplayName() ?? $listing->packagingItem?->name }}</p>@if ($materialCode)<p class="mt-1 font-mono text-xs font-medium text-[var(--color-ink-soft)]">{{ $materialCode }}</p>@endif</td>
                                <td class="px-4 py-4">{{ $listing->purchase_format }}@if ($listing->supplier_sku)<span class="numeric ml-1 text-xs text-[var(--color-ink-soft)]">{{ $listing->supplier_sku }}</span>@endif</td>
                                <td class="numeric px-4 py-4 text-right">{{ rtrim(rtrim($listing->net_quantity, '0'), '.') }} {{ $listing->net_unit }}</td>
                                <td class="px-4 py-4 text-right"><p class="text-xs font-medium text-[var(--color-ink-soft)]">{{ $row['price']['basis_label'] }}</p><p class="numeric mt-1">{{ $row['price']['entered_price'] }}</p><p class="numeric text-[var(--color-ink-soft)]">{{ __('production_bench.listing.derived', ['price' => $row['price']['derived_price']]) }}</p></td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $listing->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $listing->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></td>
                                <td class="px-5 py-4 text-right">@if ($isBenchActive)<a href="{{ route('production-bench.purchasing.listings.edit', $listing) }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.common.edit') }}</a>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.listing.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$listingRows" :per-page-label="__('production_bench.listing.per_page')" />
        </section>
    @endif
</x-production-bench.page>
