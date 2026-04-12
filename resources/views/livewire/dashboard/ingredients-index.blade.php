<div class="mx-auto w-full max-w-7xl space-y-6">
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5 sm:p-6">
 <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
 <div class="min-w-0">
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Personal library</p>
 <h3 class="mt-2 max-w-4xl text-xl font-semibold text-[var(--color-ink-strong)] sm:text-2xl">Search, sort, and maintain your ingredient catalog.</h3>
 <p class="mt-2 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">
 Keep the list efficient and application-like, with the full editor available when you open a record.
 </p>
 </div>

 <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex justify-center rounded-full border border-[var(--color-line)] px-5 py-2.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">
 Back to dashboard
 </a>
 </div>
 </section>

 @if (! $currentUser)
 <section class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-8 text-center">
 <h4 class="text-lg font-semibold text-[var(--color-ink-strong)]">Sign in to manage ingredients</h4>
 <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">Open the dashboard from your signed-in app or admin session to create and maintain private ingredients.</p>
 </section>
 @else
 <section class="overflow-hidden rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-0">
 {{ $this->table }}
 </section>

 @include('livewire.dashboard.partials.priced-ingredients-section')
 @endif
</div>
