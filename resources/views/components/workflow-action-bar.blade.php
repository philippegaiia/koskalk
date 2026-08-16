@props([
    'maxWidth' => 'max-w-5xl',
])

<div
    {{ $attributes->class([
        'sk-workflow-action-bar pointer-events-none px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-5',
    ]) }}
    data-workflow-action-bar
>
    <div @class([
        'pointer-events-auto mx-auto flex flex-nowrap items-center gap-2 rounded-xl border border-[var(--color-line)] bg-[color-mix(in_oklab,var(--color-panel)_80%,transparent)] px-3 py-3 shadow-[0_-8px_24px_rgba(60,50,30,0.10)] backdrop-blur-md sm:px-4',
        $maxWidth,
    ])>
        @isset($leading)
            @if ($leading->hasActualContent())
                <div class="flex min-w-0 flex-1 items-center gap-2 overflow-hidden [&>*]:truncate">
                    {{ $leading }}
                </div>
            @endif
        @endisset

        <div class="ml-auto flex min-w-0 flex-nowrap items-center justify-end gap-2 [&>*]:shrink-0">
            {{ $slot }}
        </div>
    </div>
</div>
