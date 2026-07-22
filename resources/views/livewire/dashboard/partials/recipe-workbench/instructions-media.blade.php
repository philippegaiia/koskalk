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
     save: () => $wire.saveRecipeContent(),
 })"
 @submit.prevent="save()"
 class="space-y-6 pb-6 pt-5"
 >
 @if (! $workbench['recipe'])
 <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workbench.instructions.draft_text_help') }}</p>
 @endif

 {{ $this->form }}

 <div class="sticky bottom-3 z-30">
 <section id="instructions-media-save-bar" data-instructions-save-bar class="flex flex-col gap-3 rounded-[1rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 shadow-[0_-8px_24px_rgba(60,50,30,0.10)] sm:flex-row sm:items-center sm:justify-between">
 <p
 class="text-sm text-[var(--color-ink-soft)]"
 :class="state === 'failed' ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink-soft)]'"
 role="status"
 aria-live="polite"
 aria-atomic="true"
 x-text="statusText"
 >
 </p>

 @if ($workbench['recipe'])
 <button type="submit" :disabled="saveDisabled" class="rounded-full bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-[var(--color-on-accent)] transition hover:bg-[var(--color-accent-hover)] disabled:cursor-wait disabled:opacity-70">
 <span x-show="state !== 'saving'">{{ __('workbench.instructions.save_changes') }}</span>
 <span x-show="state === 'saving'" x-cloak>{{ __('workbench.instructions.saving') }}</span>
 </button>
 @else
 <button type="button" disabled class="cursor-not-allowed rounded-full bg-[var(--color-field-muted)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)]">
 {{ __('workbench.instructions.save_changes') }}
 </button>
 @endif
 </section>
 </div>
 </form>
</section>
