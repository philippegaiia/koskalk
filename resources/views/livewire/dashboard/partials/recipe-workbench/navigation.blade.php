@php
    $isPublicCalculator = $isPublicCalculator ?? false;
    $tabBaseClass = 'inline-flex min-w-[9.5rem] shrink-0 items-center justify-center whitespace-nowrap rounded-lg px-4 py-3 text-center text-sm font-semibold transition xl:min-w-0 xl:px-5 xl:py-3.5 xl:text-base';
    $tabActiveClass = 'bg-[var(--color-panel)] text-[var(--color-ink-strong)] shadow-[inset_0_-3px_0_var(--color-active),0_1px_2px_rgba(60,50,30,0.05)]';
    $tabInactiveClass = 'bg-[color-mix(in_oklab,var(--color-panel)_74%,var(--color-surface)_26%)] text-[var(--color-ink-soft)] shadow-[inset_0_0_0_1px_color-mix(in_oklab,var(--color-line)_70%,transparent)] hover:bg-[var(--color-panel)] hover:text-[var(--color-ink-strong)]';
@endphp

<nav class="{{ $isPublicCalculator ? 'grid gap-2 sm:grid-cols-2' : '-mx-1 flex gap-2 overflow-x-auto px-1 pb-1 xl:mx-0 xl:grid xl:grid-cols-5 xl:overflow-visible xl:px-0 xl:pb-0' }}" role="tablist" aria-label="Workbench sections">
 <button
 id="tab-formula"
 role="tab"
 type="button"
 @click="activeWorkbenchTab = 'formula'"
	 :aria-selected="activeWorkbenchTab === 'formula'"
	 :class="activeWorkbenchTab === 'formula' ? @js($tabActiveClass) : @js($tabInactiveClass)"
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
	 :class="activeWorkbenchTab === 'packaging' ? @js($tabActiveClass) : @js($tabInactiveClass)"
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
	 :class="activeWorkbenchTab === 'costing' ? @js($tabActiveClass) : @js($tabInactiveClass)"
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
	 :class="activeWorkbenchTab === 'output' ? @js($tabActiveClass) : @js($tabInactiveClass)"
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
	 :class="activeWorkbenchTab === 'instructions' ? @js($tabActiveClass) : @js($tabInactiveClass)"
 class="{{ $tabBaseClass }}"
 >
 Instructions &amp; Media
 </button>
 @endunless
</nav>
