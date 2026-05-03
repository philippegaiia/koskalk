@extends('layouts.public')

@section('title', 'Soapkraft — Soap Calculator & Formulation Workspace')

@section('content')
@php
    $benefits = [
        ['title' => 'Get the lye number', 'body' => 'Lye, potash, water, and superfat in seconds, without opening a spreadsheet.'],
        ['title' => 'Build soap and cosmetic formulas', 'body' => 'Oils, additives, phases, fragrance, actives, and notes stay with the formula.'],
        ['title' => 'Save the version that worked', 'body' => 'When a quick calculation becomes a formula worth keeping, save it with one click.'],
        ['title' => 'Keep label signals close', 'body' => 'Allergen signals, IFRA references, and ingredient lists stay visible while you work.'],
    ];

    $calculatorItems = [
        'No account needed',
        'NaOH, KOH, and dual-lye calculations',
        'Water and superfat controls',
        'Platform ingredient library',
        'Basic soap profile and label preview',
    ];

    $workspaceItems = [
        'Save a formula portfolio',
        'Create private ingredients',
        'Make soap and cosmetic formulas',
        'Track costing and batch details',
        'Share formulas when you are ready',
    ];

    $comparisonRows = [
        ['competitors' => 'Lye and water result', 'soapkraft' => 'Lye, potash, water, full soap formulas, cosmetic phases, and saved formula history'],
        ['competitors' => 'One-off calculation', 'soapkraft' => 'A reusable source of truth for the soap and cosmetic formulas you actually make'],
        ['competitors' => 'Generic ingredient rows', 'soapkraft' => 'Platform ingredients plus your own private ingredient library'],
        ['competitors' => 'Labeling handled elsewhere', 'soapkraft' => 'Allergen and IFRA references kept next to the formula'],
    ];

    $workflow = [
        ['step' => '01', 'title' => 'Use the calculator', 'body' => 'Choose oils, lye type, water mode, and superfat. Get practical numbers in seconds — no account, no friction.'],
        ['step' => '02', 'title' => 'Add the whole formula', 'body' => 'Layer in additives, fragrance, cosmetic phases, ingredients, and notes. Nothing gets lost, nothing breaks the soap math.'],
        ['step' => '03', 'title' => 'Save the version', 'body' => 'When a quick calculation becomes something worth revisiting or sharing, save it. Your formula portfolio lives here.'],
        ['step' => '04', 'title' => 'Prepare the real product', 'body' => 'Review label signals, IFRA references, costing, and production details in one place. When outside review is needed, you arrive prepared.'],
    ];
@endphp

<section id="calculator" class="relative isolate overflow-hidden bg-cream pt-[58px]">
    <img
        data-hero-background
        src="{{ asset('images/public/soapkraft-hero-benches.webp') }}"
        alt=""
        class="absolute inset-0 -z-20 h-full w-full object-cover object-center"
        aria-hidden="true"
    >
    <div data-hero-veil class="absolute inset-0 -z-10 bg-cream/34"></div>
    <div class="absolute inset-0 -z-10" style="background: radial-gradient(ellipse at center, rgba(245, 240, 232, 0.82) 0%, rgba(245, 240, 232, 0.68) 36%, rgba(245, 240, 232, 0.36) 68%, rgba(245, 240, 232, 0.16) 100%);"></div>
    <div class="absolute inset-x-0 top-[58px] -z-10 h-52 bg-linear-to-b from-cream via-cream/62 to-transparent"></div>
    <div class="absolute inset-x-0 bottom-0 -z-10 h-44 bg-linear-to-t from-cream via-cream/58 to-transparent"></div>
    <div class="absolute inset-y-0 left-0 -z-10 w-1/4 bg-linear-to-r from-cream/55 to-transparent"></div>
    <div class="absolute inset-y-0 right-0 -z-10 w-1/4 bg-linear-to-l from-cream/55 to-transparent"></div>

    <div class="mx-auto flex min-h-[calc(100svh-58px)] max-w-[1180px] flex-col items-center justify-center px-5 py-16 text-center lg:px-10 lg:py-20">
        <div class="max-w-[900px]">
            <p class="mb-5 inline-flex rounded-full border border-line bg-panel px-3 py-1.5 font-mono text-[11px] uppercase tracking-[0.12em] text-ink-soft shadow-sm">
                Free soap calculator · soap & cosmetic formulation workspace
            </p>

            <h1 data-hero-title class="mx-auto max-w-[860px] font-serif text-[clamp(42px,6vw,82px)] font-medium leading-[1.02] tracking-[0.015em] text-ink-strong">
                Your formula, your ingredients, your bench — in one place.
            </h1>

            <p class="mx-auto mt-6 max-w-[680px] text-[16px] leading-8 text-ink-soft">
                Start with a quick lye calculation, or build a complete soap or cosmetic formula with phases, ingredients, costs, label signals, and history kept together.
            </p>

            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('recipes.create') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3.5 text-sm font-semibold text-white no-underline shadow-[0_12px_24px_rgba(192,102,58,0.24)] transition hover:bg-accent-hover hover:-translate-y-px">
                    Use the free calculator
                </a>
                <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3.5 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent">
                    Start saving formulas
                </a>
            </div>

            <p class="mt-4 text-sm text-ink-soft">
                No account needed for quick calculations. Create an account when you want to save formulas, ingredients, and history.
            </p>
        </div>
    </div>
