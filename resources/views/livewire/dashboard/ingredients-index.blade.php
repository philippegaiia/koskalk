<div class="mx-auto w-full max-w-7xl space-y-6">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Ingredients</p>
 <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Use platform ingredients or maintain your own.</h3>
 <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Platform ingredients are shared reference records. Add your own price/kg for costing, duplicate one when you need an editable copy, or create a private ingredient from scratch.
 </p>
 </div>

 <div class="flex flex-wrap items-center gap-3 lg:justify-end">
 @include('livewire.dashboard.partials.duplicate-ingredient-modal')

 <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Back to dashboard
 </a>
 </div>
 </div>
 </section>

 @if (! $currentUser)
 <section class="sk-card p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage ingredients</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to create and maintain private ingredients.</p>
 </section>
 @else
 <section class="overflow-hidden sk-card p-0">
 <div class="flex flex-col gap-3 border-b border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
 <div>
 <p class="text-sm font-medium text-[var(--color-ink-strong)]">Show</p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]">Switch between shared platform records and private ingredients.</p>
 </div>

 <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="Ingredient catalog filter">
 @foreach ($this->ownershipFilterOptions() as $filterValue => $filterLabel)
 <button
 type="button"
 role="radio"
 aria-checked="{{ $ownershipFilter === $filterValue ? 'true' : 'false' }}"
 wire:click="setOwnershipFilter('{{ $filterValue }}')"
 class="{{ $ownershipFilter === $filterValue ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]' : 'border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-strong)]' }} rounded-full border px-4 py-2 text-sm font-medium transition"
 >
 {{ $filterLabel }}
 </button>
 @endforeach
 </div>
 </div>

 {{ $this->table }}
 </section>

 @endif
</div>
