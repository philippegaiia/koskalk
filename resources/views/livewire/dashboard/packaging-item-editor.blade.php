<div class="mx-auto w-full max-w-5xl space-y-6">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Packaging item</p>
 <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">
 {{ $packagingItem ? 'Refine the packaging record and keep it ready for costing.' : 'Create a reusable packaging item for recipe costing.' }}
 </h3>
 <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Packaging items stay private to your account and can be reused across formulas without retyping the same details every time.
 </p>
 </div>

 <div class="flex flex-col gap-3 sm:flex-row">
 <a href="{{ route('packaging-items.index') }}" wire:navigate class="inline-flex justify-center rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Back to packaging
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
 {{ $packagingItem ? 'Save packaging item' : 'Create packaging item' }}
 </button>
 </div>
 </form>

 <x-filament-actions::modals />
</div>
