@php($isCosmeticWorkbench = $isCosmeticWorkbench ?? false)

<div x-show="activeWorkbenchTab === 'formula'" class="space-y-6">
 @include('livewire.dashboard.partials.recipe-workbench.formula-settings')

 <section class="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
 @include('livewire.dashboard.partials.recipe-workbench.ingredient-browser')
 <div class="space-y-4">
 @if ($isCosmeticWorkbench)
 @include('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')
 @else
 @include('livewire.dashboard.partials.recipe-workbench.reaction-core')
 @include('livewire.dashboard.partials.recipe-workbench.post-reaction')
 @endif
 </div>

 @unless ($isCosmeticWorkbench)
 @include('livewire.dashboard.partials.recipe-workbench.formula-analysis')
 @endunless
 </section>
</div>
