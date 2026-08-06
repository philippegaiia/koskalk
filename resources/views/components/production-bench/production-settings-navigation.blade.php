<nav aria-label="{{ __('production_bench.navigation.production') }} sections" class="flex flex-wrap gap-2">
    @foreach ([
        'production-bench.production.settings.numbering' => __('production_bench.settings.numbering'),
        'production-bench.production.settings.presets' => __('production_bench.settings.presets'),
        'production-bench.production.settings.departments' => __('production_bench.settings.departments'),
        'production-bench.production.settings.employees' => __('production_bench.settings.employees'),
        'production-bench.production.settings.task-types' => __('production_bench.settings.task_types'),
        'production-bench.production.settings.task-sets' => __('production_bench.settings.task_sets'),
        'production-bench.production.settings.calendar' => __('production_bench.settings.working_calendar'),
    ] as $routeName => $label)
        @php($isCurrent = request()->routeIs($routeName.'*'))
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
