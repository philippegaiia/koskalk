@extends('layouts.app-shell')

@section('title', __('workspaces.selection.page_heading').' · '.config('app.name'))
@section('page_heading', __('workspaces.selection.page_heading'))

@section('content')
@php
    $paginationParameters = array_filter([
        'search' => $search,
    ], fn (?string $value): bool => filled($value));
    $previousPageUrl = $workspaces->currentPage() > 1
        ? route('workspace-selection.index', [...$paginationParameters, 'workspace-selection-page' => $workspaces->currentPage() - 1])
        : null;
    $nextPageUrl = $workspaces->hasMorePages()
        ? route('workspace-selection.index', [...$paginationParameters, 'workspace-selection-page' => $workspaces->currentPage() + 1])
        : null;
@endphp

<div class="mx-auto w-full max-w-3xl space-y-6">
    <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('workspaces.selection.page_description') }}</p>

    <p role="note" class="rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] px-4 py-3 text-sm leading-6 text-[var(--color-ink-soft)]">
        {{ __('workspaces.selection.open_tabs_notice') }}
    </p>

    <section aria-label="{{ __('workspaces.selection.page_heading') }}" class="sk-card space-y-5 p-5 sm:p-6">
        <form method="GET" action="{{ route('workspace-selection.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label for="company-search" class="grid min-w-0 flex-1 gap-2">
                <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('workspaces.selection.search_label') }}</span>
                <input
                    id="company-search"
                    type="search"
                    name="search"
                    maxlength="120"
                    value="{{ $search }}"
                    placeholder="{{ __('workspaces.selection.search_placeholder') }}"
                    class="sk-input w-full"
                >
            </label>
            <button type="submit" class="sk-btn sk-btn-outline shrink-0">{{ __('workspaces.selection.search') }}</button>
        </form>

        @if ($errors->any())
            <p role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $errors->first() }}</p>
        @endif

        @if ($workspaces->isEmpty())
            <p role="status" class="rounded-lg border border-dashed border-[var(--color-line)] px-4 py-8 text-center text-sm text-[var(--color-ink-soft)]">
                {{ filled($search) ? __('workspaces.selection.no_results') : __('workspaces.selection.empty') }}
            </p>
        @else
            <ul class="divide-y divide-[var(--color-line)]" aria-label="{{ __('workspaces.selection.page_heading') }}">
                @foreach ($workspaces as $workspace)
                    @php($isCurrentWorkspace = $currentWorkspace?->is($workspace) ?? false)
                    <li class="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-5">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-[var(--color-ink-strong)]">{{ $workspace->name }}</p>
                            @if ($isCurrentWorkspace)
                                <p class="mt-1 text-xs font-medium text-[var(--color-accent-strong)]" role="status">{{ __('workspaces.selection.current_badge') }}</p>
                            @endif
                        </div>

                        @unless ($isCurrentWorkspace)
                            <form method="POST" action="{{ route('workspace-selection.update') }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="workspace_public_id" value="{{ $workspace->public_id }}">
                                <button type="submit" class="sk-btn sk-btn-primary w-full justify-center sm:w-auto">
                                    {{ __('workspaces.selection.switch_to') }}
                                </button>
                            </form>
                        @endunless
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($workspaces->lastPage() > 1)
            <nav aria-label="{{ __('workspaces.selection.pagination_label') }}" class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--color-line)] pt-4">
                @if ($previousPageUrl)
                    <a href="{{ $previousPageUrl }}" class="sk-btn sk-btn-outline">{{ __('workspaces.selection.previous_page') }}</a>
                @else
                    <span aria-disabled="true" class="sk-btn sk-btn-outline cursor-not-allowed opacity-50">{{ __('workspaces.selection.previous_page') }}</span>
                @endif

                <p class="text-sm text-[var(--color-ink-soft)]" aria-live="polite">
                    {{ __('workspaces.selection.page_status', ['page' => $workspaces->currentPage(), 'pages' => $workspaces->lastPage()]) }}
                </p>

                @if ($nextPageUrl)
                    <a href="{{ $nextPageUrl }}" class="sk-btn sk-btn-outline">{{ __('workspaces.selection.next_page') }}</a>
                @else
                    <span aria-disabled="true" class="sk-btn sk-btn-outline cursor-not-allowed opacity-50">{{ __('workspaces.selection.next_page') }}</span>
                @endif
            </nav>
        @endif
    </section>
</div>
@endsection
