<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Koskalk'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased">
        <div class="relative isolate overflow-hidden">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-[radial-gradient(circle_at_top,rgba(206,164,99,0.28),transparent_60%)]"></div>

            <header class="border-b border-[var(--color-line)] bg-white/88 backdrop-blur">
                <div class="mx-auto flex max-w-7xl items-center justify-between gap-6 px-6 py-4 lg:px-8">
                    <a href="{{ route('home') }}" class="flex items-center gap-3 text-sm font-semibold tracking-[0.24em] text-[var(--color-ink-strong)] uppercase">
                        <span class="grid size-9 place-items-center rounded-full border border-[var(--color-line-strong)] bg-[var(--color-panel)] text-xs">KK</span>
                        <span>Koskalk</span>
                    </a>

                    <nav class="hidden items-center gap-6 text-sm text-[var(--color-ink-soft)] lg:flex">
                        <a href="{{ route('home') }}" class="transition hover:text-[var(--color-ink-strong)]">Overview</a>
                        <a href="{{ route('dashboard') }}" class="transition hover:text-[var(--color-ink-strong)]">Dashboard</a>
                        <a href="/admin" class="transition hover:text-[var(--color-ink-strong)]">Admin</a>
                    </nav>

                    <div class="flex items-center gap-3">
                        <a href="{{ route('dashboard') }}" class="rounded-full border border-[var(--color-line-strong)] px-4 py-2 text-sm font-medium text-[var(--color-ink-strong)] transition hover:bg-[var(--color-panel)]">Open Workspace</a>
                    </div>
                </div>
            </header>

            <main>
                @yield('content')
            </main>
        </div>
    </body>
</html>
