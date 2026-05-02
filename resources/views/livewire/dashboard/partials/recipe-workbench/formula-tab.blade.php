@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<div x-show="activeWorkbenchTab === 'formula'" role="tabpanel" aria-labelledby="tab-formula" id="panel-formula" class="space-y-6">
 @include('livewire.dashboard.partials.recipe-workbench.formula-settings')

 <section aria-label="Formula workspace" class="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
 @include('livewire.dashboard.partials.recipe-workbench.ingredient-browser')
 <div class="space-y-4">
 @if ($isCosmeticWorkbench)
 @include('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')
 @else
 @include('livewire.dashboard.partials.recipe-workbench.reaction-core')
 @include('livewire.dashboard.partials.recipe-workbench.post-reaction')
 @include('livewire.dashboard.partials.recipe-workbench.formula-analysis')
 @endif
 </div>
 </section>
</div>
