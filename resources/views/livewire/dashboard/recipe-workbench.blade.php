@php
    $isCosmeticWorkbench = ($workbench['productFamily']['slug'] ?? 'soap') === 'cosmetic';
    $isPublicCalculator = request()->routeIs('calculator') && ! (bool) ($workbench['canPersist'] ?? false);
@endphp

<div x-data="recipeWorkbench(@js($workbench))" x-init="init(); if (@js($isPublicCalculator) && ! ['formula', 'output'].includes(activeWorkbenchTab)) activeWorkbenchTab = 'formula'" @dragover.window="autoScrollDuringRowDrag($event)" class="sk-workbench @container/workbench mx-auto max-w-7xl space-y-6">
 @include('livewire.dashboard.partials.recipe-workbench.header')
 @include('livewire.dashboard.partials.recipe-workbench.navigation')
 <fieldset :disabled="isFormulaLocked" :class="isFormulaLocked ? 'opacity-75' : ''" class="space-y-6 transition">
 @include('livewire.dashboard.partials.recipe-workbench.formula-tab')
 @if ($isPublicCalculator)
 @include('livewire.dashboard.partials.recipe-workbench.output-tab')
 @else
 @include('livewire.dashboard.partials.recipe-workbench.packaging-tab')
 @include('livewire.dashboard.partials.recipe-workbench.costing-tab')
 @include('livewire.dashboard.partials.recipe-workbench.output-tab')
 @include('livewire.dashboard.partials.recipe-workbench.instructions-media')
 @include('livewire.dashboard.partials.recipe-workbench.packaging-catalog-modal')
 @endif
 </fieldset>

 <x-filament-actions::modals />
</div>
