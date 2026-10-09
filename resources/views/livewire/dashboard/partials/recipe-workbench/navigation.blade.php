@php
    $workbench = $workbench ?? [];
    $isPublicCalculator = $isPublicCalculator ?? false;
    $hasSavedFormula = (bool) ($workbench['recipe']['has_saved_formula'] ?? false);
    $savedFormulaUrl = $workbench['recipe']['saved_formula_url'] ?? null;
    $tabBaseClass = 'sk-workbench-tab inline-flex shrink-0 items-center whitespace-nowrap px-0 py-3 text-left text-base font-semibold';
@endphp

<div class="sk-workbench-navigation border-b border-[var(--color-line)]">
    {{-- Output actions sit beside the tabs when the workbench is wide enough, and wrap to their own row below it so the tabs keep their full width. --}}
    <div class="flex min-w-0 flex-col gap-2 @6xl/workbench:flex-row @6xl/workbench:items-center">
    <div class="{{ $isPublicCalculator ? 'w-full' : 'relative min-w-0 max-w-full overflow-hidden flex-1' }}">
    <nav class="sk-workbench-tabs {{ $isPublicCalculator ? 'grid gap-2 sm:grid-cols-2' : 'flex min-w-0 max-w-full gap-7 overflow-x-auto overflow-y-hidden overscroll-x-contain touch-pan-x [scrollbar-width:thin]' }}" role="tablist" aria-label="{{ __('workbench.tabs.aria_label') }}">
        <button
            id="tab-formula"
            role="tab"
            type="button"
            @click="activeWorkbenchTab = 'formula'"
            :aria-selected="activeWorkbenchTab === 'formula'"
            :class="{ 'is-active': activeWorkbenchTab === 'formula' }"
            class="{{ $tabBaseClass }}"
        >
            {{ __('workbench.tabs.formula') }}
        </button>

        @unless ($isPublicCalculator)
            <button
                id="tab-packaging"
                role="tab"
                type="button"
                @click="activeWorkbenchTab = 'packaging'"
                :aria-selected="activeWorkbenchTab === 'packaging'"
                :class="{ 'is-active': activeWorkbenchTab === 'packaging' }"
                class="{{ $tabBaseClass }}"
            >
                {{ __('workbench.tabs.packaging') }}
            </button>

            <button
                id="tab-costing"
                role="tab"
                type="button"
                @click="activeWorkbenchTab = 'costing'; ensureCostingLoaded()"
                :aria-selected="activeWorkbenchTab === 'costing'"
                :class="{ 'is-active': activeWorkbenchTab === 'costing' }"
                class="{{ $tabBaseClass }}"
            >
                {{ __('workbench.tabs.costing') }}
            </button>
        @endunless

        <button
            id="tab-output"
            role="tab"
            type="button"
            @click="activeWorkbenchTab = 'output'"
            :aria-selected="activeWorkbenchTab === 'output'"
            :class="{ 'is-active': activeWorkbenchTab === 'output' }"
            class="{{ $tabBaseClass }}"
        >
            {{ __('workbench.tabs.output') }}
        </button>

        @unless ($isPublicCalculator)
            <button
                id="tab-instructions"
                role="tab"
                type="button"
                @click="activeWorkbenchTab = 'instructions'"
                :aria-selected="activeWorkbenchTab === 'instructions'"
                :class="{ 'is-active': activeWorkbenchTab === 'instructions' }"
                class="{{ $tabBaseClass }}"
            >
                {{ __('workbench.tabs.instructions') }}
            </button>

            @if ($hasSavedFormula && is_string($savedFormulaUrl))
                <a href="{{ $savedFormulaUrl }}" class="sk-formula-sheet-link inline-flex shrink-0 items-center whitespace-nowrap py-3 text-base font-semibold text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]">
                    {{ __('workbench.header.product_sheet') }}
                </a>
            @endif
        @endunless
    </nav>
    </div>
    @includeWhen(! ($isCalculatorRoute ?? false), 'livewire.dashboard.partials.recipe-workbench.output-actions')
    </div>
    @if (is_array($workbench['output'] ?? null) && ! ($isCalculatorRoute ?? false) && ! $isPublicCalculator)
        <div x-show="outputPending || outputError || outputFallbackUrl" x-cloak class="pb-2" aria-live="polite" aria-atomic="true">
            <p x-show="outputPending" x-cloak class="text-sm text-[var(--color-ink-soft)]" x-text="outputConfig?.messages?.pending"></p>
            <p x-show="outputError" x-cloak role="alert" class="text-sm text-[var(--color-danger-strong)]" x-text="outputError"></p>
            <a x-show="outputFallbackUrl" x-cloak :href="outputFallbackUrl" target="_blank" rel="noopener" class="text-sm font-medium text-[var(--color-accent-strong)] underline underline-offset-2" x-text="outputFallbackLabel"></a>
        </div>
    @endif
</div>
