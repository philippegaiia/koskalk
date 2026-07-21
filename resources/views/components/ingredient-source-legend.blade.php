@props(['show' => false])

@if ($show)
    <p class="flex items-center gap-1.5 text-[0.625rem] leading-4 text-[var(--color-ink-soft)]">
        <span class="inline-block size-1.5 shrink-0 rounded-full bg-[var(--color-ink-soft)] opacity-60" aria-hidden="true"></span>
        <span>{{ __('ingredients.table.source.legend') }}</span>
    </p>
@endif
