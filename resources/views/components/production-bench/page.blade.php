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

{{--
    The boolean props are kept because some views still pass them; they now only
    seed the same `active` key the tree resolves on. The section-navigation
    chain is gone — the tree decides which second row to render.
--}}
<div data-production-bench-page class="mx-auto w-full max-w-app space-y-6">
    <x-production-bench.navigation
        :active="$activeNavigation"
        :subnavigation="$activeSubnavigation"
    />

    <div class="w-full space-y-8">
        {{ $slot }}
    </div>
</div>
