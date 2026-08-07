@props([
    'purchasing' => false,
    'inventory' => false,
    'productionSetup' => false,
])

<div data-production-bench-page class="mx-auto w-full max-w-app space-y-6">
    <x-production-bench.navigation />

    @if ($inventory || request()->routeIs('production-bench.inventory*'))
        <x-production-bench.inventory-navigation />
    @elseif ($purchasing)
        <x-production-bench.purchasing-navigation />
    @elseif ($productionSetup || request()->routeIs('production-bench.production.settings*'))
        <x-production-bench.production-settings-navigation />
    @endif

    <div class="w-full space-y-8">
        {{ $slot }}
    </div>
</div>
