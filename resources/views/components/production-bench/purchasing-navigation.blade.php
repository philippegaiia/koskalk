<nav aria-label="Purchasing sections" class="flex flex-wrap gap-2">
    @foreach ([
        'production-bench.purchasing.suppliers' => __('production_bench.navigation.suppliers'),
        'production-bench.purchasing.listings' => __('production_bench.navigation.supplier_listings'),
        'production-bench.purchasing.quotations' => __('production_bench.navigation.quotation_requests'),
        'production-bench.purchasing.orders' => __('production_bench.navigation.purchase_orders'),
        'production-bench.purchasing.receipts' => __('production_bench.navigation.receipts'),
    ] as $routeName => $label)
        @php($isCurrent = request()->routeIs($routeName) || ($routeName === 'production-bench.purchasing.suppliers' && request()->routeIs('production-bench.purchasing.supplier', 'production-bench.purchasing.suppliers.create', 'production-bench.purchasing.suppliers.edit', 'production-bench.purchasing.suppliers.listings.create')) || ($routeName === 'production-bench.purchasing.listings' && request()->routeIs('production-bench.purchasing.listings.create')) || ($routeName === 'production-bench.purchasing.quotations' && request()->routeIs('production-bench.purchasing.quotations.create')) || ($routeName === 'production-bench.purchasing.orders' && request()->routeIs('production-bench.purchasing.orders.create', 'production-bench.purchasing.procurement.show')) || ($routeName === 'production-bench.purchasing.receipts' && request()->routeIs('production-bench.purchasing.receipts.*')))
        <a
            href="{{ route($routeName) }}"
            wire:navigate
            @if ($isCurrent)
                aria-current="page"
            @endif
            @class([
                'rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]',
                'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' => $isCurrent,
                'text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-ink-strong)]' => ! $isCurrent,
            ])
        >{{ $label }}</a>
    @endforeach

</nav>
