<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Soapkraft'))</title>

        <style>
            [x-cloak] {
                display: none !important;
            }

            :root {
                color-scheme: light;
            }
        </style>

        <script>
            document.documentElement.classList.remove('dark')
            document.documentElement.style.colorScheme = 'light'
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @filamentStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased">
        <div
            data-app-shell
            data-sidebar-open="true"
            class="relative min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] transition-[grid-template-columns] duration-300 lg:transition-none"
        >
            <div data-sidebar-overlay data-sidebar-close class="fixed inset-0 z-40 hidden bg-black/35 lg:hidden"></div>

            <aside
                data-sidebar
                class="fixed inset-y-0 left-0 z-50 w-72 overflow-hidden bg-[var(--color-sidebar)] px-5 py-6 text-[var(--color-ink-sidebar)] transition-all duration-300 lg:static lg:z-auto lg:w-[17rem] lg:translate-x-0 lg:opacity-100 lg:transition-none"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/app/brand/soapkraftlogo-beige.png') }}" alt="Soapkraft" class="size-10 rounded-lg object-contain">
                        <div>
                            <h1 class="text-base font-semibold text-[var(--color-ink-sidebar)]">{{ config('app.name') }}</h1>
                        </div>
                    </div>

                    <button type="button" data-sidebar-close class="grid size-10 place-items-center rounded-lg bg-[var(--color-field-muted)] text-[var(--color-ink-sidebar)] transition hover:bg-[var(--color-accent-soft)] lg:hidden">
                        <span class="sr-only">Close menu</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <button type="button" data-sidebar-toggle class="hidden size-10 place-items-center rounded-lg bg-[var(--color-field-muted)] text-[var(--color-ink-sidebar)] transition hover:bg-[var(--color-accent-soft)] lg:grid">
                        <span class="sr-only">Collapse menu</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                </div>

                <nav class="mt-8 grid gap-2 text-sm">
                    <a href="{{ route('dashboard') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('dashboard') ? 'bg-[var(--color-accent-soft)] font-medium text-[var(--color-accent-strong)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Dashboard</a>
                    <a href="{{ route('recipes.index') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('recipes.*') ? 'bg-[var(--color-accent-soft)] font-medium text-[var(--color-accent-strong)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Recipes</a>
                    <a href="{{ route('ingredients.index') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('ingredients.*') ? 'bg-[var(--color-accent-soft)] font-medium text-[var(--color-accent-strong)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Ingredients</a>
                    <a href="{{ route('packaging-items.index') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('packaging-items.*') ? 'bg-[var(--color-accent-soft)] font-medium text-[var(--color-accent-strong)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Packaging Items</a>
                    <a href="#" data-sidebar-mobile-close class="rounded-lg px-4 py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">Compliance</a>
                    <a href="/admin" data-sidebar-mobile-close class="rounded-lg px-4 py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">Admin</a>
                    <a href="{{ route('settings') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('settings') ? 'bg-[var(--color-accent-soft)] font-medium text-[var(--color-accent-strong)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Settings</a>
                </nav>

            </aside>

            <div class="flex min-h-screen min-w-0 flex-col">
                <header class="bg-[color:oklch(from_var(--color-panel)_l_c_h_/_0.90)] px-6 py-4 backdrop-blur lg:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                data-sidebar-toggle
                                data-sidebar-header-toggle
                                class="grid size-11 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]"
                            >
                                <span class="sr-only">Show or hide menu</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16" />
                                </svg>
                            </button>

                            <div>
                                <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">@yield('page_heading', 'Dashboard')</h2>
                            </div>
                        </div>

                        <a href="{{ route('home') }}" class="shrink-0 whitespace-nowrap text-sm text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]">Home</a>
                    </div>
                </header>

                <main class="flex-1 px-6 py-8 lg:px-8">
                    @yield('content')
                </main>
            </div>
        </div>

        @filamentScripts
    </body>
</html>
