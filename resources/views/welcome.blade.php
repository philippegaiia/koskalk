@extends('layouts.public')

@section('title', 'Koskalk')

@section('content')
    @php
        $pillars = [
            [
                'title' => 'Your recipe portfolio',
                'body' => 'Store recipes with photos, notes, and version history — all in one place.',
                'icon' => '📋',
            ],
            [
                'title' => 'Precise calculations',
                'body' => 'SAP values, lye calculations, and costings you can trust.',
                'icon' => '⚗️',
            ],
            [
                'title' => 'Compliance included',
                'body' => 'Allergen summaries and IFRA compliance for every recipe.',
                'icon' => '✓',
            ],
            [
                'title' => 'Share with makers',
                'body' => 'Share ingredients and recipes with other Koskalk members.',
                'icon' => '🔗',
            ],
        ];
    @endphp

    <section class="relative overflow-hidden bg-[var(--color-hero)] text-white">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_18%_18%,rgba(206,164,99,0.24),transparent_28%),radial-gradient(circle_at_82%_24%,rgba(72,135,116,0.22),transparent_32%),linear-gradient(135deg,rgba(8,24,21,0.96),rgba(16,44,39,0.92)_58%,rgba(10,32,29,0.98))]"></div>
        <div class="absolute inset-y-0 right-0 hidden w-3/5 bg-[radial-gradient(circle_at_center,rgba(255,255,255,0.08),transparent_62%)] lg:block"></div>
        <div class="relative mx-auto grid min-h-[100svh] max-w-7xl items-end gap-12 px-6 pb-10 pt-28 lg:grid-cols-[minmax(0,0.74fr)_minmax(32rem,1fr)] lg:px-8 lg:pb-14 lg:pt-32">
            <div class="self-center space-y-8">
                <div class="space-y-5 animate-hero-rise">
                    <p class="text-xs font-semibold tracking-[0.24em] text-white/62 uppercase">Soap formulation workspace</p>

                    <div class="space-y-4">
                        <h1 class="text-6xl leading-none font-semibold text-white sm:text-7xl lg:text-[6.5rem]">Koskalk</h1>
                        <p class="max-w-2xl text-3xl leading-[1.08] font-medium tracking-[0.016em] text-white/94 sm:text-4xl lg:text-[3.35rem]">
                            Your soap recipes. Precise. Organized. Ready to share.
                        </p>
                    </div>

                    <p class="max-w-xl text-base leading-8 text-white/72 lg:text-lg">
                        Build your recipe portfolio with precise calculations, INCI labels, and allergen compliance built in.
                    </p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row animate-hero-rise animate-hero-rise-delay-1">
                    <a href="{{ route('register') }}" class="inline-flex justify-center rounded-full bg-white px-6 py-3 text-sm font-semibold text-[var(--color-hero)] transition duration-300 hover:-translate-y-0.5 hover:bg-[var(--color-panel-strong)] motion-reduce:hover:translate-y-0">
                        Start free
                    </a>
                    <a href="#preview" class="inline-flex justify-center rounded-full border border-white/14 bg-white/8 px-6 py-3 text-sm font-semibold text-white transition duration-300 hover:bg-white/14">
                        See an example
                    </a>
                </div>
            </div>

            <div class="relative flex min-h-[33rem] items-end lg:min-h-[42rem]">
                <div class="absolute inset-0 rounded-[2.75rem] bg-[radial-gradient(circle_at_40%_20%,rgba(206,164,99,0.26),transparent_38%),radial-gradient(circle_at_72%_78%,rgba(72,135,116,0.24),transparent_34%)] blur-3xl"></div>

                <!-- Image placeholder: Replace with recipe workspace screenshot -->
                <div class="relative w-full overflow-hidden rounded-[2.4rem] border border-white/10 bg-[linear-gradient(160deg,rgba(14,39,35,0.98),rgba(8,24,21,0.94))] shadow-[0_40px_120px_rgba(0,0,0,0.45)] animate-surface-float motion-reduce:animate-none lg:translate-x-10">
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-center text-white/40">
                            <p class="text-sm font-medium">Recipe workspace screenshot</p>
                            <p class="mt-1 text-xs text-white/30">Replace with your image</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-[var(--color-surface)] px-6 py-16 lg:px-8 lg:py-20">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-6 lg:grid-cols-4">
                @foreach ($pillars as $pillar)
                    <article class="rounded-xl bg-[var(--color-panel)] p-6 shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
                        <div class="mb-4 text-3xl">{{ $pillar['icon'] }}</div>
                        <h2 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ $pillar['title'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">{{ $pillar['body'] }}</p>
                    </article>
                @endforeach
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

    <section id="preview" class="bg-[var(--color-panel)] px-6 py-16 lg:px-8 lg:py-20">
        <div class="mx-auto max-w-7xl">
            <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
                <div class="space-y-6">
                    <p class="text-xs font-semibold tracking-[0.22em] text-[var(--color-ink-soft)] uppercase">Preview</p>
                    <h2 class="text-3xl font-semibold text-[var(--color-ink-strong)] lg:text-4xl">
                        Built for soapmakers
                    </h2>
                    <p class="text-base leading-7 text-[var(--color-ink-soft)]">
                        Every recipe lives in your portfolio with the chemistry details that matter — oils, lye ratios, fatty acid profiles, and costings.
                    </p>
                    <ul class="space-y-3 text-sm text-[var(--color-ink-soft)]">
                        <li class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-[var(--color-accent)]"></span>
                            Versioned recipe history you can always audit
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-[var(--color-accent)]"></span>
                            INCI labels generated automatically
                        </li>
                        <li class="flex items-start gap-3">
                            <span class="mt-1 h-2 w-2 rounded-full bg-[var(--color-accent)]"></span>
                            Allergen check built into every formula
                        </li>
                    </ul>
                    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--color-accent)] hover:text-[var(--color-accent-hover)] transition-colors">
                        Try the workbench
                        <span aria-hidden="true">→</span>
                    </a>
                </div>

                <div class="rounded-2xl bg-[var(--color-panel-strong)] p-4 shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
                    <div class="aspect-video rounded-xl bg-[var(--color-hero)] flex items-center justify-center">
                        <div class="text-center text-white/40">
                            <p class="text-sm font-medium">Recipe workbench screenshot</p>
                            <p class="mt-1 text-xs text-white/30">Replace with your image</p>
                        </div>
                    </div>
                </div>
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
                        Koskalk separates drafting, chemistry stewardship, and compliance review so each stage can stay focused instead of collapsing into one overloaded screen.
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
