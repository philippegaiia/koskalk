@props(['controls', 'count' => 0])

<button
    type="button"
    {{ $attributes->class('sk-btn sk-btn-outline shrink-0 gap-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]') }}
    aria-controls="{{ $controls }}"
    x-bind:aria-expanded="filtersOpen.toString()"
    x-bind:style="filtersOpen || {{ $count > 0 ? 'true' : 'false' }} ? 'border-color: var(--color-accent); background: var(--color-accent-soft); color: var(--color-ink-strong)' : ''"
    x-on:click="filtersOpen = ! filtersOpen"
>
    <x-heroicon-o-funnel class="size-4" aria-hidden="true" />
    <span>{{ __('production_bench.common.filters') }}</span>
    @if ($count > 0)
        <span data-filter-count="{{ $count }}" class="inline-flex min-w-5 items-center justify-center rounded-full bg-[var(--color-accent)] px-1.5 text-xs font-semibold leading-5 text-[var(--color-on-accent)]">{{ $count }}</span>
    @endif
    <x-heroicon-m-chevron-down class="size-4 transition-transform" x-bind:class="{ 'rotate-180': filtersOpen }" aria-hidden="true" />
</button>
