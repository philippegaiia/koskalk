@extends('layouts.public')

@section('title', 'Soapkraft')

@section('content')
    @php
        $proofPoints = [
            ['label' => 'Calculation basis', 'text' => 'Carrier oils stay at the center of the first pass.'],
            ['label' => 'Chemistry posture', 'text' => 'KOH-first SAP data with derived NaOH and traceable fatty-acid logic.'],
            ['label' => 'Output discipline', 'text' => 'Versioned drafts first, compliance review second, export last.'],
        ];

        $pillars = [
            ['title' => 'Trusted chemistry', 'body' => 'INCI, soap INCI, SAP values, and fatty-acid profiles remain separate, explicit, and auditable.'],
            ['title' => 'Faster drafting', 'body' => 'The workbench is built for immediate recalculation instead of save-refresh-edit loops.'],
            ['title' => 'Cleaner handoff', 'body' => 'Allergens, IFRA, restrictions, and final label output belong in a deliberate closing pass.'],
        ];

        $workflow = [
            ['title' => 'Select the reaction core', 'body' => 'Pick carrier oils first, with chemistry fields visible instead of buried in one generic catalog list.'],
            ['title' => 'Tune the batch', 'body' => 'Adjust lye ratios, superfat, and phase weights while totals stay legible and responsive.'],
            ['title' => 'Layer the aromatic phase', 'body' => 'Add essential oils, fragrance oils, and additives without corrupting the core saponification logic.'],
            ['title' => 'Close with compliance', 'body' => 'Review allergens, IFRA categories, and final INCI outputs only when the formula itself is stable.'],
        ];

        $catalogPriorities = [
            'Carrier oils with INCI, CAS, soap INCI, KOH SAP, and fatty-acid profiles.',
            'Essential oils that can scale well past the first starter catalog.',
            'User-authored fragrance materials instead of platform-seeded fiction.',
            'Versioned recipes and ingredients that keep exports auditable.',
        ];
    @endphp

    <section class="relative overflow-hidden bg-[var(--color-hero)] text-white">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_18%_18%,rgba(206,164,99,0.24),transparent_28%),radial-gradient(circle_at_82%_24%,rgba(72,135,116,0.22),transparent_32%),linear-gradient(135deg,rgba(8,24,21,0.96),rgba(16,44,39,0.92)_58%,rgba(10,32,29,0.98))]"></div>
        <div class="absolute inset-y-0 right-0 hidden w-3/5 bg-[radial-gradient(circle_at_center,rgba(255,255,255,0.08),transparent_62%)] lg:block"></div>
        <div class="relative mx-auto grid min-h-[100svh] max-w-7xl items-end gap-12 px-6 pb-10 pt-28 lg:grid-cols-[minmax(0,0.74fr)_minmax(32rem,1fr)] lg:px-8 lg:pb-14 lg:pt-32">
            <div class="self-center space-y-8">
                <div class="space-y-5 animate-hero-rise">
                    <p class="text-xs font-semibold tracking-[0.24em] text-white/62 uppercase">soap and cosmetics formulation workspace</p>

                    <div class="space-y-4">
                        <p class="max-w-2xl text-3xl leading-[1.08] font-medium tracking-[0.016em] text-white/94 sm:text-4xl lg:text-[3.35rem]">
                            Fast drafting, clean chemistry, and a compliance-ready handoff.
                        </p>
                    </div>

                    <p class="max-w-xl text-base leading-8 text-white/72 lg:text-lg">
                        Built for soap and cosmetic formulation when carrier oils, SAP values, fatty-acid profiles, and version history need to stay legible under pressure.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row animate-hero-rise animate-hero-rise-delay-1">
                    <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-[var(--color-hero)] transition duration-300 hover:-translate-y-0.5 hover:bg-[var(--color-panel-strong)] motion-reduce:hover:translate-y-0">
                        Preview workspace
                    </a>
                    <a href="{{ route('recipes.create') }}" class="inline-flex justify-center rounded-full border border-white/14 bg-white/8 px-6 py-3 text-sm font-semibold text-white transition duration-300 hover:bg-white/14">
                        Start a soap formula
                    </a>
                </div>

                <div class="grid gap-5 border-t border-white/10 pt-6 text-sm text-white/72 sm:grid-cols-3 animate-hero-rise animate-hero-rise-delay-2">
                    @foreach ($proofPoints as $point)
                        <div class="space-y-2">
                            <p class="text-[11px] font-semibold tracking-[0.22em] text-white/45 uppercase">{{ $point['label'] }}</p>
                            <p class="leading-6">{{ $point['text'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="relative flex min-h-[33rem] items-end lg:min-h-[42rem]">
                <div class="absolute inset-0 rounded-[2.75rem] bg-[radial-gradient(circle_at_40%_20%,rgba(206,164,99,0.26),transparent_38%),radial-gradient(circle_at_72%_78%,rgba(72,135,116,0.24),transparent_34%)] blur-3xl"></div>

                <div class="relative w-full overflow-hidden rounded-[2.4rem] border border-white/10 bg-[linear-gradient(160deg,rgba(14,39,35,0.98),rgba(8,24,21,0.94))] shadow-[0_40px_120px_rgba(0,0,0,0.45)] animate-surface-float motion-reduce:animate-none lg:translate-x-10">
                    <div class="absolute inset-0 bg-[linear-gradient(180deg,rgba(255,255,255,0.08),transparent_34%)]"></div>

                    <div class="grid min-h-[33rem] gap-px bg-white/8 lg:grid-cols-[minmax(0,1.4fr)_19rem]">
                        <div class="relative space-y-5 bg-[rgba(6,18,16,0.88)] p-5 sm:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/8 pb-4">
                                <div>
                                    <p class="text-[11px] font-semibold tracking-[0.22em] text-white/42 uppercase">Live draft</p>
                                    <h2 class="mt-2 text-2xl font-semibold text-white">Olive + Laurel Bar</h2>
                                </div>

                                <div class="flex items-center gap-2 text-xs text-white/56">
                                    <span class="rounded-full border border-white/12 px-3 py-1">Unsaved changes</span>
                                    <span class="rounded-full border border-[rgba(206,164,99,0.3)] bg-[rgba(206,164,99,0.12)] px-3 py-1 text-[var(--color-glow)]">Soap</span>
                                </div>
                            </div>

                            <div class="grid gap-3 text-sm text-white/78">
                                @foreach ([
                                    ['material' => 'Olive Oil', 'phase' => 'Reaction core', 'percent' => '62.0%', 'weight' => '620 g'],
                                    ['material' => 'Coconut Oil', 'phase' => 'Reaction core', 'percent' => '20.0%', 'weight' => '200 g'],
                                    ['material' => 'Shea Butter', 'phase' => 'Reaction core', 'percent' => '10.0%', 'weight' => '100 g'],
                                    ['material' => 'Laurel Berry Oil', 'phase' => 'Reaction core', 'percent' => '8.0%', 'weight' => '80 g'],
                                ] as $row)
                                    <div class="grid gap-3 rounded-[1.5rem] border border-white/7 bg-white/4 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_8rem_5rem] sm:items-center">
                                        <div class="min-w-0">
                                            <p class="truncate font-medium text-white">{{ $row['material'] }}</p>
                                            <p class="mt-1 text-xs text-white/46">{{ $row['phase'] }}</p>
                                        </div>
                                        <p class="text-sm text-white/72 sm:text-right">{{ $row['percent'] }}</p>
                                        <p class="text-sm font-medium text-white sm:text-right">{{ $row['weight'] }}</p>
                                    </div>
                                @endforeach
                            </div>

                            <div class="grid gap-px overflow-hidden rounded-[1.75rem] border border-white/8 bg-white/8 sm:grid-cols-3">
                                <div class="bg-white/5 px-4 py-4">
                                    <p class="text-[11px] font-semibold tracking-[0.18em] text-white/40 uppercase">Lye</p>
                                    <p class="mt-2 text-lg font-semibold text-white">137.8 g NaOH</p>
                                </div>
                                <div class="bg-white/5 px-4 py-4">
                                    <p class="text-[11px] font-semibold tracking-[0.18em] text-white/40 uppercase">Liquid</p>
                                    <p class="mt-2 text-lg font-semibold text-white">310 g water</p>
                                </div>
                                <div class="bg-white/5 px-4 py-4">
                                    <p class="text-[11px] font-semibold tracking-[0.18em] text-white/40 uppercase">Superfat</p>
                                    <p class="mt-2 text-lg font-semibold text-white">5.0%</p>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-5 bg-[rgba(244,236,226,0.96)] p-5 text-[var(--color-ink-strong)] sm:p-6">
                            <div class="border-b border-[var(--color-line)] pb-4">
                                <p class="text-[11px] font-semibold tracking-[0.22em] text-[var(--color-ink-soft)] uppercase">Chemistry rail</p>
                                <h2 class="mt-2 text-xl font-semibold">Live properties</h2>
                            </div>

                            <div class="space-y-4">
                                @foreach ([
                                    ['label' => 'Hardness', 'value' => '43', 'tone' => 'bg-[rgba(72,135,116,0.14)]'],
                                    ['label' => 'Cleansing', 'value' => '17', 'tone' => 'bg-[rgba(206,164,99,0.18)]'],
                                    ['label' => 'Conditioning', 'value' => '56', 'tone' => 'bg-[rgba(72,135,116,0.14)]'],
                                ] as $metric)
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="text-[var(--color-ink-soft)]">{{ $metric['label'] }}</span>
                                            <span class="font-semibold text-[var(--color-ink-strong)]">{{ $metric['value'] }}</span>
                                        </div>
                                        <div class="h-2 overflow-hidden rounded-full bg-black/6">
                                            <div class="h-full rounded-full {{ $metric['tone'] }}" style="width: {{ min(((int) $metric['value']) + 20, 100) }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="space-y-3 border-t border-[var(--color-line)] pt-5 text-sm leading-6 text-[var(--color-ink-soft)]">
                                <p class="font-medium text-[var(--color-ink-strong)]">Compliance stays separate.</p>
                                <p>Allergens, IFRA categories, and final label output wait for a deliberate review instead of leaking into core drafting.</p>
                            </div>

                            <div class="rounded-[1.6rem] bg-[var(--color-hero)] px-4 py-4 text-sm text-white">
                                <p class="text-[11px] font-semibold tracking-[0.18em] text-white/48 uppercase">Current posture</p>
                                <p class="mt-2 leading-6">Trusted data before flashy UI. The interface earns density only where the chemistry needs it.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="border-y border-[var(--color-line)] bg-[var(--color-panel)]">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="grid lg:grid-cols-3">
                @foreach ($pillars as $pillar)
                    <article class="border-b border-[var(--color-line)] px-0 py-8 last:border-b-0 lg:border-b-0 lg:px-8 lg:py-10 {{ $loop->first ? 'lg:pl-0' : '' }} {{ $loop->last ? 'lg:pr-0' : 'lg:border-r' }}">
                        <p class="text-[11px] font-semibold tracking-[0.22em] text-[var(--color-ink-soft)] uppercase">0{{ $loop->iteration }}</p>
                        <h2 class="mt-4 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $pillar['title'] }}</h2>
                        <p class="mt-4 max-w-sm text-sm leading-7 text-[var(--color-ink-soft)]">{{ $pillar['body'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-[var(--color-surface)] px-6 py-18 lg:px-8 lg:py-24">
        <div class="mx-auto grid max-w-7xl gap-14 lg:grid-cols-[minmax(0,0.9fr)_minmax(24rem,1fr)]">
            <div class="space-y-8">
                <div class="space-y-5">
                    <p class="text-xs font-semibold tracking-[0.22em] text-[var(--color-ink-soft)] uppercase">Workflow</p>
                    <h2 class="max-w-xl text-4xl leading-none font-semibold text-[var(--color-ink-strong)] lg:text-5xl">
                        A calmer route from raw material to finished label.
                    </h2>
                    <p class="max-w-xl text-base leading-8 text-[var(--color-ink-soft)] lg:text-lg">
                        Soapkraft separates drafting, chemistry stewardship, and compliance review so each stage can stay focused instead of collapsing into one overloaded screen.
                    </p>
                </div>

                <div class="border-t border-[var(--color-line)]">
                    @foreach ($catalogPriorities as $priority)
                        <div class="flex items-start gap-4 border-b border-[var(--color-line)] py-5">
                            <span class="mt-1 h-2.5 w-2.5 rounded-full bg-[var(--color-accent)]"></span>
                            <p class="max-w-xl text-sm leading-7 text-[var(--color-ink-soft)]">{{ $priority }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="lg:sticky lg:top-24">
                <div class="overflow-hidden rounded-[2.25rem] border border-[var(--color-line-hero)] bg-[var(--color-hero)] text-white">
                    @foreach ($workflow as $step)
                        <div class="flex gap-4 border-b border-white/8 px-6 py-6 last:border-b-0">
                            <span class="grid size-10 shrink-0 place-items-center rounded-full border border-white/10 bg-white/6 text-sm font-semibold">{{ $loop->iteration }}</span>

                            <div>
                                <h3 class="text-lg font-semibold text-white">{{ $step['title'] }}</h3>
                                <p class="mt-2 text-sm leading-7 text-white/68">{{ $step['body'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="px-6 pb-16 lg:px-8 lg:pb-24">
        <div class="mx-auto overflow-hidden rounded-[2.5rem] bg-[linear-gradient(135deg,rgba(12,33,29,0.98),rgba(24,61,52,0.96))] px-6 py-10 text-white lg:max-w-7xl lg:px-10 lg:py-12">
            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                <div>
                    <p class="text-xs font-semibold tracking-[0.22em] text-white/46 uppercase">Now shipping</p>
                    <h2 class="mt-4 max-w-3xl text-4xl leading-none font-semibold text-white lg:text-5xl">
                        Open the workspace, pressure-test the flow, and push the next build.
                    </h2>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-white/68 lg:text-base">
                        The app shell is already live. The next gains come from tightening the drafting rhythm and keeping the chemistry clean as the catalog grows.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row lg:flex-col">
                    <a href="{{ route('dashboard') }}" class="inline-flex justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-[var(--color-hero)] transition duration-300 hover:-translate-y-0.5 hover:bg-[var(--color-panel-strong)] motion-reduce:hover:translate-y-0">
                        Open workspace
                    </a>
                    <a href="/admin" class="inline-flex justify-center rounded-full border border-white/14 bg-white/8 px-6 py-3 text-sm font-semibold text-white transition duration-300 hover:bg-white/14">
                        Open admin
                    </a>
                </div>
            </div>
        </div>
    </section>
@endsection
