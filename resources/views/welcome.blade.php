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

    $previewOils = [
        ['name' => 'Olive oil', 'percent' => '62%', 'weight' => '620 g'],
        ['name' => 'Coconut oil', 'percent' => '20%', 'weight' => '200 g'],
        ['name' => 'Shea butter', 'percent' => '10%', 'weight' => '100 g'],
        ['name' => 'Castor oil', 'percent' => '8%', 'weight' => '80 g'],
    ];

    $previewResults = [
        ['label' => 'NaOH', 'value' => '137.8 g', 'tone' => 'warning'],
        ['label' => 'Water', 'value' => '310 g', 'tone' => 'accent'],
        ['label' => 'Superfat', 'value' => '5%', 'tone' => 'neutral'],
    ];
@endphp

@push('head')
    <link rel="preload" as="image" href="{{ asset('images/public/soapkraft-hero-benches.webp') }}" type="image/webp" fetchpriority="high">
@endpush

<section id="calculator" class="relative isolate overflow-hidden bg-cream pt-[58px]">
    <img
        data-hero-background
        src="{{ asset('images/public/soapkraft-hero-benches.webp') }}"
        width="1774"
        height="887"
        alt=""
        class="absolute inset-0 -z-20 h-full w-full object-cover object-center"
        aria-hidden="true"
        fetchpriority="high"
        decoding="async"
    >
    <div data-hero-veil class="absolute inset-0 -z-10 bg-cream/34"></div>
    <div class="sk-hero-radial absolute inset-0 -z-10"></div>
    <div class="absolute inset-x-0 top-[58px] -z-10 h-52 bg-linear-to-b from-cream via-cream/62 to-transparent"></div>
    <div class="absolute inset-x-0 bottom-0 -z-10 h-44 bg-linear-to-t from-cream via-cream/58 to-transparent"></div>
    <div class="absolute inset-y-0 left-0 -z-10 w-1/4 bg-linear-to-r from-cream/55 to-transparent"></div>
    <div class="absolute inset-y-0 right-0 -z-10 w-1/4 bg-linear-to-l from-cream/55 to-transparent"></div>

    <div class="mx-auto grid min-h-[calc(100svh-58px)] max-w-[1180px] items-center gap-10 px-5 py-12 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.7fr)] lg:px-10 lg:py-14">
        <div class="max-w-[720px] text-center lg:text-left">
            <p class="sk-card-elevation mb-5 inline-flex rounded-full bg-panel px-3 py-1.5 text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-ink-soft">
                Free soap calculator · soap & cosmetic formulation workspace
            </p>

            <h1 data-hero-title class="font-sans text-[clamp(2.5rem,5vw,4.25rem)] font-semibold leading-[1.02] tracking-[0.015em] text-ink-strong">
                Your formula, your ingredients, your bench — in one place.
            </h1>

            <p class="mx-auto mt-5 max-w-[640px] text-base leading-8 text-ink-soft lg:mx-0">
                Start with a quick lye calculation, or build a complete soap or cosmetic formula with phases, ingredients, costs, label signals, and history kept together.
            </p>

            <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
                <a href="{{ route('calculator') }}" class="sk-card-elevation inline-flex justify-center rounded-lg bg-accent px-6 py-3.5 text-sm font-semibold text-inverse no-underline transition hover:-translate-y-px hover:bg-accent-hover">
                    Use the free calculator
                </a>
                <a href="{{ route('register') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3.5 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent">
                    Start saving formulas
                </a>
            </div>

            <p class="mt-4 text-sm leading-6 text-ink-soft">
                No account needed to calculate. Sign in appears only when you choose to save formulas, ingredients, and history.
            </p>
        </div>

        <aside aria-label="Example soap calculation" class="sk-card-elevation mx-auto w-full max-w-[440px] rounded-xl bg-panel p-5 text-left lg:mx-0">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-ink-soft">Live calculation preview</p>
                    <h2 class="mt-2 font-sans text-2xl font-semibold leading-tight text-ink-strong">Winter olive bar</h2>
                </div>
                <span class="rounded-full bg-accent-soft px-3 py-1 font-mono text-xs font-medium tabular-nums text-accent-strong">1000 g</span>
            </div>

            <div class="mt-5 grid grid-cols-3 gap-2">
                @foreach ($previewResults as $result)
                    <div @class([
                        'rounded-lg px-3 py-2',
                        'bg-warning-soft text-warning-strong' => $result['tone'] === 'warning',
                        'bg-accent-soft text-accent-strong' => $result['tone'] === 'accent',
                        'bg-panel-strong text-ink-soft' => $result['tone'] === 'neutral',
                    ])>
                        <p class="text-[0.625rem] font-medium uppercase tracking-[0.05em]">{{ $result['label'] }}</p>
                        <p class="mt-1 font-mono text-sm tabular-nums text-ink-strong">{{ $result['value'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 space-y-2">
                @foreach ($previewOils as $oil)
                    <div class="flex items-center gap-3 rounded-lg bg-panel-strong px-4 py-3">
                        <span class="min-w-0 flex-1 text-sm text-ink">{{ $oil['name'] }}</span>
                        <span class="w-12 text-right font-mono text-sm tabular-nums text-ink-soft">{{ $oil['percent'] }}</span>
                        <span class="w-16 text-right font-mono text-sm tabular-nums text-ink-strong">{{ $oil['weight'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-5 rounded-lg bg-field-muted px-4 py-3">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm text-ink-soft">Label preview</span>
                    <span class="text-sm font-medium text-ink-strong">Olivate · Cocoate · Shea butter</span>
                </div>
            </div>
        </aside>
    </div>
</section>

<section id="benefits" class="bg-panel px-5 py-10 lg:px-10">
    <h2 class="sr-only">Benefits</h2>
    <div class="mx-auto grid max-w-[1180px] gap-3 md:grid-cols-2 lg:grid-cols-4">
        @foreach ($benefits as $benefit)
            <article class="rounded-lg bg-panel-strong px-4 py-5">
                <h3 class="font-sans text-xl font-semibold text-ink-strong">{{ $benefit['title'] }}</h3>
                <p class="mt-3 text-sm leading-6 text-ink-soft">{{ $benefit['body'] }}</p>
            </article>
        @endforeach
    </div>
</section>

<section id="workspace" class="bg-surface px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="grid gap-8 lg:grid-cols-[0.84fr_1.16fr] lg:items-end">
            <div class="max-w-[680px]">
                <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-accent-strong">Two ways in — one place to land</p>
                <h2 class="mt-4 font-sans text-[clamp(2rem,4vw,3.25rem)] font-semibold leading-[1.08] text-ink-strong">
                    Quick when you just need a number. Complete when the formula matters.
                </h2>
            </div>
            <p class="text-sm leading-7 text-ink-soft lg:max-w-[420px]">
                The free calculator stays close to the top. The workspace appears only when the formula deserves history, costing, private ingredients, and review.
            </p>
        </div>

        <div class="mt-12 grid gap-5 lg:grid-cols-2">
            <article class="sk-card-elevation rounded-xl bg-panel p-6 lg:p-8">
                <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-ink-soft">No account</p>
                <h3 class="mt-3 font-sans text-3xl font-semibold text-ink-strong">Use the free calculator</h3>
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

            <article class="sk-card-elevation rounded-xl bg-cream-warm p-6 lg:p-8">
                <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-accent-strong">Account</p>
                <h3 class="mt-3 font-sans text-3xl font-semibold text-ink-strong">Save your soap and cosmetic formulas</h3>
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
                <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-accent-strong">Why use Soapkraft?</p>
                <h2 class="mt-4 font-sans text-[clamp(2rem,4vw,3.25rem)] font-semibold leading-[1.08] text-ink-strong">
                    A calculator is useful. A bench record is better.
                </h2>
                <p class="mt-5 text-sm leading-7 text-ink-soft">
                    When your formulas start to matter — for customers, records, costing, or labels — you need more than a lye number. Soapkraft keeps soap calculations, cosmetic phases, ingredients, costing, label signals, and formula history together.
                </p>
            </div>

            <div class="sk-card-elevation rounded-xl bg-panel p-4">
                <div class="grid grid-cols-2 px-2 pb-3 text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-ink-soft">
                    <span>Most calculators</span>
                    <span>Soapkraft</span>
                </div>

                <div class="space-y-2">
                    @foreach ($comparisonRows as $row)
                        <div class="grid gap-4 rounded-lg bg-panel-strong px-4 py-4 sm:grid-cols-2">
                            <p class="text-sm leading-6 text-ink-soft">{{ $row['competitors'] }}</p>
                            <p class="text-sm font-medium leading-6 text-ink-strong">{{ $row['soapkraft'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

<section id="workflow" class="bg-panel px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="max-w-[660px]">
            <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-accent-strong">From quick calculation to source of truth</p>
            <h2 class="mt-4 font-sans text-[clamp(2rem,4vw,3.25rem)] font-semibold leading-[1.08] text-ink-strong">
                Start with the number you need. Stay when the formula is worth keeping.
            </h2>
        </div>

        <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($workflow as $item)
                <article class="rounded-lg bg-panel-strong px-4 py-5">
                    <p class="font-mono text-sm font-semibold tabular-nums text-accent-strong">{{ $item['step'] }}</p>
                    <h3 class="mt-5 font-sans text-2xl font-semibold text-ink-strong">{{ $item['title'] }}</h3>
                    <p class="mt-3 text-sm leading-7 text-ink-soft">{{ $item['body'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="bg-hero px-5 py-16 text-inverse lg:px-10">
    <div class="mx-auto grid max-w-[980px] items-center gap-8 lg:grid-cols-[1fr_auto]">
        <div>
            <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-inverse-muted">Built from the bench</p>
            <blockquote class="mt-4 font-sans text-[clamp(1.625rem,4vw,2.625rem)] font-semibold leading-[1.15]">
                "I built the soap calculator I needed when formulas became production records."
            </blockquote>
            <p class="mt-5 max-w-[620px] text-sm leading-7 text-inverse-soft">
                After years of real soap and cosmetics production, Soapkraft is designed as one place of truth for the recipes, ingredients, costs, and label information that matter after the first calculation.
            </p>
        </div>

        <div class="grid grid-cols-3 gap-6 text-center lg:gap-8">
            <div>
                <p class="font-mono text-4xl font-semibold tabular-nums">1M+</p>
                <p class="mt-1 text-[0.6875rem] font-medium text-inverse-muted">bars produced</p>
            </div>
            <div>
                <p class="font-mono text-4xl font-semibold tabular-nums">16</p>
                <p class="mt-1 text-[0.6875rem] font-medium text-inverse-muted">years formulating</p>
            </div>
            <div>
                <p class="font-mono text-4xl font-semibold tabular-nums">2</p>
                <p class="mt-1 text-[0.6875rem] font-medium text-inverse-muted">production countries</p>
            </div>
        </div>
    </div>
</section>

<section class="bg-cream px-5 py-20 text-center lg:px-10">
    <div class="mx-auto max-w-[660px]">
        <p class="text-[0.6875rem] font-medium uppercase tracking-[0.05em] text-accent-strong">Start simple</p>
        <h2 class="mt-4 font-sans text-[clamp(2.125rem,5vw,3.625rem)] font-semibold leading-[1.08] text-ink-strong">
            Use the calculator. Save the formulas that earn a place on your bench.
        </h2>
        <p class="mx-auto mt-5 max-w-[520px] text-sm leading-7 text-ink-soft">
            Quick soap calculations stay free. Your workspace is where you keep the soap and cosmetic formulas, ingredients, and history you choose to revisit.
        </p>

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('calculator') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3.5 text-sm font-semibold text-inverse no-underline transition hover:bg-accent-hover hover:-translate-y-px">
                Use the free calculator
            </a>
            <a href="{{ route('register') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3.5 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent">
                Start saving formulas
            </a>
        </div>
    </div>
</section>
@endsection
