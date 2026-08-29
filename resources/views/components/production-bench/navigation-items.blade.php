@props([
    'nodes' => [],
    'level' => 1,
    'path' => [],
])

@php($chunks = App\Support\ProductionBenchNavigation::groups($nodes))
@php($isGrouped = count($chunks) > 1)

{{--
    One row per level. Every visible tier in the hierarchy is rendered by this
    same partial, so a parent can never drift from its children in markup,
    classes, icon treatment, or aria semantics.

    `data-level` drives every visual difference in CSS and nothing here
    hard-codes a tier by position, but the *number* of tiers is fixed at two by
    `ProductionBenchNavigation::rows()` and `navigation.blade.php`, which emit
    and render levels 1 and 2 by index.
--}}
<div {{ $attributes->class(['sk-nav-row']) }} data-level="{{ $level }}">
    @foreach ($chunks as $chunkIndex => $chunk)
        @php($labelId = $isGrouped ? sprintf('sk-nav-group-%d-%d', $level, $chunkIndex) : null)

        <div
            class="sk-nav-cluster"
            @if ($labelId !== null)
                role="group"
                aria-labelledby="{{ $labelId }}"
            @endif
        >
            @if ($labelId !== null)
                <h3 class="sk-eyebrow sk-nav-group-label" id="{{ $labelId }}">{{ __($chunk['label']) }}</h3>
            @endif

            @foreach ($chunk['nodes'] as $node)
                @php($isCurrent = App\Support\ProductionBenchNavigation::isLeaf($path, $node['key']))
                @php($isBranch = ! $isCurrent && App\Support\ProductionBenchNavigation::isActive($path, $node['key']))

                <a
                    href="{{ route($node['route']) }}"
                    wire:navigate
                    data-level="{{ $level }}"
                    @if ($isCurrent)
                        aria-current="page"
                    @elseif ($isBranch)
                        aria-current="true"
                    @endif
                    @if (($node['aria'] ?? null) !== null)
                        aria-label="{{ __($node['aria']) }}"
                    @endif
                    @if ($node['end'] ?? false)
                        data-nav-end="true"
                    @endif
                    @if ($node['divider'] ?? false)
                        data-nav-divider="true"
                    @endif
                    @class([
                        'sk-nav-item',
                        'is-active' => $isCurrent,
                        'is-branch' => $isBranch,
                    ])
                >
                    <x-production-bench.navigation-icon :name="$node['icon']" :level="$level" />
                    <span class="sk-nav-label">{{ __($node['label']) }}</span>
                </a>
            @endforeach
        </div>
    @endforeach
</div>
