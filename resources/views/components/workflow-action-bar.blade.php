<div
    {{ $attributes->class([
        'pointer-events-none fixed bottom-0 left-0 right-0 z-30 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5 lg:left-[var(--app-sidebar-width,0rem)]',
    ]) }}
    data-workflow-action-bar
>
    <div class="pointer-events-auto mx-auto flex max-w-5xl flex-wrap items-center gap-2 rounded-xl border border-[var(--color-line)] bg-[color-mix(in_oklab,var(--color-panel)_88%,transparent)] px-3 py-3 shadow-[0_-8px_24px_rgba(60,50,30,0.10)] backdrop-blur-md sm:flex-nowrap sm:px-4">
        @isset($leading)
            @if ($leading->hasActualContent())
                <div class="flex min-w-0 items-center gap-2">
                    {{ $leading }}
                </div>
            @endif
        @endisset

        <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
            {{ $slot }}
        </div>
    </div>
</div>
