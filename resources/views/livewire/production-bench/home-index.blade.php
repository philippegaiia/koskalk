<x-production-bench.page active="home">
    <header class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem] lg:items-end">
        <div>
            <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">{{ __('production_bench.title') }}</h1>
        </div>

        <div class="sk-card p-5">
            @if ($isActive)
                <p class="text-sm font-semibold text-[var(--color-success-strong)]">{{ __('production_bench.common.active') }}</p>
                <button wire:click="cancel" type="button" class="mt-3 text-sm font-medium text-[var(--color-ink-soft)] underline decoration-[var(--color-line-strong)] underline-offset-4">{{ __('production_bench.home.stop') }}</button>
            @elseif ($isReadOnly)
                <p class="text-sm font-semibold text-[var(--color-warning-strong)]">{{ __('production_bench.home.read_only') }}</p>
                <button wire:click="resume" type="button" class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white">{{ __('production_bench.home.resume') }}</button>
            @else
                <p class="text-sm font-semibold text-[var(--color-ink-soft)]">{{ __('production_bench.common.inactive') }}</p>
                <button wire:click="activate" type="button" class="mt-4 rounded-full bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white">{{ __('production_bench.home.activate') }}</button>
            @endif
        </div>
    </header>

    @if ($isActive || $isReadOnly)
        <section class="grid gap-4 md:grid-cols-2">
            <a href="{{ route('production-bench.inventory') }}" wire:navigate class="sk-card p-5 transition hover:shadow-lg">
                <p class="sk-eyebrow">{{ __('production_bench.home.quarantined') }}</p>
                <p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $quarantinedLots }}</p>
            </a>
            <a href="{{ route('production-bench.purchasing') }}" wire:navigate class="sk-card p-5 transition hover:shadow-lg">
                <p class="sk-eyebrow">{{ __('production_bench.home.incoming') }}</p>
                <p class="mt-4 font-mono text-4xl text-[var(--color-ink-strong)]">{{ $incomingOrders }}</p>
            </a>
        </section>
    @endif
</x-production-bench.page>
