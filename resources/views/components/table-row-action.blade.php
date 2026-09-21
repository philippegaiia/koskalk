@props(['icon', 'label', 'href' => null])

@php
    $actionAttributes = $attributes->class([
        'inline-flex size-11 shrink-0 items-center justify-center rounded-md text-[var(--color-ink-muted)] transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 disabled:pointer-events-none disabled:opacity-50',
        'hover:bg-[var(--color-danger-soft)] hover:text-[var(--color-danger-strong)] focus-visible:outline-[var(--color-danger-strong)]' => $icon === 'trash',
        'hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-accent-strong)] focus-visible:outline-[var(--color-accent)]' => $icon !== 'trash',
    ])->merge(['aria-label' => $label, 'title' => $label]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $actionAttributes }}>
        <x-action-icon :name="$icon" />
    </a>
@else
    <button type="button" {{ $actionAttributes }}>
        <x-action-icon :name="$icon" />
    </button>
@endif
