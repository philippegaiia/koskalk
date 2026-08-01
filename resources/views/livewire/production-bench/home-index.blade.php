<div class="mx-auto max-w-6xl space-y-8">
    <x-production-bench.navigation />

    <header class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
        <div>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Production Bench</h1>
        </div>

        <div class="sk-card p-5">
            @if ($isActive)
                <p class="text-sm font-semibold text-[var(--color-success-strong)]">Active</p>
                <button wire:click="cancel" type="button" class="mt-3 text-sm font-medium text-[var(--color-ink-soft)] underline decoration-[var(--color-line-strong)] underline-offset-4">Stop and keep records</button>
            @elseif ($isReadOnly)
                <p class="text-sm font-semibold text-[var(--color-warning-strong)]">Read-only</p>
                <button wire:click="resume" type="button" class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white">Resume</button>
            @else
                <p class="text-sm font-semibold text-[var(--color-ink-soft)]">Inactive</p>
                <button wire:click="activate" type="button" class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white">Activate Production Bench</button>
            @endif
        </div>
    </header>

    @if ($isActive || $isReadOnly)
        <section class="grid gap-px overflow-hidden rounded-2xl border border-[var(--color-line)] bg-[var(--color-line)] md:grid-cols-2">
            <a href="{{ route('production-bench.inventory') }}" wire:navigate class="bg-[var(--color-panel)] p-6 transition hover:bg-[var(--color-panel-muted)]">
                <p class="sk-eyebrow">Quarantined</p>
                <p class="mt-4 font-mono text-3xl text-[var(--color-ink-strong)]">{{ $quarantinedLots }}</p>
            </a>
            <a href="{{ route('production-bench.purchasing') }}" wire:navigate class="bg-[var(--color-panel)] p-6 transition hover:bg-[var(--color-panel-muted)]">
                <p class="sk-eyebrow">Incoming</p>
                <p class="mt-4 font-mono text-3xl text-[var(--color-ink-strong)]">{{ $incomingOrders }}</p>
            </a>
        </section>
    @endif
</div>
