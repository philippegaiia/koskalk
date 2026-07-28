<nav aria-label="Production Bench" class="flex gap-1 overflow-x-auto border-b border-[var(--color-line)]">
    @foreach ([
        'production-bench.home' => 'Home',
        'production-bench.inventory' => 'Inventory',
        'production-bench.purchasing' => 'Purchasing',
    ] as $routeName => $label)
        <a
            href="{{ route($routeName) }}"
            wire:navigate
            @class([
                '-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition',
                'border-[var(--color-accent)] text-[var(--color-ink-strong)]' => request()->routeIs($routeName),
                'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => ! request()->routeIs($routeName),
            ])
        >{{ $label }}</a>
    @endforeach
    <span class="whitespace-nowrap px-4 py-3 text-sm text-[var(--color-ink-muted)]">Production runs · next checkpoint</span>
</nav>
