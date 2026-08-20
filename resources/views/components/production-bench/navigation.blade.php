@props(['active' => null])

<nav aria-label="Production Bench" class="flex min-w-0 gap-1 border-b border-[var(--color-line)] px-3 sm:px-4">
    @php($productionWorkflowActive = request()->routeIs('production-bench.production.index', 'production-bench.production.show', 'production-bench.production.prepare'))
    @php($inventoryActive = request()->routeIs('production-bench.inventory*'))
    @foreach ([
        'production-bench.home' => __('production_bench.navigation.home'),
        'production-bench.inventory' => __('production_bench.navigation.inventory'),
        'production-bench.production.index' => __('production_bench.navigation.production_workflow'),
        'production-bench.production.tasks' => __('production_bench.navigation.tasks'),
        'production-bench.production.flash' => __('production_bench.navigation.flash'),
        'production-bench.production.calendar' => __('production_bench.navigation.calendar'),
        'production-bench.purchasing.suppliers' => __('production_bench.navigation.purchasing'),
        'production-bench.production.settings.presets' => __('production_bench.navigation.production'),
    ] as $routeName => $label)
        @php($navigationKey = match ($routeName) {
            'production-bench.home' => 'home',
            'production-bench.inventory' => 'inventory',
            'production-bench.production.index' => 'production',
            'production-bench.production.tasks' => 'tasks',
            'production-bench.production.flash' => 'flash',
            'production-bench.production.calendar' => 'calendar',
            'production-bench.purchasing.suppliers' => 'purchasing',
            'production-bench.production.settings.presets' => 'production-setup',
        })
        @php($isCurrent = $active !== null
            ? $active === $navigationKey
            : match ($navigationKey) {
                'inventory' => $inventoryActive,
                'purchasing' => request()->routeIs('production-bench.purchasing.*'),
                'production-setup' => request()->routeIs('production-bench.production.settings*'),
                'production' => $productionWorkflowActive,
                default => request()->routeIs($routeName),
            })
        <a
            href="{{ route($routeName) }}"
            wire:navigate
            @if ($isCurrent)
                aria-current="page"
            @endif
            @class([
                'whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium transition sm:px-4',
                'border-[var(--color-accent)] text-[var(--color-ink-strong)]' => $isCurrent,
                'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => ! $isCurrent,
            ])
        >{{ $label }}</a>
    @endforeach
</nav>
