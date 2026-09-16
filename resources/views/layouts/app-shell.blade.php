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
    <body data-user-shell class="min-h-dvh bg-[var(--color-surface)] text-[var(--color-ink)] antialiased">
        @php($appShellUser = auth()->user())
        @php($appShellAdminPanel = \Filament\Facades\Filament::getPanel('admin'))

        <div
            data-app-shell
            data-sidebar-open="true"
            class="relative mx-auto min-h-dvh w-full max-w-[2100px] transition-[grid-template-columns] duration-150 ease-[cubic-bezier(0.2,0,0,1)] motion-reduce:transition-none lg:grid lg:grid-cols-[17rem_minmax(0,1fr)] lg:items-stretch"
        >
            <div data-sidebar-overlay data-sidebar-close aria-hidden="true" class="fixed inset-0 z-40 hidden bg-black/35 lg:hidden"></div>

            <aside
                id="app-sidebar"
                data-sidebar
                aria-labelledby="app-sidebar-title"
                class="sk-sidebar fixed inset-y-0 left-0 z-50 w-72 overflow-x-hidden overflow-y-auto bg-[var(--color-sidebar)] overscroll-contain px-5 py-6 text-[var(--color-ink-sidebar)] transition-[width,opacity,padding,translate] duration-150 ease-[cubic-bezier(0.2,0,0,1)] motion-reduce:transition-none lg:sticky lg:top-0 lg:h-dvh lg:self-start lg:z-auto lg:w-[17rem] lg:translate-x-0 lg:opacity-100"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/app/brand/soapkraftlogo-beige.png') }}" alt="Soapkraft" class="size-10 rounded-lg object-contain">
                        <div>
                            <h1 id="app-sidebar-title" class="text-base font-semibold text-[var(--color-ink-sidebar)]">{{ config('app.name') }}</h1>
                        </div>
                    </div>

                    <button type="button" data-sidebar-close aria-controls="app-sidebar" class="grid size-10 place-items-center rounded-lg bg-[var(--color-field-muted)] text-[var(--color-ink-sidebar)] transition hover:bg-[var(--color-sidebar-active)] lg:hidden">
                        <span class="sr-only">{{ __('navigation.menu.close') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>

                    <button type="button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="true" class="hidden size-10 place-items-center rounded-lg bg-[var(--color-field-muted)] text-[var(--color-ink-sidebar)] transition hover:bg-[var(--color-sidebar-active)] lg:grid">
                        <span class="sr-only">{{ __('navigation.menu.collapse') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                </div>

                <nav aria-labelledby="work-navigation-title" class="mt-4 grid gap-0.5 text-sm">
                    <h2 id="work-navigation-title" class="sk-eyebrow px-4 text-[var(--color-ink-sidebar-soft)]">{{ __('navigation.sections.work') }}</h2>
                    <a href="{{ route('dashboard') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('dashboard')) aria-current="page" @endif class="{{ request()->routeIs('dashboard') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.overview') }}</a>
                    <a href="{{ route('recipes.index') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('recipes.*')) aria-current="page" @endif class="{{ request()->routeIs('recipes.*') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.formulas') }}</a>
                    <a href="{{ route('ingredients.index') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('ingredients.*')) aria-current="page" @endif class="{{ request()->routeIs('ingredients.*') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.ingredients') }}</a>
                    <a href="{{ route('packaging-items.index') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('packaging-items.*')) aria-current="page" @endif class="{{ request()->routeIs('packaging-items.*') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.packaging') }}</a>
                    <a href="{{ route('production-bench.home') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('production-bench.*')) aria-current="page" @endif class="{{ request()->routeIs('production-bench.*') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('production_bench.title') }}</a>
                    <a href="{{ route('media.index') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('media.*')) aria-current="page" @endif class="{{ request()->routeIs('media.*') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.media_library') }}</a>
                    <div class="flex items-center justify-between gap-3 border-l-2 border-l-transparent px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 text-[var(--color-ink-sidebar-soft)]">
                        <span>{{ __('navigation.items.compliance') }}</span>
                        <span class="text-xs">{{ __('navigation.status.coming_soon') }}</span>
                    </div>
                </nav>

                <nav aria-labelledby="account-navigation-title" class="mt-4 grid gap-0.5 border-t border-[var(--color-line)] pt-3 text-sm">
                    <h2 id="account-navigation-title" class="sk-eyebrow px-4 text-[var(--color-ink-sidebar-soft)]">{{ __('navigation.sections.account') }}</h2>
                    <a href="{{ route('account') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('account')) aria-current="page" @endif class="{{ request()->routeIs('account') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.account') }}</a>
                    <a href="{{ route('settings') }}" wire:navigate data-sidebar-mobile-close @if (request()->routeIs('settings')) aria-current="page" @endif class="{{ request()->routeIs('settings') ? 'border-l-[var(--color-sidebar-active-text)] font-semibold text-[var(--color-sidebar-active-text)]' : 'border-l-transparent text-[var(--color-ink-sidebar-soft)] hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]' }} border-l-2 rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 transition">{{ __('navigation.items.settings') }}</a>
                    @if ($appShellUser?->canAccessPanel($appShellAdminPanel))
                        <a href="{{ route('filament.admin.pages.dashboard') }}" data-sidebar-mobile-close class="border-l-2 border-l-transparent rounded-r-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">{{ __('navigation.items.admin') }}</a>
                    @endif
                </nav>

                <section aria-label="{{ __('navigation.user.aria_label') }}" class="mt-4 border-t border-[var(--color-line)] px-4 pt-3">
                    <p class="truncate text-sm font-semibold text-[var(--color-ink-sidebar)]">{{ $appShellUser?->name }}</p>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        <span class="rounded-full bg-[var(--color-field-muted)] px-2.5 py-1 text-xs font-medium text-[var(--color-ink-sidebar-soft)]">{{ __('navigation.user.free_account') }}</span>
                        @if ($appShellUser?->is_admin)
                            <span class="rounded-full bg-[var(--color-accent-soft)] px-2.5 py-1 text-xs font-medium text-[var(--color-accent-strong)]">Admin</span>
                        @endif
                    </div>
                </section>

                <form method="POST" action="{{ route('logout') }}" class="mt-1">
                    @csrf
                    <button type="submit" data-sidebar-mobile-close class="flex w-full items-center rounded-md px-4 py-3 lg:py-2 lg:pointer-coarse:py-3 text-left text-sm text-[var(--color-ink-sidebar-soft)] transition hover:bg-[var(--color-field-muted)] hover:text-[var(--color-ink-sidebar)]">
                        {{ __('navigation.actions.sign_out') }}
                    </button>
                </form>

            </aside>

            <div data-sidebar-background class="flex min-h-dvh min-w-0 flex-col">
                <header class="bg-[color:oklch(from_var(--color-panel)_l_c_h_/_0.90)] px-6 py-4 backdrop-blur lg:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                data-sidebar-toggle
                                data-sidebar-header-toggle
                                aria-controls="app-sidebar"
                                aria-expanded="true"
                                class="grid size-11 place-items-center rounded-lg bg-transparent text-[var(--color-ink-strong)] transition-[color,background-color,translate,scale,opacity] duration-150 ease-[cubic-bezier(0.2,0,0,1)] hover:bg-[var(--color-field-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-active)] active:scale-[0.96] motion-reduce:transition-none motion-reduce:active:scale-100"
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

        <x-app-notification
            :message="session('error') ?? session('status')"
            :type="session('error') ? 'error' : 'success'"
        />

        @filamentScripts
    </body>
</html>
