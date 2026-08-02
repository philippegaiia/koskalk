@props([
    'purchasing' => false,
    'compact' => false,
])

<div data-production-bench-page class="mx-auto w-full max-w-7xl space-y-6">
    <x-production-bench.navigation />

    @if (session('production_bench_status'))
        <p role="status" class="rounded-xl bg-[var(--color-success-soft)] px-4 py-3 text-sm text-[var(--color-success-strong)]">{{ session('production_bench_status') }}</p>
    @endif

    @if ($purchasing)
        <x-production-bench.purchasing-navigation />
    @endif

    <div @class([
        'w-full',
        'space-y-6' => $compact,
        'space-y-8' => ! $compact,
        'mx-auto max-w-5xl' => $compact,
    ])>
        {{ $slot }}
    </div>
</div>
