@extends('layouts.app-shell')

@section('title', 'Dashboard · Koskalk')
@section('page_heading', 'Dashboard')

@section('content')
    <div class="space-y-8">
        <section class="grid gap-4 xl:grid-cols-[minmax(0,1.35fr)_22rem]">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Recipes</p>
                    <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">0</p>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">The dashboard now hands off to a real recipe workspace shell for soap formulation.</p>
                    <a href="{{ route('recipes.index') }}" wire:navigate class="mt-4 inline-flex rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">Open recipes</a>
                </div>
                <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Carrier oils</p>
                    <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Trusted</p>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Initial soap calculation will only pull from properly classified saponifiable carrier oils.</p>
                </div>
                <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-5">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Essential oils</p>
                    <p class="mt-4 text-4xl font-semibold tracking-[-0.04em] text-[var(--color-ink-strong)]">Growing</p>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">The platform catalog is expected to support a large, curated essential-oil library.</p>
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)] p-5">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Public shell direction</p>
                <h3 class="mt-3 text-2xl font-semibold text-[var(--color-ink-strong)]">Tailwind-driven, not generic SaaS UI</h3>
                <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]">
                    This dashboard is being built as the companion shell for a dense formulation workspace. The goal is a fast working surface, not card-heavy filler.
                </p>
                <a href="{{ route('recipes.create') }}" wire:navigate class="mt-5 inline-flex rounded-full bg-[var(--color-ink-strong)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Start a soap draft</a>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,0.9fr)]">
            <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="flex items-center justify-between border-b border-[var(--color-line)] px-5 py-4">
                    <div>
                        <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Planned workbench flow</p>
                        <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Formulation workspace map</h3>
                    </div>
                    <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Shell preview</span>
                </div>

                <div class="grid gap-px bg-[var(--color-line)] sm:grid-cols-2">
                    <div class="space-y-3 bg-white p-5">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Left column</p>
                        <div class="rounded-2xl bg-[var(--color-panel)] p-4">
                            <p class="font-medium text-[var(--color-ink-strong)]">Ingredient search</p>
                            <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Instant search, category ticks, and click-to-add interaction.</p>
                        </div>
                        <div class="rounded-2xl bg-[var(--color-panel)] p-4">
                            <p class="font-medium text-[var(--color-ink-strong)]">Role-based filters</p>
                            <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Carrier oils, essential oils, colorants, preservatives, additives.</p>
                        </div>
                    </div>
                    <div class="space-y-3 bg-white p-5">
                        <p class="text-xs font-semibold tracking-[0.16em] text-[var(--color-ink-soft)] uppercase">Right column</p>
                        <div class="rounded-2xl bg-[var(--color-panel)] p-4">
                            <p class="font-medium text-[var(--color-ink-strong)]">Formula table</p>
                            <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">Percent and weight editing, phase totals, and save-state visibility.</p>
                        </div>
                        <div class="rounded-2xl bg-[var(--color-panel)] p-4">
                            <p class="font-medium text-[var(--color-ink-strong)]">Soap engine block</p>
                            <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">KOH-first SAP, derived NaOH, water modes, glycerine, and live soap qualities.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white">
                <div class="border-b border-[var(--color-line)] px-5 py-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Decision board</p>
                    <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Domain details still being locked</h3>
                </div>

                <div class="space-y-3 p-5">
                    @foreach ([
                        'Fixed fatty-acid key set for carrier oils',
                        'Versioned soap-quality calculation strategy',
                        'Essential-oil enrichment path for allergens and compliance',
                        'Formulation page save workflow and navigation guard',
                    ] as $decision)
                        <div class="flex gap-3 rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3 text-sm text-[var(--color-ink-soft)]">
                            <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border border-[var(--color-line)] bg-white text-[11px] font-semibold text-[var(--color-ink-strong)]">•</span>
                            <span>{{ $decision }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
@endsection
