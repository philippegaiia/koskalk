@extends('layouts.public')

@section('title', 'Koskalk')

@section('content')
    <section class="mx-auto grid max-w-7xl gap-10 px-6 py-14 lg:grid-cols-[minmax(0,1.15fr)_22rem] lg:px-8 lg:py-18">
        <div class="space-y-8">
            <div class="inline-flex items-center gap-2 rounded-full border border-[var(--color-line-strong)] bg-white px-4 py-2 text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">
                Soap Formulation Workspace
                <span class="rounded-full bg-[var(--color-accent-soft)] px-2 py-1 text-[10px] text-[var(--color-accent-strong)]">Sprint 1 foundation</span>
            </div>

            <div class="space-y-5">
                <h1 class="max-w-4xl text-5xl leading-none font-semibold tracking-[-0.05em] text-[var(--color-ink-strong)] lg:text-7xl">
                    Clearer than SoapCalc. Structured for real formulation work.
                </h1>
                <p class="max-w-3xl text-lg leading-8 text-[var(--color-ink-soft)] lg:text-xl">
                    Koskalk is being built as a dense professional workspace for soap and cosmetic formulation: fast carrier-oil selection, trusted SAP and fatty-acid data, versioned recipes, and a separate compliance step.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-3xl border border-[var(--color-line)] bg-white p-5">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Carrier oils first</p>
                    <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]">Initial soap calculation stays focused on saponifiable carrier oils only.</p>
                </div>
                <div class="rounded-3xl border border-[var(--color-line)] bg-white p-5">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Versioned chemistry</p>
                    <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]">INCI, soap INCI, SAP, and fatty-acid profiles are kept separate and traceable.</p>
                </div>
                <div class="rounded-3xl border border-[var(--color-line)] bg-white p-5">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Local-speed editing</p>
                    <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]">The formulation workbench will recalculate in the browser without saving every keystroke.</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-panel-strong)]">
                <div class="grid gap-px bg-[var(--color-line)] lg:grid-cols-[1.15fr_0.85fr]">
                    <div class="space-y-6 bg-white p-7">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">What the public app should feel like</p>
                                <h2 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">Dense, immediate, and trustworthy</h2>
                            </div>
                            <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]">Blade + Livewire + Alpine</span>
                        </div>

                        <div class="grid gap-4 text-sm text-[var(--color-ink-soft)] sm:grid-cols-2">
                            <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="font-medium text-[var(--color-ink-strong)]">Ingredient picking</p>
                                <p class="mt-2 leading-6">Search and click-to-add interactions, separated by ingredient role instead of one giant flat list.</p>
                            </div>
                            <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="font-medium text-[var(--color-ink-strong)]">Formula table</p>
                                <p class="mt-2 leading-6">Phase-aware rows with percent and weight editing, visible totals, and unsaved-state feedback.</p>
                            </div>
                            <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="font-medium text-[var(--color-ink-strong)]">Soap engine</p>
                                <p class="mt-2 leading-6">KOH-first SAP data, derived NaOH, produced glycerine, and live fatty-acid-driven properties.</p>
                            </div>
                            <div class="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4">
                                <p class="font-medium text-[var(--color-ink-strong)]">Compliance step</p>
                                <p class="mt-2 leading-6">A deliberate review page for allergens, IFRA, restrictions, and final INCI outputs.</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6 bg-[var(--color-panel)] p-7">
                        <div>
                            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Catalog priorities</p>
                            <h2 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">Trusted data before flashy UI</h2>
                        </div>

                        <div class="space-y-3">
                            @foreach ([
                                'Carrier oils with INCI, CAS, soap INCI, KOH SAP, and fatty-acid profile',
                                'Essential oil library that can keep growing well past the first 100 records',
                                'Fragrance oils treated as user-authored rather than starter-seeded platform data',
                                'Versioned ingredients and recipes so exports and compliance stay auditable',
                            ] as $priority)
                                <div class="flex gap-3 rounded-2xl border border-[var(--color-line)] bg-white p-4 text-sm text-[var(--color-ink-soft)]">
                                    <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-[var(--color-accent)] text-[11px] font-semibold text-white">+</span>
                                    <p class="leading-6">{{ $priority }}</p>
                                </div>
                            @endforeach
                        </div>

                        <a href="{{ route('dashboard') }}" class="inline-flex rounded-full bg-[var(--color-accent)] px-5 py-3 text-sm font-semibold text-white transition hover:bg-[var(--color-accent-strong)]">Preview The App Shell</a>
                    </div>
                </div>
            </div>
        </div>

        <aside class="space-y-4">
            <div class="rounded-[2rem] border border-[var(--color-line-strong)] bg-[var(--color-ink-strong)] p-6 text-[var(--color-panel)]">
                <p class="text-xs font-semibold tracking-[0.18em] uppercase text-white/70">Build track</p>
                <h2 class="mt-3 text-2xl font-semibold text-white">Current implementation path</h2>
                <div class="mt-6 space-y-3">
                    @foreach ([
                        'Lock carrier-oil chemistry strategy',
                        'Finalize KOH-first SAP handling',
                        'Tighten SAP/admin forms around fixed chemistry keys',
                        'Build the dashboard and formulation workbench shell',
                    ] as $step => $label)
                        <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/6 px-4 py-3 text-sm">
                            <span class="grid size-7 place-items-center rounded-full border border-white/15 bg-white/10 text-xs font-semibold">{{ $step + 1 }}</span>
                            <span>{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-[2rem] border border-[var(--color-line)] bg-white p-6">
                <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Reference posture</p>
                <p class="mt-3 text-sm leading-6 text-[var(--color-ink-soft)]">
                    SoapCalc remains the speed benchmark, but Koskalk is aiming for better structure, better chemistry stewardship, and a stronger path to compliance outputs.
                </p>
            </div>
        </aside>
    </section>
@endsection
