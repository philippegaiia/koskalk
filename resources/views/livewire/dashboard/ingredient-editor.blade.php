<div class="mx-auto w-full max-w-5xl space-y-6">
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Personal ingredient</p>
 <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">
 {{ $ingredient ? 'Refine the ingredient, its components, and optional aromatic compliance.' : 'Create the ingredient now, then enrich it on the next screen.' }}
 </h3>
 <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 User-created ingredients are always private by default and never become trusted soap-saponification oils automatically.
 </p>
 </div>

 <div class="flex flex-col gap-3 sm:flex-row">
 <a href="{{ route('ingredients.index') }}" wire:navigate class="inline-flex justify-center rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Back to ingredients
 </a>
 </div>
 </div>
 </section>

 <form wire:submit="save" class="space-y-4">
 @if ($statusMessage)
 <div class="{{ $statusType === 'success' ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' }} rounded-[1.5rem] border px-4 py-3 text-sm">
 {{ $statusMessage }}
 </div>
 @endif

 {{ $this->form }}

 <div class="flex justify-end">
 <button type="submit" class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)]">
 {{ $ingredient ? 'Save ingredient' : 'Create ingredient' }}
 </button>
 </div>
 </form>

 <x-filament-actions::modals />
</div>
