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
    <body class="min-h-screen bg-cream text-forest-deep antialiased overflow-x-hidden" style="font-family: 'DM Sans', sans-serif;">

        {{-- NAV --}}
        <nav class="fixed top-0 left-0 right-0 z-50 h-[58px] flex items-center justify-between px-5 lg:px-14 bg-[rgba(14,26,16,0.97)] backdrop-blur-[10px] border-b border-sage/12">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 no-underline">
                <div class="w-[30px] h-[30px] rounded-[7px] bg-sage flex items-center justify-center font-mono text-[11px] font-medium text-forest-deep">SK</div>
                <span class="font-serif text-base text-cream">Soapkraft</span>
            </a>

            <div class="hidden md:flex gap-8 items-center">
                <a href="#features" class="text-[13px] text-cream/52 no-underline transition hover:text-cream">Features</a>
                <a href="#scope" class="text-[13px] text-cream/52 no-underline transition hover:text-cream">Soap & cosmetics</a>
                <a href="#workflow" class="text-[13px] text-cream/52 no-underline transition hover:text-cream">How it works</a>
            </div>

            <a href="{{ route('dashboard') }}" class="text-[13px] px-[18px] py-[7px] rounded-md bg-sage text-forest-deep no-underline font-medium transition hover:bg-sage-light">Open workspace</a>
        </nav>

        <main>
            @yield('content')
        </main>

        {{-- FOOTER --}}
        <footer class="bg-forest-deep border-t border-sage/10 py-8 px-5 lg:px-20 flex flex-col lg:flex-row items-center justify-between gap-4">
            <p class="font-serif text-sm text-cream/52">Soapkraft — soap & cosmetics formulation workbench</p>
            <div class="flex gap-7">
                <a href="#" class="text-xs text-cream/26 no-underline font-mono tracking-[0.04em] transition hover:text-cream/52">Recipes</a>
                <a href="#" class="text-xs text-cream/26 no-underline font-mono tracking-[0.04em] transition hover:text-cream/52">Ingredients</a>
                <a href="#" class="text-xs text-cream/26 no-underline font-mono tracking-[0.04em] transition hover:text-cream/52">Compliance</a>
                <a href="#" class="text-xs text-cream/26 no-underline font-mono tracking-[0.04em] transition hover:text-cream/52">About</a>
            </div>
        </footer>
    </body>
</html>
