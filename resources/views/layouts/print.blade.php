<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Koskalk'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css'])

        <style>
            @media print {
                .print-hidden {
                    display: none !important;
                }

                body {
                    background: white !important;
                }
            }
        </style>
    </head>
    <body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased">
        <main class="mx-auto max-w-[90rem] px-6 py-8 lg:px-8">
            @yield('content')
        </main>
    </body>
</html>
