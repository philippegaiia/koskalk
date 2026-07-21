@php
 $isPlatformIngredient = $ingredient !== null && $ingredient->owner_type === null;
 $ingredientContext = $ingredient?->display_name ?: 'New ingredient';
 $isCarrierOil = ($data['category'] ?? null) === \App\IngredientCategory::CarrierOil->value;
@endphp

<div class="mx-auto w-full max-w-5xl space-y-6">
 <section aria-labelledby="ingredient-editor-title">
 <nav aria-label="Breadcrumb" class="flex min-h-10 flex-wrap items-center gap-2 text-sm font-medium text-[var(--color-ink-soft)]">
 <a href="{{ route('ingredients.index') }}" wire:navigate class="inline-flex min-h-10 items-center rounded-md text-[var(--color-accent-strong)] transition hover:text-[var(--color-accent-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]">
 Ingredients
 </a>
 <span aria-hidden="true" class="text-[var(--color-line-strong)]">/</span>
 <span aria-current="page" class="min-w-0 truncate">{{ $ingredientContext }}</span>
 </nav>

 <div class="mt-3 max-w-3xl">
 <p class="sk-eyebrow">{{ $isPlatformIngredient ? 'Platform ingredient' : 'Personal ingredient' }}</p>
 <h1 id="ingredient-editor-title" class="mt-2 text-3xl font-semibold tracking-tight text-[var(--color-ink-strong)]">
 {{ $isPlatformIngredient ? 'Ingredient reference' : ($ingredient ? 'Edit personal ingredient' : 'Create a personal ingredient') }}
 </h1>
 <p class="mt-2 max-w-[70ch] text-sm leading-6 text-[var(--color-ink-soft)]">
 @if ($isPlatformIngredient)
 This record is maintained by Soapkraft. You can review its identity, chemistry, and regulatory references, but it cannot be changed here.
 @elseif ($ingredient)
 Keep the identity and technical details your workspace uses in formulas up to date.
 @else
 Add the essential details first. Composition, soap chemistry, and compliance become available when they are relevant.
 @endif
 </p>

 @if (! $isPlatformIngredient && $isCarrierOil)
 <aside class="mt-4 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-4 py-3 text-sm leading-6 text-[var(--color-warning-strong)]" aria-labelledby="carrier-oil-guidance-title">
 <p id="carrier-oil-guidance-title" class="font-medium text-[var(--color-ink-strong)]">Using this oil in soap calculations</p>
 <p class="mt-1">A carrier oil created from scratch can be used as an ingredient, but not in saponification math. For soap calculations, duplicate a platform carrier oil and adjust that copy.</p>
 </aside>
 @endif
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

 <form wire:submit="save" class="space-y-4 pb-24">
 @if ($statusMessage)
 <div class="{{ $statusType === 'success' ? 'border-[var(--color-success-soft)] bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' : 'border-[var(--color-danger-soft)] bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' }} rounded-[1.5rem] border px-4 py-3 text-sm">
 {{ $statusMessage }}
 </div>
 @endif

 {{ $this->form }}

 @unless ($isPlatformIngredient)
 <div
 data-ingredient-save-bar
 class="pointer-events-none fixed bottom-0 left-0 right-0 z-10 px-4 pb-[max(1rem,env(safe-area-inset-bottom))] lg:left-[var(--app-sidebar-width,0rem)]"
 >
 <div class="mx-auto flex max-w-5xl justify-end">
 <button
 type="submit"
 wire:loading.attr="disabled"
 wire:target="save"
 class="pointer-events-auto sk-btn sk-btn-primary shadow-[0_8px_20px_rgba(60,50,30,0.16)]"
 >
 {{ $ingredient ? 'Save ingredient' : 'Create ingredient' }}
 </button>
 </div>
 </div>
 @endunless
 </form>

 <x-filament-actions::modals />
</div>
