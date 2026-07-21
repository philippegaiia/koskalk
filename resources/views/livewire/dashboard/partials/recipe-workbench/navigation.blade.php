@php
    $workbench = $workbench ?? [];
    $isPublicCalculator = $isPublicCalculator ?? false;
    $hasSavedFormula = (bool) ($workbench['recipe']['has_saved_formula'] ?? false);
    $savedFormulaUrl = $workbench['recipe']['saved_formula_url'] ?? null;
    $tabBaseClass = 'sk-workbench-tab inline-flex shrink-0 items-center whitespace-nowrap px-0 py-3 text-left text-base font-semibold';
@endphp

<div class="sk-workbench-navigation border-b border-[var(--color-line)]">
    <nav class="sk-workbench-tabs {{ $isPublicCalculator ? 'grid gap-2 sm:grid-cols-2' : 'flex min-w-max gap-7 overflow-x-auto xl:overflow-visible' }}" role="tablist" aria-label="Workbench sections">
        <button
            id="tab-formula"
            role="tab"
            type="button"
            @click="activeWorkbenchTab = 'formula'"
            :aria-selected="activeWorkbenchTab === 'formula'"
            :class="{ 'is-active': activeWorkbenchTab === 'formula' }"
            class="{{ $tabBaseClass }}"
        >
            Formula
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
                Packaging
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
                Costing
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
            Output
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
                Instructions &amp; Media
            </button>

            @if ($hasSavedFormula && is_string($savedFormulaUrl))
                <a href="{{ $savedFormulaUrl }}" class="sk-formula-sheet-link inline-flex shrink-0 items-center whitespace-nowrap py-3 text-base font-semibold text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]">
                    Formula sheet
                </a>
            @endif
        @endunless
    </nav>
</div>
