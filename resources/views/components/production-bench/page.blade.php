@props([
    'purchasing' => false,
    'compact' => false,
])

<div data-production-bench-page class="mx-auto w-full max-w-7xl space-y-6">
    <x-production-bench.navigation />

    @if ($purchasing)
        <x-production-bench.purchasing-navigation />
    @endif

    <div @class([
        'w-full space-y-8',
        'mx-auto max-w-5xl' => $compact,
    ])>
        {{ $slot }}
    </div>
</div>
