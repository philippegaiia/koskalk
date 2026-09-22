@props(['help', 'tab'])

@if (! empty($help['tabs'][$tab]))
    <button
        type="button"
        x-data
        @click="$dispatch('contextual-help:index', { tab: @js($tab) })"
        data-help-index
        class="sk-btn sk-btn-outline shrink-0 self-start text-[var(--color-ink-strong)] shadow-sm"
        aria-controls="contextual-help-panel"
    >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9a2.25 2.25 0 0 1 4.5 0c0 1.5-2.25 1.5-2.25 3M12 16h.01"/></svg>
        {{ __('contextual_help.help') }}
    </button>
@endif
