@props(['help', 'tab' => null, 'dynamicTab' => false])

@if ($dynamicTab ? ! empty($help['topics']) : ! empty($help['tabs'][$tab]))
    <button
        type="button"
        x-data
        @if ($dynamicTab)
            @click="$dispatch('contextual-help:index', { tab: activeWorkbenchTab })"
            x-show="(@js($help['tabs'])[activeWorkbenchTab] || []).length"
        @else
            @click="$dispatch('contextual-help:index', { tab: @js($tab) })"
        @endif
        data-help-index
        class="inline-flex min-h-10 shrink-0 items-center justify-center gap-1.5 rounded-md px-2 text-sm font-medium text-[var(--color-ink-soft)] transition-colors hover:bg-[var(--color-field-muted)] hover:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
        aria-controls="contextual-help-panel"
    >
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C9.5 5 6 4.5 3 5v14c3-.5 6.5 0 9 1.5m0-14C14.5 5 18 4.5 21 5v14c-3-.5-6.5 0-9 1.5m0-14v14"/></svg>
        {{ __('contextual_help.help') }}
    </button>
@endif
