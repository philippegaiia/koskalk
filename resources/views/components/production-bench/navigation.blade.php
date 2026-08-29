@props([
    'active' => null,
    'subnavigation' => null,
])

@php($resolved = App\Support\ProductionBenchNavigation::resolve($active, $subnavigation))

{{--
    Thin wrapper: resolve the trail, render level 1, then hang level 2 off it
    inside the recessed rail. No horizontal scroll container and no negative
    margin overlap — the row wraps instead.

    Level 2 always renders for a group, so the navigation no longer changes
    height between sections.
--}}
<nav class="sk-nav" aria-label="{{ __('production_bench.title') }}">
    <x-production-bench.navigation-items
        :nodes="$resolved['rows'][1]"
        :level="1"
        :path="$resolved['path']"
    />

    @isset($resolved['rows'][2])
        <x-production-bench.navigation-items
            :nodes="$resolved['rows'][2]"
            :level="2"
            :path="$resolved['path']"
            class="sk-nav-rail"
        />
    @endisset
</nav>
