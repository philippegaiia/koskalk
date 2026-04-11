<div x-data="recipeWorkbench(@js($workbench))" x-init="init()" class="mx-auto max-w-[90rem] space-y-6">
 @include('livewire.dashboard.partials.recipe-workbench.header')
 @include('livewire.dashboard.partials.recipe-workbench.navigation')
 @include('livewire.dashboard.partials.recipe-workbench.formula-tab')
 @include('livewire.dashboard.partials.recipe-workbench.costing-tab')
 @include('livewire.dashboard.partials.recipe-workbench.output-tab')
 @include('livewire.dashboard.partials.recipe-workbench.instructions-media')

 <x-filament-actions::modals />
</div>
