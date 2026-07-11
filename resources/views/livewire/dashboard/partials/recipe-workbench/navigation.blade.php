@php
    $isPublicCalculator = $isPublicCalculator ?? false;
    $tabBaseClass = 'sk-workbench-tab inline-flex min-w-[9.5rem] shrink-0 items-center justify-center whitespace-nowrap rounded-lg px-4 py-2.5 text-center text-sm font-semibold xl:min-w-0 xl:px-5 xl:py-3 xl:text-base';
@endphp

<nav class="sk-workbench-tabs {{ $isPublicCalculator ? 'grid gap-2 sm:grid-cols-2' : '-mx-1 flex gap-2 overflow-x-auto xl:mx-0 xl:grid xl:grid-cols-5 xl:overflow-visible' }}" role="tablist" aria-label="Workbench sections">
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
 @endunless
</nav>
