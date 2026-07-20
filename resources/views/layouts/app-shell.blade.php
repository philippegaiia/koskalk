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

        <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
        <link href="https://api.fontshare.com/v2/css?f[]=instrument-sans@400,500,600,700&f[]=instrument-serif@400&display=swap" rel="stylesheet">

        @filamentStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        @stack('head')
    </head>
    <body class="min-h-dvh bg-[var(--color-surface)] text-[var(--color-ink)] antialiased">
        @php($appShellUser = auth()->user())

        <div
            data-app-shell
            data-sidebar-open="true"
            class="relative mx-auto min-h-dvh w-full max-w-[2100px] lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-stretch transition-[grid-template-columns] duration-300 lg:transition-none"
        >
            <div data-sidebar-overlay data-sidebar-close class="fixed inset-0 z-40 hidden bg-black/35 lg:hidden"></div>

            <aside
                data-sidebar
                class="sk-sidebar fixed inset-y-0 left-0 z-50 w-72 overflow-x-hidden overflow-y-auto bg-[var(--color-sidebar)] px-5 py-6 text-[var(--color-ink-sidebar)] transition-all duration-300 lg:sticky lg:top-0 lg:h-dvh lg:self-start lg:z-auto lg:w-[17rem] lg:translate-x-0 lg:opacity-100 lg:transition-none"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/app/brand/soapkraftlogo-beige.png') }}" alt="Soapkraft" class="size-10 rounded-lg object-contain">
                        <div>
                            <h1 class="text-base font-semibold text-[var(--color-ink-sidebar)]">{{ config('app.name') }}</h1>
                        </div>
                    </div>

                    <button type="button" data-sidebar-close class="grid size-10 place-items-center rounded-lg bg-[var(--color-field-muted)] text-[var(--color-ink-sidebar)] transition hover:bg-[var(--color-sidebar-active)] lg:hidden">
                        <span class="sr-only">{{ __('navigation.menu.close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <button type="button" data-sidebar-toggle class="hidden size-10 place-items-center rounded-lg bg-[var(--color-field-muted)] text-[var(--color-ink-sidebar)] transition hover:bg-[var(--color-sidebar-active)] lg:grid">
                        <span class="sr-only">{{ __('navigation.menu.collapse') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                </div>

                <nav class="mt-8 grid gap-2 text-sm">
                    <a href="{{ route('dashboard') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('dashboard') ? 'bg-[var(--color-sidebar-active)] font-medium text-[var(--color-sidebar-active-text)] ring-1 ring-[var(--color-sidebar-active-ring)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">{{ __('navigation.items.overview') }}</a>
                    <a href="{{ route('recipes.index') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('recipes.*') ? 'bg-[var(--color-sidebar-active)] font-medium text-[var(--color-sidebar-active-text)] ring-1 ring-[var(--color-sidebar-active-ring)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">{{ __('navigation.items.formulas') }}</a>
                    <a href="{{ route('ingredients.index') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('ingredients.*') ? 'bg-[var(--color-sidebar-active)] font-medium text-[var(--color-sidebar-active-text)] ring-1 ring-[var(--color-sidebar-active-ring)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">{{ __('navigation.items.ingredients') }}</a>
                    <a href="{{ route('packaging-items.index') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('packaging-items.*') ? 'bg-[var(--color-sidebar-active)] font-medium text-[var(--color-sidebar-active-text)] ring-1 ring-[var(--color-sidebar-active-ring)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">{{ __('navigation.items.packaging') }}</a>
                    <a href="javascript:void(0)" data-sidebar-mobile-close aria-disabled="true" tabindex="-1" title="{{ __('navigation.status.coming_soon') }}" class="rounded-lg px-4 py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">{{ __('navigation.items.compliance') }}</a>
                    <a href="{{ route('account') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('account') ? 'bg-[var(--color-sidebar-active)] font-medium text-[var(--color-sidebar-active-text)] ring-1 ring-[var(--color-sidebar-active-ring)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">{{ __('navigation.items.account') }}</a>
                    <a href="/admin" data-sidebar-mobile-close class="rounded-lg px-4 py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">Admin</a>
                    <a href="{{ route('settings') }}" wire:navigate data-sidebar-mobile-close class="{{ request()->routeIs('settings') ? 'bg-[var(--color-sidebar-active)] font-medium text-[var(--color-sidebar-active-text)] ring-1 ring-[var(--color-sidebar-active-ring)]' : 'text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} rounded-lg px-4 py-3 transition">{{ __('navigation.items.settings') }}</a>
                </nav>

                <section aria-label="{{ __('navigation.user.aria_label') }}" class="mt-8 border-t border-[var(--color-line)] px-4 pt-5">
                    <p class="sk-eyebrow text-[var(--color-ink-sidebar-soft)]">{{ __('navigation.user.signed_in') }}</p>
                    <p class="mt-2 truncate text-sm font-semibold text-[var(--color-ink-sidebar)]">{{ $appShellUser?->name }}</p>
                    <p class="mt-0.5 truncate text-xs text-[var(--color-ink-sidebar-soft)]">{{ $appShellUser?->email }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="rounded-full bg-[var(--color-field-muted)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-sidebar-soft)]">{{ __('navigation.user.free_account') }}</span>
                        @if ($appShellUser?->is_admin)
                            <span class="rounded-full bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-accent-strong)]">Admin</span>
                        @endif
                    </div>
                </section>

                <form method="POST" action="{{ route('logout') }}" class="mt-8 border-t border-white/10 pt-5">
                    @csrf
                    <button type="submit" data-sidebar-mobile-close class="flex w-full items-center rounded-lg px-4 py-3 text-left text-sm text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">
                        {{ __('navigation.actions.sign_out') }}
                    </button>
                </form>

            </aside>

            <div class="flex min-h-dvh min-w-0 flex-col">
                <header class="bg-[color:oklch(from_var(--color-panel)_l_c_h_/_0.90)] px-6 py-4 backdrop-blur lg:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                data-sidebar-toggle
                                data-sidebar-header-toggle
                                class="grid size-11 place-items-center rounded-lg bg-[var(--color-forest-deep)] text-[var(--color-inverse)] shadow-sm transition hover:bg-[var(--color-forest-mid)]"
                            >
                                <span class="sr-only">{{ __('navigation.menu.toggle') }}</span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16" />
                                </svg>
                            </button>

                            <div>
                                <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">@yield('page_heading', __('navigation.items.overview'))</h2>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 sm:gap-5">
                            <x-language-selector id="app" class="text-[var(--color-ink-soft)]" />
                            <a href="{{ route('home') }}" class="shrink-0 whitespace-nowrap text-sm text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]">{{ __('navigation.items.home') }}</a>
                        </div>
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