</section>

<section id="benefits" class="border-y border-line bg-panel">
    <h2 class="sr-only">Benefits</h2>
    <div class="mx-auto grid max-w-[1180px] gap-px bg-line px-5 lg:grid-cols-4 lg:px-10">
        @foreach ($benefits as $benefit)
            <div class="bg-panel py-7 lg:px-6">
                <h2 class="font-serif text-xl font-medium text-ink-strong">{{ $benefit['title'] }}</h2>
                <p class="mt-3 text-sm leading-6 text-ink-soft">{{ $benefit['body'] }}</p>
            </div>
        @endforeach
    </div>
</section>

<section id="workspace" class="bg-surface px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="max-w-[680px]">
            <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">Two ways in — one place to land</p>
            <h2 class="mt-4 font-serif text-[clamp(32px,4vw,52px)] font-medium leading-[1.08] text-ink-strong">
                Quick when you just need a number. Complete when the formula matters.
            </h2>
        </div>

        <div class="mt-12 grid gap-5 lg:grid-cols-2">
            <article class="rounded-[1rem] border border-line bg-panel p-6 lg:p-8">
                <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-ink-soft">No account</p>
                <h3 class="mt-3 font-serif text-3xl font-medium text-ink-strong">Use the free calculator</h3>
                <p class="mt-4 text-sm leading-7 text-ink-soft">You searched for a lye calculator. Here it is — no signup, no friction. Get your numbers and go.</p>
                <div class="mt-6 space-y-3">
                    @foreach ($calculatorItems as $item)
                        <p class="flex gap-3 text-sm text-ink-soft">
                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-accent"></span>
                            <span>{{ $item }}</span>
                        </p>
                    @endforeach
                </div>
            </article>

            <article class="rounded-[1rem] border border-line-strong bg-cream-warm p-6 lg:p-8">
                <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">Account</p>
                <h3 class="mt-3 font-serif text-3xl font-medium text-ink-strong">Save your soap and cosmetic formulas</h3>
                <p class="mt-4 text-sm leading-7 text-ink-soft">For the maker who's tired of losing good soap batches, cream formulas, and production notes to screenshots, notebooks, and duplicated spreadsheets.</p>
                <div class="mt-6 space-y-3">
                    @foreach ($workspaceItems as $item)
                        <p class="flex gap-3 text-sm text-ink-soft">
                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-success"></span>
                            <span>{{ $item }}</span>
                        </p>
                    @endforeach
                </div>
            </article>
        </div>
    </div>
</section>

