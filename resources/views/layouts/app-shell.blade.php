<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Koskalk'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-[var(--color-surface-strong)] text-[var(--color-ink)] antialiased">
        <div class="grid min-h-screen lg:grid-cols-[17rem_minmax(0,1fr)]">
            <aside class="border-b border-[var(--color-line)] bg-[var(--color-panel-strong)] px-5 py-6 lg:border-r lg:border-b-0">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-full border border-[var(--color-line-strong)] bg-white text-xs font-semibold tracking-[0.22em] uppercase text-[var(--color-ink-strong)]">KK</span>
                    <div>
                        <p class="text-xs font-semibold tracking-[0.2em] text-[var(--color-ink-soft)] uppercase">Workspace</p>
                        <h1 class="text-base font-semibold text-[var(--color-ink-strong)]">Koskalk</h1>
                    </div>
                </div>

                <nav class="mt-8 grid gap-2 text-sm">
                    <a href="{{ route('dashboard') }}" wire:navigate class="{{ request()->routeIs('dashboard') ? 'border border-[var(--color-line)] bg-white font-medium text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)] hover:bg-white/70 hover:text-[var(--color-ink-strong)]' }} rounded-2xl px-4 py-3 transition">Dashboard</a>
                    <a href="{{ route('recipes.index') }}" wire:navigate class="{{ request()->routeIs('recipes.*') ? 'border border-[var(--color-line)] bg-white font-medium text-[var(--color-ink-strong)]' : 'text-[var(--color-ink-soft)] hover:bg-white/70 hover:text-[var(--color-ink-strong)]' }} rounded-2xl px-4 py-3 transition">Recipes</a>
                    <a href="#" class="rounded-2xl px-4 py-3 text-[var(--color-ink-soft)] transition hover:bg-white/70 hover:text-[var(--color-ink-strong)]">Ingredients</a>
                    <a href="#" class="rounded-2xl px-4 py-3 text-[var(--color-ink-soft)] transition hover:bg-white/70 hover:text-[var(--color-ink-strong)]">Compliance</a>
                    <a href="/admin" class="rounded-2xl px-4 py-3 text-[var(--color-ink-soft)] transition hover:bg-white/70 hover:text-[var(--color-ink-strong)]">Admin</a>
                </nav>

                <div class="mt-8 rounded-3xl border border-[var(--color-line)] bg-white p-4">
                    <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Current focus</p>
                    <p class="mt-3 text-sm text-[var(--color-ink-soft)]">Build the soap formulation workbench on top of trusted carrier-oil chemistry and a growing essential-oil library.</p>
                </div>
            </aside>

            <div class="flex min-h-screen flex-col">
                <header class="border-b border-[var(--color-line)] bg-white/92 px-6 py-4 backdrop-blur lg:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold tracking-[0.18em] text-[var(--color-ink-soft)] uppercase">Public app shell</p>
                            <h2 class="text-xl font-semibold text-[var(--color-ink-strong)]">@yield('page_heading', 'Dashboard')</h2>
                        </div>

                        <a href="{{ route('home') }}" class="rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">Back To Home</a>
                    </div>
                </header>

                <main class="flex-1 px-6 py-8 lg:px-8">
                    @yield('content')
                </main>
            </div>
        </div>

        @livewireScripts
    </body>
</html>
