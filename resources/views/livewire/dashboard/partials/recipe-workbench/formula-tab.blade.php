@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<div x-show="activeWorkbenchTab === 'formula'" role="tabpanel" aria-labelledby="tab-formula" id="panel-formula" class="space-y-6 pb-28">
 @include('livewire.dashboard.partials.recipe-workbench.formula-settings')

 <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm" aria-live="polite">
  <span class="font-medium text-[var(--color-ink-strong)]" x-text="formulaItemLimit === null ? t(formulaItemCount() === 1 ? 'formula_items.count_singular' : 'formula_items.count_plural', { count: formulaItemCount() }) : t('formula_items.limited_count', { count: formulaItemCount(), limit: formulaItemLimit })"></span>
  <span x-show="formulaItemLimitReached() || formulaItemLimitMessage" x-cloak class="text-[var(--color-warning-strong)]" role="alert" x-text="formulaItemLimitMessage || t('formula_items.limit_reached', { limit: formulaItemLimit })"></span>
 </div>

 <section aria-label="{{ __('workbench.tabs.formula') }}" class="grid gap-4 @5xl/workbench:grid-cols-[19rem_minmax(0,1fr)] @5xl/workbench:gap-6 @7xl/workbench:gap-8">
 <div class="space-y-4 @5xl/workbench:sticky @5xl/workbench:top-4 @5xl/workbench:self-start">
 @include('livewire.dashboard.partials.recipe-workbench.ingredient-browser')
 @unless ($isCosmeticWorkbench)
 <div class="hidden @5xl/workbench:block">
 @include('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')
 </div>
 @endunless
 </div>
 <div class="space-y-4">
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