<section id="comparison" class="bg-cream px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="grid gap-10 lg:grid-cols-[0.75fr_1.25fr]">
            <div>
                <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">Why use Soapkraft?</p>
                <h2 class="mt-4 font-serif text-[clamp(32px,4vw,52px)] font-medium leading-[1.08] text-ink-strong">
                    A calculator is useful. A bench record is better.
                </h2>
                <p class="mt-5 text-sm leading-7 text-ink-soft">
                    When your formulas start to matter — for customers, records, costing, or labels — you need more than a lye number. Soapkraft keeps soap calculations, cosmetic phases, ingredients, costing, label signals, and formula history together.
                </p>
            </div>

            <div class="overflow-hidden rounded-[1rem] border border-line-strong bg-panel">
                <div class="grid grid-cols-2 border-b border-line bg-field-muted px-4 py-3 font-mono text-[11px] uppercase tracking-[0.1em] text-ink-soft">
                    <span>Most calculators</span>
                    <span>Soapkraft</span>
                </div>

                @foreach ($comparisonRows as $row)
                    <div class="grid gap-4 border-b border-line px-4 py-4 last:border-b-0 sm:grid-cols-2">
                        <p class="text-sm leading-6 text-ink-soft">{{ $row['competitors'] }}</p>
                        <p class="text-sm font-medium leading-6 text-ink-strong">{{ $row['soapkraft'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

<section id="workflow" class="bg-panel px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="max-w-[660px]">
            <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">From quick calculation to source of truth</p>
            <h2 class="mt-4 font-serif text-[clamp(32px,4vw,52px)] font-medium leading-[1.08] text-ink-strong">
                Start with the number you need. Stay when the formula is worth keeping.
            </h2>
        </div>

        <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($workflow as $item)
                <article class="border-t border-line-strong pt-5">
                    <p class="font-mono text-sm font-semibold text-accent">{{ $item['step'] }}</p>
                    <h3 class="mt-5 font-serif text-2xl font-medium text-ink-strong">{{ $item['title'] }}</h3>
                    <p class="mt-3 text-sm leading-7 text-ink-soft">{{ $item['body'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="bg-hero px-5 py-16 text-inverse lg:px-10">
    <div class="mx-auto grid max-w-[980px] items-center gap-8 lg:grid-cols-[1fr_auto]">
        <div>
            <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-inverse-muted">Built from the bench</p>
            <blockquote class="mt-4 font-serif text-[clamp(26px,4vw,42px)] font-medium leading-[1.15]">
                "I built the soap calculator I needed when formulas became production records."
            </blockquote>
            <p class="mt-5 max-w-[620px] text-sm leading-7 text-inverse-soft">
                After years of real soap and cosmetics production, Soapkraft is designed to be one place of truth for the recipes, ingredients, costs, and label information that matter after the first calculation.
            </p>
        </div>

        <div class="grid grid-cols-3 gap-6 text-center lg:gap-8">
            <div>
                <p class="font-serif text-4xl font-medium">1M+</p>
                <p class="mt-1 font-mono text-[11px] text-inverse-muted">bars made</p>
            </div>
            <div>
                <p class="font-serif text-4xl font-medium">16</p>
                <p class="mt-1 font-mono text-[11px] text-inverse-muted">years</p>
            </div>
            <div>
                <p class="font-serif text-4xl font-medium">2</p>
                <p class="mt-1 font-mono text-[11px] text-inverse-muted">countries</p>
            </div>
        </div>
    </div>
</section>

<section class="bg-cream px-5 py-20 text-center lg:px-10">
    <div class="mx-auto max-w-[660px]">
        <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">Start simple</p>
        <h2 class="mt-4 font-serif text-[clamp(34px,5vw,58px)] font-medium leading-[1.08] text-ink-strong">
            Use the calculator. Save the formulas that earn a place on your bench.
        </h2>
        <p class="mx-auto mt-5 max-w-[520px] text-sm leading-7 text-ink-soft">
            Quick soap calculations stay free. Your workspace is where you keep the soap and cosmetic formulas, ingredients, and history you want to revisit.
        </p>

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('recipes.create') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3.5 text-sm font-semibold text-white no-underline transition hover:bg-accent-hover hover:-translate-y-px">
                Use the free calculator
            </a>
            <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3.5 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent">
                Start saving formulas
            </a>
        </div>
    </div>
</section>
@endsection
