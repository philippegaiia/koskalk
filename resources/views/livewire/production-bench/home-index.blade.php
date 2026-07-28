<div class="mx-auto max-w-6xl space-y-8">
    <x-production-bench.navigation />

    <header class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
        <div>
            <p class="sk-eyebrow">Optional professional workspace</p>
            <h1 class="mt-3 font-serif text-4xl text-[var(--color-ink-strong)]">Production without the ERP headache.</h1>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">Plan purchases, record real deliveries, and know what is physically present versus actually available.</p>
        </div>

        <div class="sk-card p-5">
            @if ($isActive)
                <p class="text-sm font-semibold text-[var(--color-success-strong)]">Bench is active</p>
                <button wire:click="cancel" type="button" class="mt-3 text-sm font-medium text-[var(--color-ink-soft)] underline decoration-[var(--color-line-strong)] underline-offset-4">Stop and keep records</button>
            @elseif ($isReadOnly)
                <p class="text-sm font-semibold text-[var(--color-warning-strong)]">Read-only</p>
                <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">Your history remains available. Resume whenever you need it.</p>
                <button wire:click="resume" type="button" class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white">Resume Production Bench</button>
            @else
                <p class="text-sm font-semibold text-[var(--color-ink-strong)]">Ready when your production grows</p>
                <button wire:click="activate" type="button" class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white">Activate Production Bench</button>
            @endif
        </div>
    </header>

    @if ($isActive || $isReadOnly)
        <section class="grid gap-px overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-line)] md:grid-cols-3">
            <a href="{{ route('production-bench.inventory') }}" wire:navigate class="bg-[var(--color-panel)] p-6 transition hover:bg-[var(--color-panel-muted)]">
                <p class="sk-eyebrow">Stock attention</p>
                <p class="mt-4 font-mono text-3xl text-[var(--color-ink-strong)]">{{ $quarantinedLots }}</p>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">lots waiting for release</p>
            </a>
            <a href="{{ route('production-bench.purchasing') }}" wire:navigate class="bg-[var(--color-panel)] p-6 transition hover:bg-[var(--color-panel-muted)]">
                <p class="sk-eyebrow">On the way</p>
                <p class="mt-4 font-mono text-3xl text-[var(--color-ink-strong)]">{{ $incomingOrders }}</p>
                <p class="mt-2 text-sm text-[var(--color-ink-soft)]">orders still incoming</p>
            </a>
            <div class="bg-[var(--color-panel)] p-6">
                <p class="sk-eyebrow">Next</p>
                <p class="mt-4 text-base font-semibold text-[var(--color-ink-strong)]">Production runs</p>
                <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Planning, reservations and batch traceability arrive in the next checkpoint.</p>
            </div>
        </section>
    @endif
</div>
