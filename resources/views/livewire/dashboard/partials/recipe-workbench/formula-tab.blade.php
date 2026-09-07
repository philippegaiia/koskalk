@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<div x-show="activeWorkbenchTab === 'formula'" role="tabpanel" aria-labelledby="tab-formula" id="panel-formula" class="space-y-6 pb-40 sm:pb-28">
 @include('livewire.dashboard.partials.recipe-workbench.formula-settings')

 <section
 aria-label="{{ __('workbench.tabs.formula') }}"
 class="grid min-w-0 gap-4 @5xl/workbench:grid-cols-[19rem_minmax(0,1fr)] @5xl/workbench:gap-6 @7xl/workbench:gap-8"
 >
 <div class="order-1 min-w-0 @5xl/workbench:col-start-1 @5xl/workbench:row-start-1">
 <div class="space-y-4 @5xl/workbench:sticky @5xl/workbench:top-4 @5xl/workbench:self-start">
 <button type="button" data-ingredient-browser-disclosure @click="ingredientBrowserOpen = ! ingredientBrowserOpen" :aria-expanded="ingredientBrowserOpen.toString()" aria-controls="formula-ingredient-browser" class="flex w-full items-center justify-between gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-left text-sm font-semibold text-[var(--color-ink-strong)] @5xl/workbench:hidden">
 <span>{{ __('workbench.ingredients.title') }}</span>
 <span aria-hidden="true" class="text-lg leading-none text-[var(--color-ink-soft)]" x-text="ingredientBrowserOpen ? '−' : '+'"></span>
 </button>
 <div id="formula-ingredient-browser" x-ref="ingredientBrowserRail" x-cloak :class="ingredientBrowserOpen ? 'block' : 'hidden @5xl/workbench:block'">
 @include('livewire.dashboard.partials.recipe-workbench.ingredient-browser')
 </div>
 @unless ($isCosmeticWorkbench)
 <div class="hidden @5xl/workbench:block">
 @include('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')
 </div>
 @endunless
 </div>
 </div>
 <div class="order-2 min-w-0 space-y-4 @5xl/workbench:col-start-2 @5xl/workbench:row-start-1">
 @if ($isCosmeticWorkbench)
 @include('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')
 @else
 @include('livewire.dashboard.partials.recipe-workbench.reaction-core')
 @include('livewire.dashboard.partials.recipe-workbench.post-reaction')
 <div class="@5xl/workbench:hidden">
 @include('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')
 </div>
 @include('livewire.dashboard.partials.recipe-workbench.formula-analysis')
 @endif
 </div>
 </section>
 @include('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')
</div>
