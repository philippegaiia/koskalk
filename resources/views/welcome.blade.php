@extends('layouts.public')

@section('title', __('homepage.meta.title'))

@section('content')
@php
    $workspaceCapabilities = ['soap', 'cosmetics', 'portfolio', 'costing', 'compliance', 'production'];

    $previewOils = [
        ['key' => 'olive', 'percent' => '62%', 'weight' => '620 g'],
        ['key' => 'coconut', 'percent' => '20%', 'weight' => '200 g'],
        ['key' => 'shea', 'percent' => '10%', 'weight' => '100 g'],
        ['key' => 'castor', 'percent' => '8%', 'weight' => '80 g'],
    ];

    $previewResults = [
        ['key' => 'naoh', 'value' => '137.8 g', 'tone' => 'warning'],
        ['key' => 'water', 'value' => '310 g', 'tone' => 'accent'],
        ['key' => 'superfat', 'value' => '5%', 'tone' => 'neutral'],
    ];
@endphp

@push('head')
    <link rel="preload" as="image" href="{{ asset('images/public/soapkraft-hero-benches.webp') }}" type="image/webp" fetchpriority="high">
@endpush

<section id="top" aria-labelledby="hero-heading" class="relative isolate overflow-hidden bg-cream pt-[58px]">
    <img
        data-hero-background
        src="{{ asset('images/public/soapkraft-hero-benches.webp') }}"
        width="1774"
        height="887"
        alt=""
        class="absolute inset-0 -z-20 hidden h-full w-full object-cover object-center lg:block"
        aria-hidden="true"
        fetchpriority="high"
        decoding="async"
    >
    <div data-hero-veil class="absolute inset-0 -z-10 hidden bg-cream/34 lg:block"></div>
    <div class="sk-hero-radial absolute inset-0 -z-10 hidden lg:block"></div>
    <div class="absolute inset-x-0 top-[58px] -z-10 hidden h-48 bg-linear-to-b from-cream via-cream/64 to-transparent lg:block"></div>
    <div class="absolute inset-x-0 bottom-0 -z-10 hidden h-36 bg-linear-to-t from-cream via-cream/60 to-transparent lg:block"></div>

    <div class="mx-auto grid min-h-[calc(100svh-8rem)] max-w-[1180px] items-center gap-10 px-5 py-7 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.72fr)] lg:px-10 lg:py-12">
        <div class="max-w-[700px] text-center lg:text-left">
            <h1 id="hero-heading" data-hero-title class="font-display text-[2.35rem] text-ink-strong sm:text-[2.75rem] lg:text-[4.5rem]">
                {{ __('homepage.hero.title') }}
            </h1>

            <p class="mx-auto mt-4 max-w-[650px] text-sm leading-6 text-ink-soft sm:text-base sm:leading-8 lg:mx-0 lg:mt-6">
                {{ __('homepage.hero.description') }}
            </p>

            <div class="mt-6 flex flex-col justify-center gap-2 sm:flex-row sm:gap-3 lg:mt-8 lg:justify-start">
                @guest
                    <a href="{{ route('register') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-inverse no-underline shadow-sm transition hover:-translate-y-px hover:bg-accent-hover">
                        {{ __('homepage.actions.create_account') }}
                    </a>
                @else
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-inverse no-underline shadow-sm transition hover:-translate-y-px hover:bg-accent-hover">
                        {{ __('homepage.actions.open_workspace') }}
                    </a>
                @endguest

                <a href="{{ route('calculator') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-line-strong bg-panel px-6 py-3 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent-strong">
                    {{ __('homepage.actions.free_calculator') }}
                </a>
            </div>

            <div class="mt-3 text-xs leading-5 text-ink-soft lg:mt-5 lg:leading-6">
                <p>{{ __('homepage.hero.calculator_note') }}</p>
                @guest
                    <p>{{ __('homepage.hero.already_registered') }} <a href="{{ route('login') }}" class="font-semibold text-ink-strong underline decoration-line-strong underline-offset-4">{{ __('public.navigation.sign_in') }}</a></p>
                @endguest
            </div>

            <img
                src="{{ asset('images/public/soapkraft-hero-benches.webp') }}"
                width="1774"
                height="887"
                alt="{{ __('homepage.hero.image_alt') }}"
                class="mt-5 aspect-[11/5] w-full rounded-lg border border-line object-cover object-center shadow-sm lg:hidden"
                decoding="async"
            >
        </div>

        <aside aria-label="{{ __('homepage.preview.aria_label') }}" class="sk-card-elevation hidden w-full max-w-[440px] rounded-xl border border-line bg-panel p-5 text-left lg:block">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="ledger-eyebrow">{{ __('homepage.preview.eyebrow') }}</p>
                    <h2 class="font-display mt-2 text-[1.75rem] leading-tight text-ink-strong">{{ __('homepage.preview.title') }}</h2>
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
                        <p class="text-xs font-medium uppercase tracking-[0.08em]">{{ __("homepage.preview.results.{$result['key']}") }}</p>
                        <p class="mt-1 font-mono text-sm tabular-nums text-ink-strong">{{ $result['value'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 space-y-1.5">
                @foreach ($previewOils as $oil)
                    <div class="flex items-center gap-3 rounded-md bg-panel-strong/60 px-4 py-2.5">
                        <span class="min-w-0 flex-1 text-sm text-ink">{{ __("homepage.preview.oils.{$oil['key']}") }}</span>
                        <span class="w-12 text-right font-mono text-sm tabular-nums text-ink-soft">{{ $oil['percent'] }}</span>
                        <span class="w-16 text-right font-mono text-sm tabular-nums text-ink-strong">{{ $oil['weight'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-lg border border-line bg-surface px-4 py-3">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-xs uppercase tracking-[0.08em] text-ink-soft">{{ __('homepage.preview.ingredient_list.label') }}</span>
                    <span class="text-sm font-medium text-ink-strong">{{ __('homepage.preview.ingredient_list.value') }}</span>
                </div>
            </div>
        </aside>
    </div>
</section>

<section id="workspace" data-workspace-proof aria-labelledby="workspace-heading" class="bg-panel px-5 py-14 lg:px-10 lg:py-18">
    <div class="mx-auto max-w-[1180px]">
        <div class="grid gap-6 border-b border-line pb-10 lg:grid-cols-[0.8fr_1.2fr] lg:items-end">
            <h2 id="workspace-heading" class="max-w-[620px] text-3xl font-semibold leading-tight text-ink-strong lg:text-5xl">
                {{ __('homepage.workspace.heading') }}
            </h2>
            <p class="max-w-[650px] text-sm leading-7 text-ink-soft lg:justify-self-end">
                {{ __('homepage.workspace.description') }}
            </p>
        </div>

        <div class="grid md:grid-cols-2">
            @foreach ($workspaceCapabilities as $capability)
                <article class="border-b border-line py-7 md:px-6 md:odd:border-r md:odd:pl-0 md:even:pr-0">
                    <h3 class="text-xl font-semibold text-ink-strong">{{ __("homepage.capabilities.{$capability}.title") }}</h3>
                    <p class="mt-3 max-w-[520px] text-sm leading-7 text-ink-soft">{{ __("homepage.capabilities.{$capability}.body") }}</p>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section aria-label="{{ __('homepage.statement.aria_label') }}" class="forest-ledger px-5 py-12 text-center text-inverse lg:px-10 lg:py-16">
    <div class="mx-auto max-w-[900px]">
        <p class="text-3xl font-semibold leading-tight text-inverse lg:text-5xl">
            {{ __('homepage.statement.line_one') }}<br>
            {{ __('homepage.statement.line_two') }}
        </p>
        <p class="mt-5 text-sm font-medium leading-7 text-inverse-soft lg:text-base">
            {{ __('homepage.statement.experience') }}
        </p>
    </div>
</section>

<section aria-labelledby="cta-heading" class="bg-cream px-5 py-14 text-center lg:px-10 lg:py-18">
    <div class="mx-auto max-w-[680px]">
        <h2 id="cta-heading" class="text-3xl font-semibold leading-tight text-ink-strong lg:text-5xl">
            {{ __('homepage.cta.heading') }}
        </h2>
        @guest
            <p class="mx-auto mt-5 max-w-[560px] text-sm leading-7 text-ink-soft">
                {{ __('homepage.cta.guest_description') }}
            </p>
        @else
            <p class="mx-auto mt-5 max-w-[560px] text-sm leading-7 text-ink-soft">
                {{ __('homepage.cta.authenticated_description') }}
            </p>
        @endguest

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            @guest
                <a href="{{ route('register') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-inverse no-underline transition hover:-translate-y-px hover:bg-accent-hover">
                    {{ __('homepage.actions.create_account') }}
                </a>
            @else
                <a href="{{ route('dashboard') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-accent px-6 py-3 text-sm font-semibold text-inverse no-underline transition hover:-translate-y-px hover:bg-accent-hover">
                    {{ __('homepage.actions.open_workspace') }}
                </a>
            @endguest
            <a href="{{ route('calculator') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-line-strong bg-panel px-6 py-3 text-sm font-semibold text-ink-strong no-underline transition hover:border-accent hover:text-accent-strong">
                {{ __('homepage.actions.free_calculator') }}
            </a>
        </div>
    </div>
</section>
@endsection
