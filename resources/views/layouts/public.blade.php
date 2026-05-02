<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Soapkraft'))</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;1,400;1,500;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-cream text-ink-strong antialiased overflow-x-hidden" style="font-family: 'DM Sans', sans-serif;">

        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:text-white focus:no-underline">Skip to content</a>

        {{-- NAV --}}
        <nav class="fixed top-0 left-0 right-0 z-50 h-[58px] flex items-center justify-between px-5 lg:px-14 bg-cream/92 backdrop-blur-[10px] border-b border-line">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 no-underline">
                <div class="w-[30px] h-[30px] rounded-[7px] bg-accent-soft flex items-center justify-center font-mono text-[11px] font-medium text-accent-strong">SK</div>
                <span class="font-serif text-base text-ink-strong">Soapkraft</span>
            </a>

            {{-- Desktop nav links --}}
            <div class="hidden md:flex gap-8 items-center">
                <a href="#calculator" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Calculator</a>
                <a href="#benefits" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Benefits</a>
                <a href="#workspace" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Free workspace</a>
                <a href="#comparison" class="text-[13px] text-ink-soft no-underline transition hover:text-ink-strong">Why Soapkraft</a>
            </div>

            <div class="flex items-center gap-2">
                <a href="{{ route('recipes.create') }}" class="text-[13px] px-[18px] py-2.5 rounded-md bg-accent text-white no-underline font-medium transition hover:bg-accent-hover">Use calculator</a>
                <button type="button" data-mobile-menu-toggle aria-controls="mobile-menu" aria-expanded="false" class="md:hidden grid size-10 place-items-center rounded-lg text-ink-soft">
                    <span class="sr-only">Toggle navigation menu</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
            </div>
        </nav>

        {{-- Mobile nav dropdown --}}
        <div data-mobile-menu-overlay class="fixed inset-0 top-[58px] z-40 hidden bg-black/20 md:hidden"></div>
        <div id="mobile-menu" data-mobile-menu class="fixed top-[58px] left-0 right-0 z-40 hidden border-b border-line bg-cream px-5 py-4 md:hidden">
            <div class="flex flex-col gap-1">
                <a href="#calculator" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Calculator</a>
                <a href="#benefits" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Benefits</a>
                <a href="#workspace" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Free workspace</a>
                <a href="#comparison" class="rounded-lg px-4 py-3 text-sm text-ink-soft no-underline transition hover:bg-panel hover:text-ink-strong">Why Soapkraft</a>
            </div>
        </div>

        <main id="main-content">
            @yield('content')
        </main>

        {{-- FOOTER --}}
        <footer class="bg-panel border-t border-line py-8 px-5 lg:px-20 flex flex-col lg:flex-row items-center justify-between gap-4">
            <p class="font-serif text-sm text-ink-soft">Soapkraft — free soap calculator & formulation workspace</p>
            <div class="flex gap-7">
                <a href="#calculator" class="rounded px-1 py-2 text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Calculator</a>
                <a href="#workspace" class="rounded px-1 py-2 text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Workspace</a>
                <a href="#benefits" class="rounded px-1 py-2 text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Benefits</a>
                <a href="{{ route('dashboard') }}" class="rounded px-1 py-2 text-xs text-ink-soft no-underline font-mono tracking-[0.04em] transition hover:text-ink-strong">Dashboard</a>
            </div>
        </footer>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const toggle = document.querySelector('[data-mobile-menu-toggle]');
                const menu = document.querySelector('[data-mobile-menu]');
                const overlay = document.querySelector('[data-mobile-menu-overlay]');

                if (!toggle || !menu || !overlay) return;

                function closeMobileMenu() {
                    menu.classList.add('hidden');
                    overlay.classList.add('hidden');
                    toggle.setAttribute('aria-expanded', 'false');
                }

                function openMobileMenu() {
                    menu.classList.remove('hidden');
                    overlay.classList.remove('hidden');
                    toggle.setAttribute('aria-expanded', 'true');
                }

                toggle.addEventListener('click', () => {
                    if (menu.classList.contains('hidden')) {
                        openMobileMenu();
                    } else {
                        closeMobileMenu();
                    }
                });

                overlay.addEventListener('click', closeMobileMenu);

                menu.querySelectorAll('a[href^="#"]').forEach(link => {
                    link.addEventListener('click', closeMobileMenu);
                });
            });
        </script>
    </body>
</html>
