<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Soapkraft'))</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400;1,500;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-cream text-ink-strong antialiased overflow-x-hidden" style="font-family: 'DM Sans', sans-serif;">

        {{-- NAV --}}
        <nav class="fixed top-0 left-0 right-0 z-50 h-[58px] flex items-center justify-between px-5 lg:px-14 bg-cream/92 backdrop-blur-[10px] border-b border-line">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 no-underline">
                <div class="w-[30px] h-[30px] rounded-[7px] bg-accent-soft flex items-center justify-center font-mono text-[11px] font-medium text-accent-strong">SK</div>
                <span class="font-serif text-base text-ink-strong">Soapkraft</span>
            </a>

            <div class="hidden md:flex gap-8 items-center">
                <a href="#calculator" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Calculator</a>
                <a href="#benefits" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Benefits</a>
                <a href="#workspace" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Free workspace</a>
                <a href="#comparison" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Why Soapkraft</a>
            </div>

            <a href="{{ route('recipes.create') }}" class="text-[13px] px-[18px] py-[7px] rounded-md bg-accent text-white no-underline font-medium transition hover:bg-accent-hover">Use calculator</a>
        </nav>

        <main>
            @yield('content')
        </main>

        {{-- FOOTER --}}
        <footer class="bg-panel border-t border-line py-8 px-5 lg:px-20 flex flex-col lg:flex-row items-center justify-between gap-4">
            <p class="font-serif text-sm text-ink-soft">Soapkraft — free soap calculator & formulation workspace</p>
            <div class="flex gap-7">
                <a href="#calculator" class="text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Calculator</a>
                <a href="#workspace" class="text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Workspace</a>
                <a href="#comparison" class="text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Benefits</a>
                <a href="{{ route('dashboard') }}" class="text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Dashboard</a>
            </div>
        </footer>
    </body>
</html>
