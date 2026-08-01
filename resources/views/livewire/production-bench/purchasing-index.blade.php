<div class="mx-auto w-full max-w-5xl space-y-6">
    <x-production-bench.navigation />
    <x-production-bench.purchasing-navigation />

    <header>
        <h1 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Purchasing</h1>
    </header>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('production-bench.purchasing.suppliers') }}" wire:navigate class="sk-btn sk-btn-primary">Suppliers</a>
        <a href="{{ route('production-bench.purchasing.listings') }}" wire:navigate class="sk-btn">Supplier listings</a>
    </div>
</div>
