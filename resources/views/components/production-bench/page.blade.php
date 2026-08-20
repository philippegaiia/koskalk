@props([
    'purchasing' => false,
    'inventory' => false,
    'productionSetup' => false,
    'inventorySection' => null,
    'active' => null,
    'subnavigation' => null,
])

@php($activeNavigation = $active ?? ($inventory ? 'inventory' : ($purchasing ? 'purchasing' : ($productionSetup ? 'production-setup' : null))))
@php($activeSubnavigation = $subnavigation ?? $inventorySection)

<div data-production-bench-page class="mx-auto w-full max-w-app space-y-6">
    <x-production-bench.navigation :active="$activeNavigation" />

    @if ($activeNavigation === 'inventory' || $inventory || request()->routeIs('production-bench.inventory*'))
        <x-production-bench.inventory-navigation :current="$activeSubnavigation" />
    @elseif ($activeNavigation === 'purchasing' || $purchasing)
        <x-production-bench.purchasing-navigation :current="$activeSubnavigation" />
    @elseif ($activeNavigation === 'production-setup' || $productionSetup || request()->routeIs('production-bench.production.settings*'))
        <x-production-bench.production-settings-navigation :current="$activeSubnavigation" />
    @endif

    <div class="w-full space-y-8">
        {{ $slot }}
    </div>
</div>
