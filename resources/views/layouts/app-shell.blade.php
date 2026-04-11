<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Koskalk'))</title>

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
    <body
        x-data="{
            navStorageKey: 'koskalk:sidebar-open',
            navOpen: true,
            isDesktop: window.innerWidth >= 1024,
            initNavState() {
                const storedValue = window.localStorage.getItem(this.navStorageKey);

                if (storedValue === null) {
                    this.navOpen = this.isDesktop;

                    return;
                }

                this.navOpen = storedValue === 'true';
            },
            persistNavState() {
                window.localStorage.setItem(this.navStorageKey, this.navOpen ? 'true' : 'false');
            },
            syncViewport() {
                this.isDesktop = window.innerWidth >= 1024;

                if (! this.isDesktop) {
                    this.navOpen = false;

                    return;
                }

                this.initNavState();
            },
            sidebarGridStyle() {
                if (! this.isDesktop) {
                    return null;
                }

                return `grid-template-columns: ${this.navOpen ? '17rem minmax(0, 1fr)' : '0 minmax(0, 1fr)'};`;
            },
            sidebarStyle() {
                if (! this.isDesktop) {
                    return null;
                }

                return this.navOpen
                    ? 'width: 17rem; opacity: 1; padding: 1.5rem 1.25rem; pointer-events: auto;'
                    : 'width: 0; opacity: 0; padding: 0; pointer-events: none;';
            },
            toggleNav() {
                this.navOpen = ! this.navOpen;
                this.persistNavState();
            },
            closeNav() {
                this.navOpen = false;
                this.persistNavState();
            },
        }"
        x-init="syncViewport()"
        @resize.window.debounce.150ms="syncViewport()"
        class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased"
    >
        <div :style="sidebarGridStyle()" class="relative min-h-screen lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] transition-[grid-template-columns] duration-300 lg:transition-none">
            <div x-cloak x-show="navOpen && ! isDesktop" x-transition.opacity class="fixed inset-0 z-40 bg-black/35 lg:hidden" @click="closeNav()"></div>

            <aside
                :class="navOpen
                    ? 'translate-x-0'
                    : '-translate-x-full lg:translate-x-0 lg:w-0 lg:px-0 lg:py-0 lg:opacity-0 lg:pointer-events-none'"
                :style="sidebarStyle()"
                class="fixed inset-y-0 left-0 z-50 w-72 overflow-hidden bg-[var(--color-hero)] px-5 py-6 text-[var(--color-ink-sidebar)] transition-all duration-300 lg:static lg:z-auto lg:w-[17rem] lg:translate-x-0 lg:opacity-100 lg:transition-none"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="grid size-10 place-items-center rounded-lg bg-[var(--color-hero-soft)] text-xs font-semibold tracking-[0.12em] uppercase text-[var(--color-ink-sidebar)]">KK</span>
                        <div>
                            <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-sidebar-soft)] uppercase">Workspace</p>
                            <h1 class="text-base font-semibold text-[var(--color-ink-sidebar)]">Koskalk</h1>
                        </div>
                    </div>

                    <button type="button" @click="closeNav()" class="grid size-10 place-items-center rounded-lg bg-[var(--color-hero-soft)] text-[var(--color-ink-sidebar)] transition hover:text-white lg:hidden">
                        <span class="sr-only">Close menu</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <button type="button" @click="toggleNav()" class="hidden size-10 place-items-center rounded-lg bg-[var(--color-hero-soft)] text-[var(--color-ink-sidebar)] transition hover:text-white lg:grid">
                        <span class="sr-only">Collapse menu</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                </div>

                <nav class="mt-8 grid gap-2 text-sm">
                    <a href="{{ route('dashboard') }}" wire:navigate @click="if (! isDesktop) closeNav()" class="{{ request()->routeIs('dashboard') ? 'bg-[var(--color-hero-soft)] font-medium text-[var(--color-ink-sidebar)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Dashboard</a>
                    <a href="{{ route('recipes.index') }}" wire:navigate @click="if (! isDesktop) closeNav()" class="{{ request()->routeIs('recipes.*') ? 'bg-[var(--color-hero-soft)] font-medium text-[var(--color-ink-sidebar)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Recipes</a>
                    <a href="{{ route('ingredients.index') }}" wire:navigate @click="if (! isDesktop) closeNav()" class="{{ request()->routeIs('ingredients.*') ? 'bg-[var(--color-hero-soft)] font-medium text-[var(--color-ink-sidebar)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Ingredients</a>
                    <a href="{{ route('packaging-items.index') }}" wire:navigate @click="if (! isDesktop) closeNav()" class="{{ request()->routeIs('packaging-items.*') ? 'bg-[var(--color-hero-soft)] font-medium text-[var(--color-ink-sidebar)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Packaging Items</a>
                    <a href="#" @click="if (! isDesktop) closeNav()" class="rounded-lg px-4 py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]">Compliance</a>
                    <a href="/admin" @click="if (! isDesktop) closeNav()" class="rounded-lg px-4 py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]">Admin</a>
                    <a href="{{ route('settings') }}" wire:navigate @click="if (! isDesktop) closeNav()" class="{{ request()->routeIs('settings') ? 'bg-[var(--color-hero-soft)] font-medium text-[var(--color-ink-sidebar)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-hero-soft)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">Settings</a>
                </nav>

                <div class="mt-8 rounded-xl bg-[var(--color-hero-soft)] p-4">
                    <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-sidebar-soft)] uppercase">Current focus</p>
                    <p class="mt-3 text-sm text-[var(--color-ink-sidebar-soft)]">Build the soap formulation workbench on top of trusted carrier-oil chemistry and a growing essential-oil library.</p>
                </div>
            </aside>

            <div class="flex min-h-screen min-w-0 flex-col">
                <header class="bg-[color:oklch(from_var(--color-panel)_l_c_h_/_0.90)] px-6 py-4 backdrop-blur lg:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                @click="toggleNav()"
                                :class="navOpen && isDesktop ? 'lg:pointer-events-none lg:-translate-x-2 lg:opacity-0' : ''"
                                class="grid size-11 place-items-center rounded-lg bg-[var(--color-panel-strong)] text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]"
                            >
                                <span class="sr-only" x-text="navOpen ? 'Hide menu' : 'Show menu'"></span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path x-show="! navOpen || ! isDesktop" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16" />
                                    <path x-show="navOpen && ! isDesktop" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18 18 6M6 6l12 12" />
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
