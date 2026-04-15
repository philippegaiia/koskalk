@extends('layouts.app-shell')

@section('title', 'New Cosmetic Formula · '.config('app.name'))
@section('page_heading', 'New Cosmetic Formula')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        <section class="sk-card p-6">
            <p class="sk-eyebrow">Cosmetic formula</p>
            <div class="mt-3 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <h3 class="text-3xl font-semibold text-[var(--color-ink-strong)]">Choose a cosmetic product type</h3>
                    <p class="mt-4 text-sm leading-7 text-[var(--color-ink-soft)]">
                        Pick the broad category first. The formula name stays free, and IFRA guidance remains editable in the workbench.
                    </p>
                </div>
                <a href="{{ route('recipes.index') }}" wire:navigate class="sk-action-link">Back to recipes</a>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($productTypes as $productType)
                <a
                    href="{{ route('recipes.create', ['family' => $productFamily->slug, 'type' => $productType->slug]) }}"
                    wire:navigate
                    class="sk-card p-5 transition hover:-translate-y-0.5 hover:border-[var(--color-line-strong)]"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="sk-eyebrow">Product type</p>
                            <h4 class="mt-3 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $productType->name }}</h4>
                        </div>
                        <span class="sk-badge sk-badge-neutral">Start</span>
                    </div>

                    @if ($productType->description)
                        <p class="mt-4 text-sm leading-6 text-[var(--color-ink-soft)]">{{ $productType->description }}</p>
                    @else
                        <p class="mt-4 text-sm leading-6 text-[var(--color-ink-soft)]">Open a blank cosmetic formula with Phase A ready for ingredients.</p>
                    @endif
                </a>
            @endforeach
        </section>
    </div>
@endsection
