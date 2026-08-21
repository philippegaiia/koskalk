@extends('layouts.app-shell')

@section('title', __('products.creation.start.title').' · '.config('app.name'))
@section('page_heading', __('products.creation.start.heading'))

@section('content')
    <div class="mx-auto w-full max-w-5xl">
        <section class="sk-card overflow-hidden" data-product-creation-selector>
            <header class="border-b border-[var(--color-line)] px-5 py-6 sm:px-8 sm:py-8">
                <p class="sk-eyebrow">{{ __('products.creation.quick.eyebrow') }}</p>
                <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ __('products.creation.quick.title') }}</h3>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('products.creation.quick.description') }}</p>
            </header>

            <div class="space-y-7 px-5 py-6 sm:px-8 sm:py-8">
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('products.creation.quick.families_label') }}">
                    <button
                        type="button"
                        data-product-family-filter="all"
                        aria-pressed="true"
                        class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel-strong)] aria-pressed:border-[var(--color-accent)] aria-pressed:bg-[var(--color-accent-soft)] aria-pressed:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                    >
                        {{ __('products.creation.quick.all_families') }}
                    </button>
                    @foreach ($entries as $entry => $details)
                        <button
                            type="button"
                            data-product-family-filter="{{ $entry }}"
                            aria-pressed="false"
                            class="rounded-full border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel-strong)] aria-pressed:border-[var(--color-accent)] aria-pressed:bg-[var(--color-accent-soft)] aria-pressed:text-[var(--color-accent-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                        >
                            {{ $details['name'] }}
                        </button>
                    @endforeach
                </div>

                <div class="max-w-3xl">
                    <label for="product-type-search" class="block text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('products.creation.quick.search_label') }}</label>
                    <div class="mt-2 flex items-center rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-4 transition focus-within:border-[var(--color-accent)] focus-within:ring-2 focus-within:ring-[var(--color-accent-soft)]">
                        <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="size-5 shrink-0 text-[var(--color-ink-soft)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                            <circle cx="11" cy="11" r="7" />
                            <path stroke-linecap="round" d="m20 20-4-4" />
                        </svg>
                        <input
                            id="product-type-search"
                            data-product-type-search
                            type="search"
                            autocomplete="off"
                            placeholder="{{ __('products.creation.quick.search_placeholder') }}"
                            class="sk-field-control w-full px-3 py-3.5"
                        >
                    </div>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ __('products.creation.quick.search_help') }}</p>
                </div>

                <div class="flex items-center justify-between gap-4 border-b border-[var(--color-line)] pb-3">
                    <h4 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('products.creation.quick.results_label') }}</h4>
                    <p
                        data-product-type-count
                        data-count-zero="{{ trans_choice('products.creation.quick.result_count', 0, ['count' => 0]) }}"
                        data-count-one="{{ trans_choice('products.creation.quick.result_count', 1, ['count' => ':count']) }}"
                        data-count-many="{{ trans_choice('products.creation.quick.result_count', 2, ['count' => ':count']) }}"
                        class="text-xs font-medium text-[var(--color-ink-soft)]"
                    >
                        {{ trans_choice('products.creation.quick.result_count', count($productTypes), ['count' => count($productTypes)]) }}
                    </p>
                </div>

                <div data-product-type-results class="grid max-h-[32rem] gap-3 overflow-y-auto pr-1 sm:grid-cols-2" aria-label="{{ __('products.creation.quick.results_label') }}">
                    @foreach ($productTypes as $productType)
                        <a
                            href="{{ route('recipes.create', ['family' => $productType['family'], 'type' => $productType['slug']]) }}"
                            wire:navigate
                            data-product-type-option
                            data-product-entry="{{ $productType['entry'] }}"
                            data-product-search="{{ $productType['search_text'] }}"
                            class="group rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4 transition-colors hover:border-[var(--color-accent)] hover:bg-[var(--color-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                        >
                            <span class="flex items-start justify-between gap-4">
                                <span class="min-w-0">
                                    <span class="block font-semibold text-[var(--color-ink-strong)]">{{ $productType['name'] }}</span>
                                    <span class="mt-1 block text-xs font-medium text-[var(--color-accent-strong)]">{{ $productType['entry_name'] }} · {{ $productType['area_name'] }} · {{ $productType['category_name'] }}</span>
                                    <span class="mt-2 block text-sm leading-6 text-[var(--color-ink-soft)]">{{ $productType['description'] ?: __('products.creation.quick.fallback_description') }}</span>
                                </span>
                                <span class="mt-0.5 shrink-0 text-[var(--color-ink-soft)] transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                            </span>
                        </a>
                    @endforeach
                </div>

                <div data-product-type-empty hidden class="rounded-xl border border-dashed border-[var(--color-line)] px-6 py-10 text-center">
                    <p class="font-semibold text-[var(--color-ink-strong)]">{{ __('products.creation.quick.empty_title') }}</p>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('products.creation.quick.empty_description') }}</p>
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--color-line)] pt-5">
                    <p class="text-sm text-[var(--color-ink-soft)]">{{ __('products.creation.quick.guided_help') }}</p>
                    <a href="{{ route('recipes.start.guided') }}" wire:navigate class="sk-btn sk-btn-outline">{{ __('products.creation.quick.guided') }}</a>
                </footer>
            </div>
        </section>
    </div>
@endsection
