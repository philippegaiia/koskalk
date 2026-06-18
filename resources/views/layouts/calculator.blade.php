<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

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
    <body data-public-shell="true" data-workbench-shell="true" class="min-h-screen bg-[var(--color-surface)] font-sans text-[var(--color-ink-strong)] antialiased">
        <header class="sk-shell-line-b sticky top-0 z-50 bg-[color:color-mix(in_oklab,var(--color-panel)_88%,transparent)] backdrop-blur">
            <div class="mx-auto flex h-[58px] w-full max-w-[90rem] items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                <a href="{{ route('home') }}" class="flex min-h-11 items-center gap-2.5 no-underline">
                    <div class="flex h-[30px] w-[30px] items-center justify-center rounded-[7px] bg-[var(--color-accent-soft)] text-xs font-semibold text-[var(--color-accent-strong)]">SK</div>
                    <span class="text-lg font-semibold text-[var(--color-ink-strong)]">Soapkraft</span>
                </a>
                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-lg bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-white no-underline transition hover:bg-[var(--color-accent-hover)]">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="hidden text-sm font-medium text-[var(--color-ink-soft)] no-underline transition hover:text-[var(--color-ink-strong)] sm:inline">Sign in</a>
                        <a href="{{ route('register') }}" class="rounded-lg bg-[var(--color-accent)] px-4 py-2.5 text-sm font-medium text-white no-underline transition hover:bg-[var(--color-accent-hover)]">Create account</a>
                    @endauth
                </div>
            </div>
        </header>

        <main>
            @yield('content')
        </main>

        @filamentScripts
    </body>
</html>
