@props(['name', 'level' => 1])

{{--
    One inline stroke icon per navigation node. Kept here rather than in PHP so
    the path data stays in the view layer and the tree stays pure structure.

    Every icon shares the same 24x24 box, `currentColor` stroke and round caps
    so icons and labels form two clean vertical columns at each level.
--}}
@php($paths = match ($name) {
    'dashboard' => '<path d="M3 10.6 12 3.4l9 7.2"/><path d="M5.4 9.6V20.6h13.2V9.6"/><path d="M9.8 20.6v-5.2h4.4v5.2"/>',
    'inventory' => '<path d="M12 3.2 3 7.6v8.8L12 20.8l9-4.4V7.6z"/><path d="m3 7.6 9 4.4 9-4.4M12 12v8.8"/>',
    'materials' => '<rect x="4.8" y="4.6" width="14.4" height="16" rx="2.2"/><path d="M9.4 4.6V3.2h5.2v1.4"/><path d="M8.8 10h6.4M8.8 14h6.4M8.8 17.6h3.4"/>',
    'stock' => '<path d="M12 3.4 3.4 7.7 12 12l8.6-4.3z"/><path d="m3.4 12.4 8.6 4.3 8.6-4.3"/><path d="m3.4 16.8 8.6 4.3 8.6-4.3"/>',
    'production' => '<path d="M3.2 20.8V9.6l6 3.7V9.6l6 3.7V9.6l5.6 3.4v7.8z"/><path d="M6.6 16.8h2.2M10.8 16.8h2.2M15 16.8h2.2"/>',
    'runs' => '<path d="M8.4 6.4h12M8.4 12h12M8.4 17.6h12"/><path d="M3.8 6.4h.01M3.8 12h.01M3.8 17.6h.01"/>',
    'tasks' => '<rect x="3.6" y="3.6" width="16.8" height="16.8" rx="3"/><path d="m8.2 12.4 2.7 2.7 5-5.4"/>',
    'flash' => '<path d="M13.6 3.2 5.4 13.4h4.9L8.8 20.8 17 10.4h-4.9z"/>',
    'calendar' => '<rect x="3.4" y="5" width="17.2" height="15.6" rx="2.4"/><path d="M3.4 9.8h17.2M8.2 3.2v3.4M15.8 3.2v3.4"/><path d="M7.6 13.4h1.8M11.1 13.4h1.8M14.6 13.4h1.8M7.6 17h1.8M11.1 17h1.8M14.6 17h1.8"/>',
    'purchasing' => '<path d="M2.6 3.8h2.3l2.3 11.3h10.6"/><path d="M6.3 7.6h14.4l-1.8 6.1H8.3"/><circle cx="9.2" cy="19.2" r="1.7"/><circle cx="16.8" cy="19.2" r="1.7"/>',
    'suppliers' => '<path d="M3.6 9.6 5.2 4.4h13.6l1.6 5.2"/><path d="M3.6 9.6V20.6h16.8V9.6"/><path d="M3.6 9.6a3.1 3.1 0 0 1 5.8 0 3.1 3.1 0 0 1 5.2 0 3.1 3.1 0 0 1 5.8 0"/><path d="M9.8 20.6v-5.2h4.4v5.2"/>',
    'listings' => '<path d="M11.2 3.6H5a1.6 1.6 0 0 0-1.6 1.6v6.2a2 2 0 0 0 .59 1.41l8.2 8.2a2 2 0 0 0 2.83 0l6.19-6.19a2 2 0 0 0 0-2.83l-8.2-8.2a2 2 0 0 0-1.41-.59z"/><path d="M7.6 7.6h.01"/>',
    'quotations' => '<path d="M13.4 3.2H6.8a2 2 0 0 0-2 2v13.6a2 2 0 0 0 2 2h10.4a2 2 0 0 0 2-2V8.2z"/><path d="M13.4 3.2v5h5.8"/><path d="M8.6 13h6.8M8.6 16.6h4"/>',
    'orders' => '<path d="M2.6 6.4h10.8v10.2H2.6z"/><path d="M13.4 9.6h3.9l3.1 3.3v3.7h-7z"/><circle cx="6.6" cy="18.2" r="1.8"/><circle cx="16.8" cy="18.2" r="1.8"/>',
    'receipts' => '<path d="M5 3.6h14v16.8l-2.33-1.4-2.34 1.4-2.33-1.4-2.34 1.4L7.33 18.6 5 20.4z"/><path d="M8.8 8.4h6.4M8.8 12.4h6.4"/>',
    'settings' => '<path d="M4 7.2h9.6M18.4 7.2H20M4 16.8h1.6M9.6 16.8H20"/><circle cx="15.8" cy="7.2" r="2.3"/><circle cx="7.6" cy="16.8" r="2.3"/>',
    'numbering' => '<path d="M4.8 9.2h14.4M4.8 14.8h14.4M10.2 3.6 8.6 20.4M15.4 3.6l-1.6 16.8"/>',
    'presets' => '<path d="M9.2 3.2h5.6v5.2l4.4 9.2a2 2 0 0 1-1.8 2.9H6.6a2 2 0 0 1-1.8-2.9l4.4-9.2z"/><path d="M6.9 14.4h10.2"/>',
    'task-types' => '<rect x="3.6" y="3.6" width="7.6" height="7.6" rx="1.6"/><circle cx="16" cy="7.6" r="3.8"/><path d="M12 18.4 16 12.4l4 6z"/>',
    'task-sets' => '<rect x="3.2" y="3.6" width="7" height="5.6" rx="1.5"/><rect x="13.8" y="3.6" width="7" height="5.6" rx="1.5"/><rect x="8.5" y="14.8" width="7" height="5.6" rx="1.5"/><path d="M6.7 9.2v2.4h10.6V9.2M12 11.6v3.2"/>',
    'working-calendar' => '<rect x="3.4" y="5" width="17.2" height="15.6" rx="2.4"/><path d="M3.4 9.8h17.2M8.2 3.2v3.4M15.8 3.2v3.4"/><path d="m8.8 15 2.3 2.3 4.1-4.4"/>',
    'ready-dates' => '<circle cx="12" cy="13" r="8"/><path d="M12 8.6V13l3 1.9"/><path d="M9.4 3.4h5.2"/>',
    'departments' => '<path d="M3.6 20.8V5.6L12 3.2l8.4 2.4v15.2"/><path d="M7 9.4h2.8M14.2 9.4H17M7 13.6h2.8M14.2 13.6H17"/><path d="M9.8 20.8v-4.6h4.4v4.6"/>',
    'employees' => '<circle cx="12" cy="7.8" r="3.9"/><path d="M4.6 20.8v-1.4a4.4 4.4 0 0 1 4.4-4.4h6a4.4 4.4 0 0 1 4.4 4.4v1.4"/>',
    default => '',
})

<svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="{{ $level === 1 ? 1.7 : 1.6 }}"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    {{ $attributes->class(['sk-nav-icon']) }}
>{!! $paths !!}</svg>
