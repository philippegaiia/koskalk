<div class="mx-auto w-full max-w-7xl space-y-6">
 <section class="sk-card p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
 <div class="min-w-0">
 <p class="sk-eyebrow">Packaging items</p>
 <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Manage packaging used in recipe costing.</h3>
 <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Add boxes, jars, labels, inserts, and other reusable packaging with a unit price. Saved items can be selected in recipe packaging and costing instead of retyped.
 </p>
 </div>

 <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center whitespace-nowrap rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Back to dashboard
 </a>
 </div>
 </section>

 @if (! $currentUser)
 <section class="sk-card p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage packaging items</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">
 Open the dashboard from your signed-in app or admin session to create and reuse packaging items.
 </p>
 </section>
 @else
 <section class="overflow-hidden sk-card p-0">
 {{ $this->table }}
 </section>
 @endif
</div>
