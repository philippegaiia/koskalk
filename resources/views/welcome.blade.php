@extends('layouts.public')

@section('title', 'Soapkraft — Free Soap Calculator & Formulation Workspace')

@section('content')
@php
    $calculatorOutputs = [
        ['label' => 'NaOH', 'value' => '137.8 g'],
        ['label' => 'Water', 'value' => '310 g'],
        ['label' => 'Superfat', 'value' => '5%'],
    ];

    $formulaRows = [
        ['name' => 'Olive oil', 'amount' => '620 g', 'percent' => '62%'],
        ['name' => 'Coconut oil', 'amount' => '200 g', 'percent' => '20%'],
        ['name' => 'Shea butter', 'amount' => '100 g', 'percent' => '10%'],
        ['name' => 'Additives', 'amount' => '80 g', 'percent' => '8%'],
    ];

    $benefits = [
        ['title' => 'Calculate fast', 'body' => 'Get lye, potash, water, and superfat numbers without opening a spreadsheet.'],
        ['title' => 'Build the full formula', 'body' => 'Keep oils, additives, phases, aromatic materials, and notes in one practical workspace.'],
        ['title' => 'Save what works', 'body' => 'Create a free workspace when a quick calculation becomes a formula you want to keep.'],
        ['title' => 'Check label signals', 'body' => 'See useful allergen and IFRA references while you prepare a formula for real use.'],
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
        ['competitors' => 'Lye and water result', 'soapkraft' => 'Lye, potash, water, full formula, additives, and saved recipe history'],
        ['competitors' => 'One-off calculation', 'soapkraft' => 'A reusable source of truth for the formulas you actually make'],
        ['competitors' => 'Generic ingredient rows', 'soapkraft' => 'Platform ingredients plus your own private ingredient library'],
        ['competitors' => 'Labeling handled elsewhere', 'soapkraft' => 'Allergen and IFRA references kept next to the formula'],
    ];

    $workflow = [
        ['step' => '01', 'title' => 'Use the calculator', 'body' => 'Choose oils, lye type, water mode, and superfat to get practical numbers quickly.'],
        ['step' => '02', 'title' => 'Add the whole formula', 'body' => 'Add additives, fragrance, phases, cosmetic ingredients, and notes without losing the soap math.'],
        ['step' => '03', 'title' => 'Save the version', 'body' => 'Create a free workspace when the formula matters enough to keep, revisit, or share.'],
        ['step' => '04', 'title' => 'Prepare the real product', 'body' => 'Review label signals, IFRA references, costing, and production details in one place.'],
    ];
@endphp

