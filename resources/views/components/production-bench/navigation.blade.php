<nav aria-label="Production Bench" class="flex min-w-0 gap-1 border-b border-[var(--color-line)] px-3 sm:px-4">
    @foreach ([
        'production-bench.home' => __('production_bench.navigation.home'),
        'production-bench.inventory' => __('production_bench.navigation.inventory'),
        'production-bench.production.create' => __('production_bench.navigation.production_workflow'),
        'production-bench.purchasing.suppliers' => __('production_bench.navigation.purchasing'),
        'production-bench.production.settings' => __('production_bench.navigation.production'),
    ] as $routeName => $label)
        <a
            href="{{ route($routeName) }}"
            wire:navigate
            @if ($routeName === 'production-bench.purchasing.suppliers' ? request()->routeIs('production-bench.purchasing.*') : request()->routeIs($routeName))
                aria-current="page"
            @endif
            @class([
                'whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium transition sm:px-4',
                'border-[var(--color-accent)] text-[var(--color-ink-strong)]' => $routeName === 'production-bench.purchasing.suppliers' ? request()->routeIs('production-bench.purchasing.*') : request()->routeIs($routeName),
                'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $routeName === 'production-bench.purchasing.suppliers' ? ! request()->routeIs('production-bench.purchasing.*') : ! request()->routeIs($routeName),
            ])
        >{{ $label }}</a>
    @endforeach
</nav>
