@props([
    'paginator',
    'perPageLabel' => 'Rows per page',
])

@php
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
    $pageName = $paginator->getPageName();
    $pages = collect([1, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage])
        ->filter(fn (int $page): bool => $page >= 1 && $page <= $lastPage)
        ->unique()
        ->sort()
        ->values();
@endphp

<div {{ $attributes->class(['flex flex-col gap-3 border-t border-[var(--color-line)] px-5 py-3 sm:flex-row sm:items-center sm:justify-between']) }}>
    <label class="flex items-center gap-2 text-xs font-medium text-[var(--color-ink-soft)]">
        <span>{{ __('table.pagination.rows_per_page') }}</span>
        <select wire:model.live="perPage" class="sk-pagination-select h-9 w-20 shrink-0 rounded-lg border border-transparent bg-transparent py-1.5 pl-2.5 text-sm text-[var(--color-ink-strong)] outline-[var(--color-active)] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-active)]" aria-label="{{ $perPageLabel }}">
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
        </select>
    </label>

    <p class="text-xs tabular-nums text-[var(--color-ink-soft)]" aria-live="polite">
        {{ __('table.pagination.summary', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
    </p>

    @if ($lastPage > 1)
        <nav class="flex items-center gap-1" aria-label="{{ __('table.pagination.label') }}">
            <button
                type="button"
                wire:click="previousPage('{{ $pageName }}')"
                @disabled($paginator->onFirstPage())
                class="inline-flex h-9 items-center gap-1 rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] px-2.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:border-[var(--color-accent)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="{{ __('table.pagination.previous_page') }}"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M11.78 14.53a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 0 1 0-1.06l4-4a.75.75 0 1 1 1.06 1.06L8.31 10l3.47 3.47a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd" />
                </svg>
                <span class="hidden md:inline">{{ __('table.pagination.previous') }}</span>
            </button>

            <div class="hidden items-center gap-1 sm:flex">
                @foreach ($pages as $page)
                    @if (! $loop->first && $page - $pages[$loop->index - 1] > 1)
                        <span class="grid size-9 place-items-center text-xs text-[var(--color-ink-soft)]" aria-hidden="true">…</span>
                    @endif

                    @if ($page === $currentPage)
                        <span class="grid size-9 place-items-center rounded-lg bg-[var(--color-active)] text-xs font-semibold text-[var(--color-on-active)]" aria-current="page" aria-label="{{ __('table.pagination.page', ['page' => $page]) }}">{{ $page }}</span>
                    @else
                        <button
                            type="button"
                            wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                            class="grid size-9 place-items-center rounded-lg border border-transparent text-xs font-medium text-[var(--color-ink-soft)] transition hover:border-[var(--color-line)] hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                            aria-label="{{ __('table.pagination.go_to_page', ['page' => $page]) }}"
                        >{{ $page }}</button>
                    @endif
                @endforeach
            </div>

            <span class="px-1 text-xs tabular-nums text-[var(--color-ink-soft)] sm:hidden">{{ $currentPage }} / {{ $lastPage }}</span>

            <button
                type="button"
                wire:click="nextPage('{{ $pageName }}')"
                @disabled(! $paginator->hasMorePages())
                class="inline-flex h-9 items-center gap-1 rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] px-2.5 text-xs font-medium text-[var(--color-ink-soft)] transition hover:border-[var(--color-accent)] hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-40"
                aria-label="{{ __('table.pagination.next_page') }}"
            >
                <span class="hidden md:inline">{{ __('table.pagination.next') }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8.22 5.47a.75.75 0 0 1 1.06 0l4 4a.75.75 0 0 1 0 1.06l-4 4a.75.75 0 1 1-1.06-1.06L11.69 10 8.22 6.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </button>
        </nav>
    @endif
</div>
