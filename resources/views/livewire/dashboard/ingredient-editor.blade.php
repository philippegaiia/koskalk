<div class="mx-auto w-full max-w-5xl space-y-6">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Personal ingredient</p>
 <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">
 {{ $ingredient ? 'Refine the ingredient, its components, and optional aromatic compliance.' : 'Create the ingredient now, then enrich it on the next screen.' }}
 </h3>
 <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Personal ingredients stay private to your workspace. They can be used in formulas and cosmetic phases, but new carrier oils do not become trusted soap oils automatically.
 </p>

 <div class="mt-4 max-w-3xl rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-warning-strong)]">
 <p class="font-medium text-[var(--color-ink-strong)]">Carrier oils and soap calculation</p>
 <p class="mt-1">
 To use a carrier oil in the soap reaction core, duplicate a platform carrier oil first, then adjust it. A carrier oil created from scratch stays available as an ingredient, but it is not used for saponification math.
 </p>
 </div>
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
