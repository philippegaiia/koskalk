<section x-show="activeWorkbenchTab === 'instructions'" x-cloak class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
 <div class="border-b border-[var(--color-line)] px-5 py-4">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Content &amp; Media</p>
 <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Presentation, manufacturing steps, and product image</h3>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Keep customer-facing presentation separate from the print-ready manufacturing instructions.</p>
 </div>

 <div class="px-5 py-5">
 <form wire:submit="saveRecipeContent" class="space-y-4">
 @if ($recipeContentMessage)
 <div class="{{ $recipeContentStatus === 'success' ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' }} rounded-[1.5rem] border px-4 py-3 text-sm">
 {{ $recipeContentMessage }}
 </div>
 @endif

 @if (! $workbench['recipe'])
 <div class="rounded-lg bg-[var(--color-panel-strong)] px-4 py-4 text-sm text-[var(--color-ink-soft)]">
 You can prepare the presentation, manufacturing instructions, and image now. They will be kept when you save the first draft.
 </div>
 @endif

 {{ $this->form }}

 <div class="flex justify-end">
 @if ($workbench['recipe'])
 <button type="submit" class="rounded-full bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 Save content and media
 </button>
 @else
 <div class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2.5 text-sm font-medium text-[var(--color-ink-soft)]">
 Save the draft above to attach this content to the recipe.
 </div>
 @endif
 </div>
 </form>
 </div>
</section>
