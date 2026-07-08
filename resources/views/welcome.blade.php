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

{{-- ===================== HERO ===================== --}}
<section id="calculator" aria-labelledby="hero-heading" class="relative isolate overflow-hidden bg-cream pt-[58px]">
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
    <div class="absolute inset-x-0 top-[58px] -z-10 h-56 bg-linear-to-b from-cream via-cream/64 to-transparent"></div>
    <div class="absolute inset-x-0 bottom-0 -z-10 h-48 bg-linear-to-t from-cream via-cream/60 to-transparent"></div>
    <div class="absolute inset-y-0 left-0 -z-10 w-1/4 bg-linear-to-r from-cream/60 to-transparent"></div>
    <div class="absolute inset-y-0 right-0 -z-10 w-1/4 bg-linear-to-l from-cream/60 to-transparent"></div>

    <div class="mx-auto grid min-h-[calc(100svh-58px)] max-w-[1180px] items-center gap-12 px-5 py-14 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.72fr)] lg:px-10 lg:py-16">
        <div class="max-w-[720px] text-center lg:text-left">
            <p class="ledger-eyebrow al-rise">Free soap calculator · soap &amp; cosmetic formulation workspace</p>

            <h1 id="hero-heading" data-hero-title class="font-display al-rise al-rise-1 mt-5 text-[clamp(2.75rem,6vw,5rem)] text-ink-strong">
                Your formula, your ingredients, your bench — in one place.
            </h1>

            <p class="al-rise al-rise-2 mx-auto mt-6 max-w-[620px] text-base leading-8 text-ink-soft lg:mx-0">
                Start with a quick lye calculation, or build a complete soap or cosmetic formula with phases, ingredients, costs, label signals, and history kept together.
            </p>

            <div class="al-rise al-rise-3 mt-8 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
                <a href="{{ route('calculator') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3.5 text-sm font-semibold text-inverse no-underline shadow-sm transition hover:-translate-y-px hover:bg-accent-hover">
                    Use the free calculator
                </a>
                <a href="{{ route('register') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3.5 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent-strong">
                    Start saving formulas
                </a>
            </div>

            <p class="al-rise al-rise-4 mt-5 text-xs leading-6 text-ink-soft">
                No account needed to calculate. Sign in appears only when you choose to save formulas, ingredients, and history.
            </p>
        </div>

        {{-- Live calculation ledger card --}}
        <aside aria-label="Example soap calculation" class="al-rise al-rise-3 sk-card-elevation mx-auto w-full max-w-[440px] rounded-xl border border-line bg-panel p-5 text-left lg:mx-0">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="ledger-eyebrow">Live calculation preview</p>
                    <h2 class="font-display mt-2 text-[1.75rem] leading-tight text-ink-strong">Winter olive bar</h2>
                </div>
                <span class="rounded-full bg-accent-soft px-3 py-1 font-mono text-xs font-medium tabular-nums text-accent-strong">1000 g</span>
            </div>

            <div class="ledger-rule mt-4"></div>

            <div class="mt-4 grid grid-cols-3 gap-2">
                @foreach ($previewResults as $result)
                    <div @class([
                        'rounded-lg px-3 py-2',
                        'bg-accent-soft text-accent-strong' => $result['tone'] === 'warning',
                        'bg-sage-pale text-botanical' => $result['tone'] === 'accent',
                        'bg-panel-strong text-ink-soft' => $result['tone'] === 'neutral',
                    ])>
                        <p class="text-xs font-medium uppercase tracking-[0.08em]">{{ $result['label'] }}</p>
                        <p class="mt-1 font-mono text-sm tabular-nums text-ink-strong">{{ $result['value'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 space-y-1.5">
                @foreach ($previewOils as $oil)
                    <div class="flex items-center gap-3 rounded-md bg-panel-strong/60 px-4 py-2.5">
                        <span class="min-w-0 flex-1 text-sm text-ink">{{ $oil['name'] }}</span>
                        <span class="w-12 text-right font-mono text-sm tabular-nums text-ink-soft">{{ $oil['percent'] }}</span>
                        <span class="w-16 text-right font-mono text-sm tabular-nums text-ink-strong">{{ $oil['weight'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-lg border border-line bg-surface px-4 py-3">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-xs uppercase tracking-[0.08em] text-ink-soft">Label preview</span>
                    <span class="text-sm font-medium text-ink-strong">Olivate · Cocoate · Shea butter</span>
                </div>
            </div>
        </aside>
    </div>
</section>

{{-- ===================== BENEFITS ===================== --}}
<section id="benefits" aria-labelledby="benefits-heading" class="bg-panel px-5 py-14 lg:px-10 lg:py-16">
    <h2 id="benefits-heading" class="sr-only">Benefits</h2>
    <div class="mx-auto grid max-w-[1180px] gap-x-8 gap-y-10 md:grid-cols-2 lg:grid-cols-4">
        @foreach ($benefits as $benefit)
            <article>
                <p class="ledger-eyebrow">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</p>
                <h3 class="font-display mt-3 text-[1.625rem] leading-tight text-ink-strong">{{ $benefit['title'] }}</h3>
                <div class="ledger-rule mt-4"></div>
                <p class="mt-4 text-sm leading-6 text-ink-soft">{{ $benefit['body'] }}</p>
            </article>
        @endforeach
    </div>
</section>

{{-- ===================== TWO WAYS IN ===================== --}}
<section id="workspace" aria-labelledby="workspace-heading" class="bg-surface px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="grid gap-8 lg:grid-cols-[0.84fr_1.16fr] lg:items-end">
            <div class="max-w-[680px]">
                <p class="ledger-eyebrow">Two ways in — one place to land</p>
                <h2 id="workspace-heading" class="font-display mt-4 text-[clamp(2rem,4vw,3.25rem)] text-ink-strong">
                    Quick when you just need a number. Complete when the formula matters.
                </h2>
            </div>
            <p class="text-sm leading-7 text-ink-soft lg:max-w-[420px]">
                The free calculator stays close to the top. The workspace appears only when the formula deserves history, costing, private ingredients, and review.
            </p>
        </div>

        <div class="mt-12 grid gap-5 lg:grid-cols-2">
            {{-- Free calculator card --}}
            <article class="sk-card-elevation rounded-xl border border-line bg-panel p-6 lg:p-8">
                <p class="ledger-eyebrow">No account</p>
                <h3 class="font-display mt-3 text-[2rem] leading-tight text-ink-strong">Use the free calculator</h3>
                <p class="mt-4 text-sm leading-7 text-ink-soft">You searched for a lye calculator. Here it is — no signup, no friction. Get your numbers and go.</p>
                <div class="mt-6 space-y-3">
                    @foreach ($calculatorItems as $item)
                        <p class="flex gap-3 text-sm text-ink">
                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-accent"></span>
                            <span>{{ $item }}</span>
                        </p>
                    @endforeach
                </div>
            </article>

            {{-- Workspace card — deep forest, reversed --}}
            <article class="forest-ledger rounded-xl p-6 text-inverse lg:p-8">
                <p class="ledger-eyebrow" style="--ledger-eyebrow-color: var(--color-sage-light)">Account</p>
                <h3 class="font-display mt-3 text-[2rem] leading-tight text-inverse">Save your soap and cosmetic formulas</h3>
                <p class="mt-4 text-sm leading-7 text-inverse-soft">For the maker who's tired of losing good soap batches, cream formulas, and production notes to screenshots, notebooks, and duplicated spreadsheets.</p>
                <div class="mt-6 space-y-3">
                    @foreach ($workspaceItems as $item)
                        <p class="flex gap-3 text-sm text-inverse">
                            <span class="mt-2 size-1.5 shrink-0 rounded-full bg-amber"></span>
                            <span>{{ $item }}</span>
                        </p>
                    @endforeach
                </div>
            </article>
        </div>
    </div>
</section>

{{-- ===================== COMPARISON ===================== --}}
<section id="comparison" aria-labelledby="comparison-heading" class="bg-cream px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="grid gap-10 lg:grid-cols-[0.75fr_1.25fr]">
            <div>
                <p class="ledger-eyebrow">Why use Soapkraft?</p>
                <h2 id="comparison-heading" class="font-display mt-4 text-[clamp(2rem,4vw,3.25rem)] text-ink-strong">
                    A calculator is useful. A bench record is better.
                </h2>
                <p class="mt-5 text-sm leading-7 text-ink-soft">
                    When your formulas start to matter — for customers, records, costing, or labels — you need more than a lye number. Soapkraft keeps soap calculations, cosmetic phases, ingredients, costing, label signals, and formula history together.
                </p>
            </div>

            <div class="sk-card-elevation rounded-xl border border-line bg-panel p-4">
                <div class="grid grid-cols-2 gap-4 border-b border-line px-3 pb-3 text-xs font-semibold uppercase tracking-[0.1em] text-ink-soft">
                    <span>Most calculators</span>
                    <span class="text-botanical">Soapkraft</span>
                </div>

                <div class="mt-3 space-y-2">
                    @foreach ($comparisonRows as $row)
                        <div class="grid gap-4 rounded-lg bg-panel-strong/60 px-4 py-4 sm:grid-cols-2">
                            <p class="text-sm leading-6 text-ink-soft">{{ $row['competitors'] }}</p>
                            <p class="text-sm font-medium leading-6 text-ink-strong">{{ $row['soapkraft'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ===================== WORKFLOW ===================== --}}
<section id="workflow" aria-labelledby="workflow-heading" class="bg-panel px-5 py-20 lg:px-10">
    <div class="mx-auto max-w-[1180px]">
        <div class="max-w-[660px]">
            <p class="ledger-eyebrow">From quick calculation to source of truth</p>
            <h2 id="workflow-heading" class="font-display mt-4 text-[clamp(2rem,4vw,3.25rem)] text-ink-strong">
                Start with the number you need. Stay when the formula is worth keeping.
            </h2>
        </div>

        <div class="mt-12 grid gap-px overflow-hidden rounded-xl border border-line bg-line md:grid-cols-2 lg:grid-cols-4">
            @foreach ($workflow as $item)
                <article class="bg-panel px-5 py-6">
                    <p class="font-mono text-sm font-semibold tabular-nums text-accent-strong">{{ $item['step'] }}</p>
                    <h3 class="font-display mt-4 text-[1.5rem] leading-tight text-ink-strong">{{ $item['title'] }}</h3>
                    <p class="mt-3 text-sm leading-7 text-ink-soft">{{ $item['body'] }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ===================== FOUNDER NOTE + STATS ===================== --}}
<section aria-label="Founder note and production stats" class="forest-ledger px-5 py-16 text-inverse lg:px-10">
    <div class="mx-auto grid max-w-[980px] items-center gap-10 lg:grid-cols-[1fr_auto]">
        <div>
            <p class="ledger-eyebrow" style="--ledger-eyebrow-color: var(--color-sage-light)">Built from the bench</p>
            <blockquote class="font-display mt-4 text-[clamp(1.625rem,4vw,2.625rem)] leading-[1.15] text-inverse">
                "I built the soap calculator I needed when formulas became production records."
            </blockquote>
            <p class="mt-5 max-w-[620px] text-sm leading-7 text-inverse-soft">
                After years of real soap and cosmetics production, Soapkraft is designed as one place of truth for the recipes, ingredients, costs, and label information that matter after the first calculation.
            </p>
        </div>

        <div class="grid grid-cols-3 gap-6 border-t border-inverse/15 pt-8 text-center lg:border-l lg:border-t-0 lg:pl-10 lg:text-left">
            <div>
                <p class="font-mono text-4xl font-semibold tabular-nums text-amber">1M+</p>
                <p class="mt-1 text-xs uppercase tracking-[0.08em] text-inverse-muted">bars produced</p>
            </div>
            <div>
                <p class="font-mono text-4xl font-semibold tabular-nums text-amber">16</p>
                <p class="mt-1 text-xs uppercase tracking-[0.08em] text-inverse-muted">years formulating</p>
            </div>
            <div>
                <p class="font-mono text-4xl font-semibold tabular-nums text-amber">2</p>
                <p class="mt-1 text-xs uppercase tracking-[0.08em] text-inverse-muted">production countries</p>
            </div>
        </div>
    </div>
</section>

{{-- ===================== FINAL CTA ===================== --}}
<section aria-labelledby="cta-heading" class="bg-cream px-5 py-20 text-center lg:px-10">
    <div class="mx-auto max-w-[680px]">
        <p class="ledger-eyebrow">Start simple</p>
        <h2 id="cta-heading" class="font-display mt-4 text-[clamp(2.125rem,5vw,3.625rem)] leading-[1.08] text-ink-strong">
            Use the calculator. Save the formulas that earn a place on your bench.
        </h2>
        <p class="mx-auto mt-5 max-w-[520px] text-sm leading-7 text-ink-soft">
            Quick soap calculations stay free. Your workspace is where you keep the soap and cosmetic formulas, ingredients, and history you choose to revisit.
        </p>

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('calculator') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3.5 text-sm font-semibold text-inverse no-underline transition hover:-translate-y-px hover:bg-accent-hover">
                Use the free calculator
            </a>
            <a href="{{ route('register') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3.5 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent-strong">
                Start saving formulas
            </a>
        </div>
    </div>
</section>
@endsection
