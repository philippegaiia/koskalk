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
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.navigation.suppliers') }}</h1>
            @if ($isBenchActive)
                <a href="{{ route('production-bench.purchasing.suppliers.create') }}" wire:navigate class="sk-btn sk-btn-primary">{{ __('production_bench.supplier.add') }}</a>
            @endif
        </header>

        <section class="overflow-hidden sk-card">
            <div data-production-bench-filters class="border-b border-[var(--color-line)] p-4">
                {{ $this->filtersForm }}
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-sm">
                    <thead class="bg-[var(--color-panel-muted)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]"><tr><th class="px-5 py-3">{{ __('production_bench.supplier.code') }}</th><th class="px-4 py-3">{{ __('production_bench.supplier.singular') }}</th><th class="px-4 py-3">{{ __('production_bench.supplier.location') }}</th><th class="px-4 py-3">{{ __('production_bench.supplier.main_contact') }}</th><th class="px-4 py-3">{{ __('production_bench.common.status') }}</th><th class="px-4 py-3 text-right">{{ __('production_bench.listing.listings') }}</th><th class="px-5 py-3 text-right">{{ __('production_bench.common.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @forelse ($suppliers as $supplier)
                            <tr wire:key="supplier-{{ $supplier->id }}" class="transition hover:bg-[var(--color-panel-strong)]">
                                <td class="numeric px-5 py-4 font-medium text-[var(--color-ink-strong)]">{{ $supplier->code }}</td>
                                <td class="px-4 py-4"><a href="{{ route('production-bench.purchasing.supplier', $supplier) }}" wire:navigate class="font-medium text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">{{ $supplier->name }}</a></td>
                                <td class="px-4 py-4 text-[var(--color-ink-soft)]">{{ collect([$supplier->city, $supplier->country_code])->filter()->join(', ') ?: '—' }}</td>
                                <td class="px-4 py-4"><p>{{ $supplier->contact_name ?: '—' }}</p>@if ($supplier->email)<p class="text-xs text-[var(--color-ink-soft)]">{{ $supplier->email }}</p>@endif</td>
                                <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $supplier->is_active ? 'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]' }}">{{ $supplier->is_active ? __('production_bench.common.active') : __('production_bench.common.inactive') }}</span></td>
                                <td class="numeric px-4 py-4 text-right">{{ $supplier->listings_count }}</td>
                                <td class="px-5 py-4 text-right">@if ($isBenchActive)<a href="{{ route('production-bench.purchasing.suppliers.edit', $supplier) }}" wire:navigate class="text-sm font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('production_bench.common.edit') }}</a>@else — @endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-12 text-center text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.supplier.none') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-table-pagination :paginator="$suppliers" :per-page-label="__('production_bench.supplier.per_page')" />
        </section>
    @endif
</x-production-bench.page>
