@props(['isUserOwned' => false])

@if ($isUserOwned)
    <span
        class="inline-block size-1.5 shrink-0 rounded-full bg-[var(--color-ink-soft)] opacity-60"
        role="img"
        aria-label="{{ __('ingredients.table.source.marker') }}"
        title="{{ __('ingredients.table.source.marker') }}"
    ></span>
@endif
