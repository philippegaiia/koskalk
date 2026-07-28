<nav aria-label="Purchasing sections" class="flex flex-wrap gap-2">
    @foreach ([
        'production-bench.purchasing.suppliers' => 'Suppliers',
        'production-bench.purchasing.listings' => 'Supplier listings',
    ] as $routeName => $label)
        <a
            href="{{ route($routeName) }}"
            wire:navigate
            @class([
                'rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]',
                'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' => request()->routeIs($routeName),
                'text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-ink-strong)]' => ! request()->routeIs($routeName),
            ])
        >{{ $label }}</a>
    @endforeach

    @foreach (['Quotation requests', 'Purchase orders', 'Receipts'] as $label)
        <span class="cursor-not-allowed rounded-lg px-3 py-2 text-sm text-[var(--color-ink-muted)]" aria-disabled="true" title="Coming later">{{ $label }} <span class="text-xs">Coming later</span></span>
    @endforeach
</nav>
