@props(['current' => null])

<nav aria-label="{{ __('production_bench.navigation.inventory') }} sections" class="flex flex-wrap gap-2">
    @foreach ([
        'production-bench.inventory' => __('production_bench.inventory.overview'),
        'production-bench.inventory.stock' => __('production_bench.inventory.stock'),
        'production-bench.inventory.requirements' => __('production_bench.inventory.requirements'),
    ] as $routeName => $label)
        @php($isCurrent = $current !== null
            ? $current === match ($routeName) {
                'production-bench.inventory' => 'overview',
                'production-bench.inventory.stock' => 'stock',
                'production-bench.inventory.requirements' => 'requirements',
            }
            : request()->routeIs($routeName))
        <a
            href="{{ route($routeName) }}"
            wire:navigate
            @if ($isCurrent)
                aria-current="page"
            @endif
            @class([
                'inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]',
                'bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' => $isCurrent,
                'text-[var(--color-ink-soft)] hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-ink-strong)]' => ! $isCurrent,
            ])
        >{{ $label }}</a>
    @endforeach
</nav>
