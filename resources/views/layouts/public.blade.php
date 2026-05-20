<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Soapkraft'))</title>

        <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
        <link href="https://api.fontshare.com/v2/css?f[]=instrument-sans@400,500,600,700&display=swap" rel="stylesheet">
        @stack('head')

        @vite(['resources/css/public.css', 'resources/js/public.js'])
    </head>
    <body class="min-h-screen overflow-x-hidden bg-cream font-sans text-ink-strong antialiased">

        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:text-inverse focus:no-underline">Skip to content</a>

        {{-- NAV --}}
        <nav class="sk-shell-line-b fixed top-0 left-0 right-0 z-50 h-[58px] bg-cream/92 backdrop-blur-[10px]">
            <div data-public-nav-inner class="mx-auto flex h-full w-full max-w-[1180px] items-center justify-between gap-3 px-4 min-[390px]:px-5 lg:gap-6 lg:px-10">
                <a href="{{ route('home') }}" class="flex min-h-11 shrink-0 items-center gap-2.5 no-underline">
                    <div class="flex h-[30px] w-[30px] items-center justify-center rounded-[7px] bg-accent-soft text-[0.6875rem] font-semibold text-accent-strong">SK</div>
                    <span class="text-lg font-semibold text-ink-strong">Soapkraft</span>
                </a>

                {{-- Desktop nav links --}}
                <div class="hidden md:flex gap-8 items-center">
                    <a href="#calculator" class="text-sm text-ink-soft no-underline transition hover:text-ink-strong">Calculator</a>
                    <a href="#benefits" class="text-sm text-ink-soft no-underline transition hover:text-ink-strong">Benefits</a>
                    <a href="#workspace" class="text-sm text-ink-soft no-underline transition hover:text-ink-strong">Workspace</a>
                    <a href="#comparison" class="text-sm text-ink-soft no-underline transition hover:text-ink-strong">Why Soapkraft</a>
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ route('recipes.create') }}" class="rounded-md bg-accent px-[18px] py-3 text-sm font-medium text-inverse no-underline transition hover:bg-accent-hover max-[360px]:hidden">Use calculator</a>
                    <button type="button" data-mobile-menu-toggle aria-controls="mobile-menu" aria-expanded="false" class="grid size-11 shrink-0 place-items-center rounded-lg text-ink-soft md:hidden">
                        <span class="sr-only">Toggle navigation menu</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                </div>
            </div>
        </nav>

        {{-- Mobile nav dropdown --}}
        <div data-mobile-menu-overlay class="fixed inset-0 top-[58px] z-40 hidden bg-ink-strong/20 md:hidden"></div>
        <div id="mobile-menu" data-mobile-menu class="sk-shell-line-b fixed top-[58px] left-0 right-0 z-40 hidden bg-cream px-5 py-4 md:hidden">
            <div class="flex flex-col gap-1">
                <a href="#calculator" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Calculator</a>
                <a href="#benefits" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Benefits</a>
                <a href="#workspace" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Workspace</a>
                <a href="#comparison" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Why Soapkraft</a>
            </div>
        </div>

        <main id="main-content">
            @yield('content')
        </main>

        {{-- FOOTER --}}
        <footer class="sk-shell-line-t bg-panel py-8">
            <div data-public-footer-inner class="mx-auto flex w-full max-w-[1180px] flex-col items-center justify-between gap-4 px-5 text-center lg:flex-row lg:px-10 lg:text-left">
                <p class="max-w-[28rem] text-sm text-ink-soft">Soapkraft — free soap calculator · soap & cosmetic formulation workspace</p>
                <div class="flex flex-wrap justify-center gap-x-5 gap-y-2 min-[390px]:gap-x-7">
                    <a href="#calculator" class="inline-flex min-h-11 items-center rounded px-1 py-2 text-sm font-medium text-ink-soft no-underline transition hover:text-ink-strong">Calculator</a>
                    <a href="#workspace" class="inline-flex min-h-11 items-center rounded px-1 py-2 text-sm font-medium text-ink-soft no-underline transition hover:text-ink-strong">Workspace</a>
                    <a href="#benefits" class="inline-flex min-h-11 items-center rounded px-1 py-2 text-sm font-medium text-ink-soft no-underline transition hover:text-ink-strong">Benefits</a>
                    <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded px-1 py-2 text-sm font-medium text-ink-soft no-underline transition hover:text-ink-strong">Dashboard</a>
                </div>
            </div>
        </footer>
    </body>
</html>
