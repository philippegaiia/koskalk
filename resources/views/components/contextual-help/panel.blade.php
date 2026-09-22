<aside id="contextual-help-panel" data-contextual-help-panel data-index-title="{{ __('contextual_help.index_title') }}" aria-labelledby="contextual-help-heading" role="complementary" class="sk-help-panel" hidden>
    <header class="flex items-center justify-between gap-3 border-b border-[var(--color-line)] px-5 py-4">
        <button type="button" data-help-back class="sk-btn sk-btn-ghost" hidden>{{ __('contextual_help.back') }}</button>
        <button type="button" data-help-close class="sk-btn sk-btn-ghost ml-auto">{{ __('contextual_help.close') }}</button>
    </header>
    <div class="overflow-y-auto p-5">
        <h2 id="contextual-help-heading" data-help-heading tabindex="-1" aria-live="polite" class="mb-4 text-xl font-semibold"></h2>
        <div data-help-content class="space-y-5 text-sm leading-relaxed"></div>
    </div>
</aside>
