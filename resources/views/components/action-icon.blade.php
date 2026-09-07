@props(['name'])

@php($paths = match ($name) {
    'drag' => '<path d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01" />',
    'info' => '<path d="M12 10.5v5M12 7.5h.01" />',
    'close' => '<path d="m7 7 10 10M17 7 7 17" />',
    'plus' => '<path d="M12 5v14M5 12h14" />',
    'minus' => '<path d="M5 12h14" />',
    'chevron-down' => '<path d="m6 9 6 6 6-6" />',
    default => '',
})

<svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->class(['size-4']) }}
>{!! $paths !!}</svg>
