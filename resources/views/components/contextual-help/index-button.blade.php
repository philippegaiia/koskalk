@props(['help', 'tab' => null, 'dynamicTab' => false])

@if ($dynamicTab ? ! empty($help['topics']) : ! empty($help['tabs'][$tab]))
    <div x-data class="contents">
        <template x-teleport="#contextual-help-topbar">
            <button
                type="button"
                @if ($dynamicTab)
                    @click="$dispatch('contextual-help:index', { tab: activeWorkbenchTab })"
                    x-show="(@js($help['tabs'])[activeWorkbenchTab] || []).length"
                @else
                    @click="$dispatch('contextual-help:index', { tab: @js($tab) })"
                @endif
                data-help-index
                class="sk-help-index inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-full border border-[var(--color-accent)]/40 bg-[var(--color-accent-soft)] px-3.5 text-sm font-semibold text-[var(--color-accent-strong)] transition-colors hover:border-[var(--color-accent)] hover:bg-[var(--color-panel)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                aria-controls="contextual-help-panel"
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5C9.5 5 6 4.5 3 5v14c3-.5 6.5 0 9 1.5m0-14C14.5 5 18 4.5 21 5v14c-3-.5-6.5 0-9 1.5m0-14v14"/></svg>
                {{ __('contextual_help.help') }}
            </button>
        </template>
    </div>
@endif
