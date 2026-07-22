<section x-show="activeWorkbenchTab === 'instructions'" x-cloak role="tabpanel" aria-labelledby="tab-instructions" id="panel-instructions">
 <header class="border-b border-[var(--color-line)] pb-4">
 <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.instructions.title') }}</h3>
 <p class="mt-2 max-w-[75ch] text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workbench.instructions.intro') }}</p>
 </header>

 <form wire:submit="saveRecipeContent" class="space-y-6 pb-6 pt-5">
 @if (! $workbench['recipe'])
 <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workbench.instructions.draft_text_help') }}</p>
 @endif

 {{ $this->form }}

 <div class="sticky bottom-3 z-30">
 <section id="instructions-media-save-bar" data-instructions-save-bar aria-live="polite" class="flex flex-col gap-3 rounded-[1rem] border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 shadow-[0_-8px_24px_rgba(60,50,30,0.10)] sm:flex-row sm:items-center sm:justify-between">
 <p class="text-sm text-[var(--color-ink-soft)]" role="status">
 @if ($recipeContentStatus === 'success')
 {{ __('workbench.instructions.all_saved') }}
 @elseif ($recipeContentStatus === 'error')
 {{ __('workbench.instructions.save_failed') }}
 @else
 {{ __('workbench.instructions.unsaved') }}
 @endif
 </p>

 @if ($workbench['recipe'])
 <button type="submit" wire:loading.attr="disabled" wire:target="saveRecipeContent" class="rounded-full bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-[var(--color-on-accent)] transition hover:bg-[var(--color-accent-hover)] disabled:cursor-wait disabled:opacity-70">
 <span wire:loading.remove wire:target="saveRecipeContent">{{ __('workbench.instructions.save_changes') }}</span>
 <span wire:loading wire:target="saveRecipeContent">{{ __('workbench.instructions.saving') }}</span>
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
