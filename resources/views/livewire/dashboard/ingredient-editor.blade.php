@php($isPlatformIngredient = $ingredient !== null && $ingredient->owner_type === null)

<div class="mx-auto w-full max-w-5xl space-y-6">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">{{ $isPlatformIngredient ? 'Platform ingredient' : 'Personal ingredient' }}</p>
 <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">
 {{ $isPlatformIngredient ? 'Read-only reference' : ($ingredient ? 'Refine the ingredient, its components, and optional aromatic compliance.' : 'Create the ingredient now, then enrich it on the next screen.') }}
 </h3>
 <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 @if ($isPlatformIngredient)
 This platform record is maintained by Soapkraft administrators. You can inspect its identity, composition, chemistry, allergens, and regulatory references, but it cannot be changed from your workspace.
 @else
 Personal ingredients stay private to your workspace. They can be used in formulas and cosmetic phases, but new carrier oils do not become trusted soap oils automatically.
 @endif
 </p>

 @unless ($isPlatformIngredient)
 <div class="mt-4 max-w-3xl rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-warning-strong)]">
 <p class="font-medium text-[var(--color-ink-strong)]">Carrier oils and soap calculation</p>
 <p class="mt-1">
 To use a carrier oil in the soap reaction core, duplicate a platform carrier oil first, then adjust it. A carrier oil created from scratch stays available as an ingredient, but it is not used for saponification math.
 </p>
 </div>
 @endunless
 </div>

 <div class="flex flex-col gap-3 sm:flex-row">
 <a href="{{ route('ingredients.index') }}" wire:navigate class="inline-flex justify-center rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Back to ingredients
 </a>
 </div>
 </div>
 </section>

 @if ($isPlatformIngredient)
 <section class="sk-card p-5 sm:p-6" aria-labelledby="platform-regulatory-summary">
 <p class="sk-eyebrow">Regulatory identity</p>
 <h4 id="platform-regulatory-summary" class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">Identifiers and declared allergens</h4>
 <dl class="mt-5 grid gap-4 sm:grid-cols-2">
 <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
 <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">INCI</dt>
 <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $ingredient->inci_name ?: 'Not available' }}</dd>
 </div>
 <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
 <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">CAS number</dt>
 <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $ingredient->cas_number ?: 'Not available' }}</dd>
 </div>
 <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3">
 <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">EINECS / EC number</dt>
 <dd class="mt-1 text-sm font-medium text-[var(--color-ink-strong)]">{{ $ingredient->ec_number ?: 'Not available' }}</dd>
 </div>
 <div class="rounded-lg bg-[var(--color-field-muted)] px-4 py-3 sm:col-span-2">
 <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-ink-soft)]">Declared allergens</dt>
 <dd class="mt-2">
 @if ($ingredient->allergenEntries->isEmpty())
 <span class="text-sm text-[var(--color-ink-soft)]">No allergen composition is recorded.</span>
 @else
 <ul class="flex flex-wrap gap-2">
 @foreach ($ingredient->allergenEntries as $entry)
 <li class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-3 py-1.5 text-sm text-[var(--color-ink-strong)]">
 {{ $entry->allergen?->inci_name ?? 'Unknown allergen' }}
 @if ($entry->concentration_percent !== null)
 <span class="text-[var(--color-ink-soft)]">· {{ rtrim(rtrim(number_format((float) $entry->concentration_percent, 5, '.', ''), '0'), '.') }}%</span>
 @endif
 </li>
 @endforeach
 </ul>
 @endif
 </dd>
 </div>
 </dl>
 </section>
 @endif

 <form wire:submit="save" class="space-y-4 pb-2">
 @if ($statusMessage)
 <div class="{{ $statusType === 'success' ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' }} rounded-[1.5rem] border px-4 py-3 text-sm">
 {{ $statusMessage }}
 </div>
 @endif

 {{ $this->form }}

 @unless ($isPlatformIngredient)
 <div
 data-ingredient-save-bar
 class="sticky bottom-0 z-30 -mx-2 flex justify-end border-t border-[var(--color-line)] bg-[var(--color-surface)] px-2 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:-mx-4 sm:px-4"
 >
 <button
 type="submit"
 wire:loading.attr="disabled"
 wire:target="save"
 class="w-full rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-[var(--color-on-accent)] transition hover:bg-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-65 sm:w-auto"
 >
 {{ $ingredient ? 'Save ingredient' : 'Create ingredient' }}
 </button>
 </div>
 @endunless
 </form>

 <x-filament-actions::modals />
</div>
