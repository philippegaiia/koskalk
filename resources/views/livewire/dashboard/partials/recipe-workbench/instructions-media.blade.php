<section x-show="activeWorkbenchTab === 'instructions'" x-cloak role="tabpanel" aria-labelledby="tab-instructions" id="panel-instructions">
 <header class="border-b border-[var(--color-line)] pb-4">
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.instructions.title') }}</h3>
 <p class="mt-2 max-w-[75ch] text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workbench.instructions.intro') }}</p>
 </header>

 <form
 x-data="recipeContentAutosave({
     registry: dirtyStateRegistry,
     labels: @js([
         'allSaved' => __('workbench.instructions.all_saved'),
         'unsaved' => __('workbench.instructions.unsaved'),
         'saving' => __('workbench.instructions.saving'),
         'savedAt' => __('workbench.instructions.saved_at'),
         'saveFailed' => __('workbench.instructions.save_failed'),
     ]),
     livewireId: $wire.$id,
     uploadEventTarget: window,
     watch: (path, callback) => $wire.$watch(path, callback),
     save: () => $wire.saveRecipeContent(),
 })"
 @submit.prevent="save()"
 class="space-y-6 pb-24 pt-5"
 >
 @if (! $workbench['recipe'])
 <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workbench.instructions.draft_text_help') }}</p>
 @endif

 {{ $this->form }}

    <x-workflow-action-bar max-width="max-w-7xl" data-instructions-save-bar>
 <x-slot:leading>
 <p class="text-sm text-[var(--color-ink-soft)]" :class="state === 'failed' ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink-soft)]'" role="status" aria-live="polite" aria-atomic="true" x-text="statusText"></p>
 </x-slot:leading>

 @if ($workbench['recipe'])
 <button type="submit" :disabled="saveDisabled" class="sk-btn sk-btn-primary">
 <span x-show="state !== 'saving'">{{ __('workbench.instructions.save_changes') }}</span>
 <span x-show="state === 'saving'" x-cloak>{{ __('workbench.instructions.saving') }}</span>
 </button>
 @else
 <button type="button" disabled class="sk-btn sk-btn-primary">
 {{ __('workbench.instructions.save_changes') }}
 </button>
 @endif
 </x-workflow-action-bar>
 </form>
</section>
