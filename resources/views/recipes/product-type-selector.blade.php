@extends('layouts.app-shell')

@section('title', __('products.creation.selector.title', ['entry' => $entryData['name']]).' · '.config('app.name'))
@section('page_heading', __('products.creation.selector.heading', ['entry' => $entryData['name']]))

@section('content')
    <div class="mx-auto w-full max-w-5xl space-y-10">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="max-w-2xl">
                <p class="sk-eyebrow">{{ $entryData['name'] }}</p>
                <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ __('products.creation.selector.choose') }}</h3>
                <p class="mt-3 text-sm leading-7 text-[var(--color-ink-soft)]">{{ __('products.creation.selector.description') }}</p>
            </div>
            <a href="{{ route('recipes.start') }}" wire:navigate class="sk-action-link">{{ __('products.creation.selector.back') }}</a>
        </header>

        @foreach ($groupedProductTypes as $area)
            <section class="space-y-6" aria-labelledby="product-area-{{ $area['id'] }}">
                <div class="border-b border-[var(--color-line)] pb-3">
                    <h4 id="product-area-{{ $area['id'] }}" class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $area['name'] }}</h4>
                </div>

                @foreach ($area['categories'] as $category)
                    <div class="space-y-3">
                        <h5 class="text-sm font-semibold text-[var(--color-ink)]">{{ $category['name'] }}</h5>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($category['product_types'] as $productType)
                                <a
                                    href="{{ route('recipes.create', ['family' => $entryData['family'], 'type' => $productType['slug']]) }}"
                                    wire:navigate
                                    class="group rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] px-5 py-4 transition-colors hover:border-[var(--color-accent)] hover:bg-[var(--color-accent-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                                >
                                    <span class="flex items-start justify-between gap-4">
                                        <span>
                                            <span class="block font-semibold text-[var(--color-ink-strong)]">{{ $productType['name'] }}</span>
                                            <span class="mt-1.5 block text-sm leading-6 text-[var(--color-ink-soft)]">
                                                {{ $productType['description'] ?: __('products.creation.selector.fallback_description') }}
                                            </span>
                                        </span>
                                        <span class="mt-0.5 text-[var(--color-ink-soft)] transition-transform group-hover:translate-x-1" aria-hidden="true">→</span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
@endsection
