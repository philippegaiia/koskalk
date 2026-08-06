<x-production-bench.page compact>
    @if (! $isBenchActive && ! $isReadOnly)
        <section class="sk-card p-8 text-center">
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.common.inactive') }}</h1>
            <a href="{{ route('production-bench.home') }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-[var(--color-accent)]">{{ __('production_bench.title') }}</a>
        </section>
    @else
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="sk-eyebrow">{{ __('production_bench.navigation.production_workflow') }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.calendar.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-[var(--color-ink-soft)]">{{ __('production_bench.calendar.intro') }}</p>
            </div>
            <a href="{{ route('production-bench.production.index') }}" wire:navigate class="sk-btn sk-btn-secondary">{{ __('production_bench.production.index_title') }}</a>
        </header>

        @error('range')
            <p role="alert" class="rounded-xl bg-[var(--color-danger-soft)] px-4 py-3 text-sm text-[var(--color-danger-strong)]">{{ $message }}</p>
        @enderror

        <section class="sk-card space-y-4 p-4 sm:p-5">
            <div class="flex flex-wrap items-center gap-4" role="group" aria-label="{{ __('production_bench.calendar.title') }}">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-[var(--color-ink-strong)]">
                    <input type="checkbox" wire:model.live="showProductions" style="accent-color: var(--color-accent);" class="h-4 w-4 rounded border-[var(--color-line-strong)]">
                    {{ __('production_bench.calendar.productions') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-[var(--color-ink-strong)]">
                    <input type="checkbox" wire:model.live="showTasks" style="accent-color: var(--color-accent);" class="h-4 w-4 rounded border-[var(--color-line-strong)]">
                    {{ __('production_bench.calendar.tasks') }}
                </label>
                <label class="inline-flex items-center gap-2 text-sm font-medium text-[var(--color-ink-strong)]">
                    <input type="checkbox" wire:model.live="showCompleted" style="accent-color: var(--color-accent);" class="h-4 w-4 rounded border-[var(--color-line-strong)]">
                    {{ __('production_bench.calendar.completed') }}
                </label>
            </div>

            <div
                wire:ignore
                data-production-calendar
                data-events="{{ json_encode($events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                data-calendar-options="{{ json_encode([
                    'view' => 'dayGridMonth',
                    'date' => $today,
                    'events' => $events,
                    'headerToolbar' => ['start' => 'prev today next', 'center' => 'title', 'end' => 'dayGridMonth timeGridWeek dayGridDay listWeek'],
                    'buttonText' => [
                        'today' => __('production_bench.calendar.today'),
                        'dayGridMonth' => __('production_bench.calendar.month'),
                        'timeGridWeek' => __('production_bench.calendar.week'),
                        'dayGridDay' => __('production_bench.calendar.day'),
                        'listWeek' => __('production_bench.calendar.agenda'),
                    ],
                    'height' => 'auto',
                    'firstDay' => 1,
                    'noEventsContent' => __('production_bench.calendar.no_events'),
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
                data-range-start="{{ $rangeStart }}"
                data-range-end="{{ $rangeEnd }}"
                x-data="productionCalendarComponent()"
                class="production-calendar min-h-[32rem]"
            ></div>
        </section>
    @endif
</x-production-bench.page>
