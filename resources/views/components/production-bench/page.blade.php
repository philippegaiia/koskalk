@props([
    'purchasing' => false,
    'productionSetup' => false,
    'compact' => false,
])

<div data-production-bench-page class="mx-auto w-full max-w-7xl space-y-6">
    <x-production-bench.navigation />

    @if ($purchasing)
        <x-production-bench.purchasing-navigation />
    @elseif ($productionSetup || request()->routeIs('production-bench.production.settings*'))
        <x-production-bench.production-settings-navigation />
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
