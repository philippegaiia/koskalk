<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Soapkraft'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php($isHomePage = request()->routeIs('home'))
    <body data-public-shell="true" class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased">
        <div class="relative isolate overflow-hidden">
            @unless ($isHomePage)
                <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-[radial-gradient(circle_at_top,rgba(206,164,99,0.28),transparent_60%)]"></div>
            @endunless

            <header class="{{ $isHomePage ? 'absolute inset-x-0 top-0 z-30' : 'border-b border-[var(--color-line)] bg-white/88 backdrop-blur' }}">
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-6 py-4 lg:px-8">
                    <a href="{{ route('home') }}" class="flex items-center gap-3 text-sm font-semibold tracking-[0.24em] {{ $isHomePage ? 'text-white' : 'text-[var(--color-ink-strong)]' }} uppercase">
                        <span class="grid size-9 place-items-center rounded-full border text-xs {{ $isHomePage ? 'border-white/15 bg-white/10 text-white' : 'border-[var(--color-line-strong)] bg-[var(--color-panel)] text-[var(--color-ink-strong)]' }}">SK</span>
                        <span>Soapkraft</span>
                    </a>

                    <nav class="hidden items-center gap-6 text-sm lg:flex {{ $isHomePage ? 'text-white/72' : 'text-[var(--color-ink-soft)]' }}">
                        <a href="{{ route('home') }}" class="transition {{ $isHomePage ? 'hover:text-white' : 'hover:text-[var(--color-ink-strong)]' }}">Overview</a>
                        <a href="{{ route('recipes.index') }}" class="transition {{ $isHomePage ? 'hover:text-white' : 'hover:text-[var(--color-ink-strong)]' }}">Recipes</a>
                        <a href="{{ route('ingredients.index') }}" class="transition {{ $isHomePage ? 'hover:text-white' : 'hover:text-[var(--color-ink-strong)]' }}">Ingredients</a>
                        <a href="/admin" class="transition {{ $isHomePage ? 'hover:text-white' : 'hover:text-[var(--color-ink-strong)]' }}">Admin</a>
                    </nav>

                    <div class="flex items-center gap-3">
                        <a href="{{ route('dashboard') }}" class="rounded-full border px-4 py-2 text-sm font-medium transition {{ $isHomePage ? 'border-white/15 bg-white/10 text-white hover:bg-white/16' : 'border-[var(--color-line-strong)] text-[var(--color-ink-strong)] hover:bg-[var(--color-panel)]' }}">Open Workspace</a>
                    </div>
                </div>
            </header>

            <main>
                @yield('content')
            </main>
        </div>
    </body>
</html>
