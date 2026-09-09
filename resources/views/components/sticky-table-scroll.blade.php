<div
    x-data="stickyTableHeader()"
    x-on:scroll.window="scheduleUpdate()"
    x-on:resize.window="scheduleUpdate()"
    data-sticky-table-scroll
    {{ $attributes->merge(['class' => 'overflow-x-auto']) }}
>
    {{ $slot }}
</div>
