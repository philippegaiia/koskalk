@php
    $isCosmeticWorkbench = ($workbench['productFamily']['slug'] ?? 'soap') === 'cosmetic';
    $isPublicCalculator = request()->routeIs('calculator') && ! (bool) ($workbench['canPersist'] ?? false);
    $contextualHelp = $workbench['contextualHelp'] ?? ['topics' => [], 'tabs' => []];
    unset($workbench['contextualHelp']);
@endphp

<div x-data="recipeWorkbench(@js($workbench))" x-init="if (@js($isPublicCalculator) && ! ['formula', 'output'].includes(activeWorkbenchTab)) activeWorkbenchTab = 'formula'" x-effect="if (packagingCatalogModalOpen || isIfraCategoryModalOpen || pendingCosmeticPhaseRemoval || editingTakeoverOpen) $dispatch('contextual-help:modal')" @editing-updated.window="handleEditingUpdated($event.detail.editing)" @dragover.window="autoScrollDuringRowDrag($event)" @drop.window="endRowDrag()" @dragend.window="endRowDrag()" @blur.window="stopRowDragScroll()" @dragleave.document="leaveRowDragDocument($event)" class="sk-workbench @container/workbench mx-auto max-w-app space-y-6">
 <script type="application/json" data-contextual-help-scope>{!! \Illuminate\Support\Js::encode($contextualHelp) !!}</script>
 <div class="space-y-4">
 @include('livewire.dashboard.partials.recipe-workbench.header')
 @error('expected_revision')
 <p role="alert" class="rounded-lg bg-[var(--color-warning-soft)] p-3 text-sm text-[var(--color-warning-strong)]">{{ $message }}</p>
 @enderror
 <section x-show="editingRequired && isEditingUnavailable" x-cloak aria-live="polite" class="space-y-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] p-4 text-sm text-[var(--color-warning-strong)]">
     <p x-show="editingStatus === 'acquiring'">{{ __('workbench.editing.acquiring') }}</p>
     <p x-show="editingStatus === 'blocked'" x-text="t('editing.blocked', { name: editingHolderName })"></p>
     <p x-show="editingStatus === 'available'">{{ __('workbench.editing.available') }}</p>
     <p x-show="editingMessage" x-text="editingMessage"></p>
     <p x-show="editingStatus !== 'acquiring'">{{ __('workbench.editing.preserved') }}</p>
     <div class="flex flex-wrap gap-2">
         <button type="button" x-show="!editingStale && ['available', 'lost'].includes(editingStatus)" @click="retryEditing()" class="sk-btn sk-btn-outline">{{ __('workbench.editing.retry') }}</button>
         <button type="button" x-show="editingStatus === 'stale'" @click="reloadRecipeWithConfirmation()" class="sk-btn sk-btn-outline">{{ __('workbench.editing.reload') }}</button>
         <button type="button" x-show="canTakeOverEditing" @click="openEditingTakeover()" class="sk-btn sk-btn-outline">{{ __('workbench.editing.takeover') }}</button>
     </div>
     <form x-show="editingTakeoverOpen" @submit.prevent="confirmEditingTakeover()" class="space-y-3 border-t border-[var(--color-line)] pt-3">
         <p>{{ __('workbench.editing.takeover_help') }}</p>
         <label class="block">
             <span class="font-medium">{{ __('workbench.editing.reason') }}</span>
             <input x-model="editingTakeoverReason" type="text" required maxlength="1000" class="mt-1 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-[var(--color-ink-strong)]">
         </label>
         <div class="flex flex-wrap gap-2">
             <button type="submit" :disabled="!canTakeOverEditing || !editingTakeoverReason.trim()" class="sk-btn sk-btn-outline">{{ __('workbench.editing.confirm_takeover') }}</button>
             <button type="button" @click="cancelEditingTakeover()" class="sk-btn sk-btn-ghost">{{ __('workbench.editing.cancel') }}</button>
         </div>
     </form>
 </section>
 @include('livewire.dashboard.partials.recipe-workbench.navigation')
 </div>
 @include('livewire.dashboard.partials.recipe-workbench.formula-tab')
 <fieldset x-show="activeWorkbenchTab !== 'formula'" @if (! $isPublicCalculator && ! $canEditRecipe) disabled @endif :disabled="!canWriteRecipe || (isSaving && !hasSavedRecipe)" :inert="isSaving && !hasSavedRecipe" :class="!canWriteRecipe ? 'opacity-75' : ''" class="space-y-6 transition">
 @if ($isPublicCalculator)
 @include('livewire.dashboard.partials.recipe-workbench.output-tab')
 @else
 @include('livewire.dashboard.partials.recipe-workbench.packaging-tab')
 @include('livewire.dashboard.partials.recipe-workbench.output-tab')
 @include('livewire.dashboard.partials.recipe-workbench.instructions-media')
 @include('livewire.dashboard.partials.recipe-workbench.packaging-catalog-modal')
 @endif
 </fieldset>

 @unless ($isPublicCalculator)
 <fieldset @if (! $canEditRecipe) disabled @endif :disabled="!canAdjustCosting || (isSaving && !hasSavedRecipe)" :inert="isSaving && !hasSavedRecipe" :class="!canAdjustCosting ? 'opacity-75' : ''" class="space-y-6 transition" data-costing-controls>
 @include('livewire.dashboard.partials.recipe-workbench.costing-tab')
 </fieldset>
 @endunless

 <x-filament-actions::modals />
</div>
