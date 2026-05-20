@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<div x-show="activeWorkbenchTab === 'formula'" role="tabpanel" aria-labelledby="tab-formula" id="panel-formula" class="space-y-6 pb-28">
 @include('livewire.dashboard.partials.recipe-workbench.formula-settings')

 <section aria-label="Formula workspace" class="grid gap-4 xl:grid-cols-[19rem_minmax(0,1fr)] 2xl:grid-cols-[22rem_minmax(0,1fr)]">
 <div class="space-y-4">
 @include('livewire.dashboard.partials.recipe-workbench.ingredient-browser')
 @unless ($isCosmeticWorkbench)
 <div class="hidden xl:block">
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
 <div class="xl:hidden">
 @include('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')
 </div>
 @include('livewire.dashboard.partials.recipe-workbench.formula-analysis')
 @endif
 </div>
 </section>
 @include('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')
</div>
