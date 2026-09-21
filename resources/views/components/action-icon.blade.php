@props(['name'])

@php($paths = match ($name) {
    'drag' => '<path d="M8 6h.01M8 12h.01M8 18h.01M16 6h.01M16 12h.01M16 18h.01" />',
    'info' => '<path d="M12 10.5v5M12 7.5h.01" />',
    'close' => '<path d="m7 7 10 10M17 7 7 17" />',
    'plus' => '<path d="M12 5v14M5 12h14" />',
    'minus' => '<path d="M5 12h14" />',
    'more-horizontal' => '<path d="M6 12h.01M12 12h.01M18 12h.01" />',
    'chevron-down' => '<path d="m6 9 6 6 6-6" />',
    'chevron-right' => '<path d="m9 6 6 6-6 6" />',
    'pencil' => '<path d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />',
    'trash' => '<path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" />',
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
