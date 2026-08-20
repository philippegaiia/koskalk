@extends('layouts.app-shell')

@section('title', __('products.creation.start.title').' · '.config('app.name'))
@section('page_heading', __('products.creation.start.heading'))

@section('content')
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <header class="max-w-2xl">
            <h3 class="text-2xl font-semibold text-[var(--color-ink-strong)]">{{ __('products.creation.start.heading') }}</h3>
            <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('products.creation.start.description') }}</p>
        </header>

        <nav class="sk-card overflow-hidden" aria-label="{{ __('products.creation.start.aria_label') }}">
            @foreach ($entries as $entry => $details)
                <a
                    href="{{ route('recipes.choose-type', ['entry' => $entry]) }}"
                    wire:navigate
                    class="group grid min-h-28 grid-cols-[3rem_1fr_auto] items-center gap-4 border-b border-[var(--color-line)] px-5 py-5 transition-colors last:border-b-0 hover:bg-[var(--color-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-accent)] sm:grid-cols-[4rem_1fr_auto] sm:px-7"
                >
                    <span class="font-mono text-sm text-[var(--color-ink-soft)]" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span>
                        <span class="block text-lg font-semibold text-[var(--color-ink-strong)]">{{ $details['name'] }}</span>
                        <span class="mt-1 block text-sm text-[var(--color-ink-soft)]">{{ $details['description'] }}</span>
                    </span>
                    <span class="text-xl text-[var(--color-ink-soft)] transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                </a>
            @endforeach
        </nav>
    </div>
@endsection