<section id="calculator" class="relative overflow-hidden bg-cream pt-[58px]">
    <div class="mx-auto grid max-w-[1180px] items-center gap-12 px-5 py-16 lg:grid-cols-[0.96fr_1.04fr] lg:px-10 lg:py-20">
        <div class="max-w-[620px]">
            <p class="mb-5 inline-flex rounded-full border border-line bg-panel px-3 py-1.5 font-mono text-[11px] uppercase tracking-[0.12em] text-ink-soft shadow-sm">
                Free soap calculator
            </p>

            <h1 class="font-serif text-[clamp(40px,6vw,74px)] font-medium leading-[1.02] text-ink-strong">
                Calculate soap. Save formulas. Manage your whole bench.
            </h1>

            <p class="mt-6 max-w-[560px] text-[16px] leading-8 text-ink-soft">
                Use the free calculator for lye, potash, water, and superfat. Create a free workspace when you want to save complete soap and cosmetic formulas with ingredients, labeling insights, costing, and history.
            </p>

            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('recipes.create') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-white no-underline shadow-[0_12px_24px_rgba(192,102,58,0.24)] transition hover:bg-accent-hover hover:-translate-y-px">
                    Use the free calculator
                </a>
                <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent">
                    Create free workspace
                </a>
            </div>

            <p class="mt-4 text-sm text-ink-soft">
                No account needed for quick calculations. A free account lets you save formulas and private ingredients.
            </p>
        </div>

        <div class="relative">
            <div class="rounded-[1.25rem] border border-line-strong bg-panel p-4 shadow-[0_28px_70px_rgba(59,55,47,0.14)]">
                <div class="flex items-center justify-between border-b border-line pb-3">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.12em] text-ink-soft">Soap calculator</p>
                        <p class="mt-1 font-serif text-xl text-ink-strong">Olive + shea bar</p>
                    </div>
                    <span class="rounded-full bg-accent-soft px-3 py-1 text-xs font-semibold text-accent-strong">Unsaved</span>
                </div>

                <div class="mt-5 grid gap-5 lg:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <p class="mb-3 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-soft">Formula</p>
                        <div class="space-y-2">
                            @foreach ($formulaRows as $row)
                                <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3 rounded-lg border border-line bg-field-muted px-3 py-2 text-sm">
                                    <span class="min-w-0 font-medium text-ink-strong">{{ $row['name'] }}</span>
                                    <span class="font-mono text-xs text-ink-soft">{{ $row['amount'] }}</span>
                                    <span class="font-mono text-xs text-accent-strong">{{ $row['percent'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <p class="mb-3 font-mono text-[10px] uppercase tracking-[0.12em] text-ink-soft">Result</p>
                        <div class="grid gap-2">
                            @foreach ($calculatorOutputs as $output)
                                <div class="rounded-lg bg-hero px-4 py-3 text-inverse">
                                    <p class="font-mono text-lg font-semibold">{{ $output['value'] }}</p>
                                    <p class="mt-0.5 font-mono text-[10px] uppercase tracking-[0.12em] text-inverse-muted">{{ $output['label'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 border-t border-line pt-5 sm:grid-cols-3">
                    <div class="rounded-lg bg-success-soft px-3 py-3">
                        <p class="font-mono text-[10px] uppercase tracking-[0.1em] text-success-strong">Label</p>
                        <p class="mt-1 text-sm font-medium text-ink-strong">Allergen signals</p>
                    </div>
                    <div class="rounded-lg bg-warning-soft px-3 py-3">
                        <p class="font-mono text-[10px] uppercase tracking-[0.1em] text-warning-strong">IFRA</p>
                        <p class="mt-1 text-sm font-medium text-ink-strong">Reference rates</p>
                    </div>
                    <div class="rounded-lg bg-accent-soft px-3 py-3">
                        <p class="font-mono text-[10px] uppercase tracking-[0.1em] text-accent-strong">Save</p>
                        <p class="mt-1 text-sm font-medium text-ink-strong">Free workspace</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section id="benefits" class="border-y border-line bg-panel">
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
            <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">Two useful ways in</p>
            <h2 class="mt-4 font-serif text-[clamp(32px,4vw,52px)] font-medium leading-[1.08] text-ink-strong">
                Quick when you only need numbers. Complete when the formula matters.
            </h2>
        </div>

        <div class="mt-12 grid gap-5 lg:grid-cols-2">
            <article class="rounded-[1rem] border border-line bg-panel p-6 lg:p-8">
                <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-ink-soft">No account</p>
                <h3 class="mt-3 font-serif text-3xl font-medium text-ink-strong">Use the free calculator</h3>
                <p class="mt-4 text-sm leading-7 text-ink-soft">For the person who came from search and just needs a reliable lye, potash, or water calculation right now.</p>
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
                <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">Free account</p>
                <h3 class="mt-3 font-serif text-3xl font-medium text-ink-strong">Save and manage formulas</h3>
                <p class="mt-4 text-sm leading-7 text-ink-soft">For the maker who wants a portfolio instead of loose calculator screenshots, notebooks, and duplicated spreadsheets.</p>
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
                    Because a calculator is not enough once you make real products.
                </h2>
                <p class="mt-5 text-sm leading-7 text-ink-soft">
                    Built by a seasoned formulator because the existing tools were incomplete. Soapkraft keeps the calculation, the formula, the ingredients, and the production context together.
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
            <p class="font-mono text-[11px] uppercase tracking-[0.12em] text-accent-strong">From quick math to source of truth</p>
            <h2 class="mt-4 font-serif text-[clamp(32px,4vw,52px)] font-medium leading-[1.08] text-ink-strong">
                Start with the number you need. Keep the formula when it becomes real.
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
            Use the calculator. Save it if it earns a place on your bench.
        </h2>
        <p class="mx-auto mt-5 max-w-[520px] text-sm leading-7 text-ink-soft">
            Quick calculations stay free. A free workspace gives you a place to keep the formulas you want to revisit.
        </p>

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('recipes.create') }}" class="inline-flex justify-center rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-white no-underline transition hover:bg-accent-hover hover:-translate-y-px">
                Use the free calculator
            </a>
            <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-lg border border-line-strong bg-panel px-6 py-3 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent">
                Create free workspace
            </a>
        </div>
    </div>
</section>
@endsection
