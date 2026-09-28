<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Soapkraft'))</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/print-document.js'])

        <style>
            @media print {
                .print-hidden {
                    display: none !important;
                }

                body.formula-sheet-document main {
                    max-width: none !important;
                    margin: 0 !important;
                    padding: 0 !important;
                }

                body {
                    background: white !important;
                }

                [data-formula-document-table] {
                    overflow: visible !important;
                    border: 0 !important;
                    border-radius: 0 !important;
                }

                [data-formula-document-table] table {
                    width: 100%;
                    min-width: 0;
                }

                [data-formula-document-table] thead {
                    display: table-header-group;
                }

                [data-formula-document-table] tr {
                    break-inside: avoid;
                    page-break-inside: avoid;
                }

                [data-formula-section] h2,
                [data-formula-section] h3 {
                    break-after: avoid;
                    page-break-after: avoid;
                }

                [data-formula-procedure-content] {
                    break-inside: auto;
                    page-break-inside: auto;
                }
            }
        </style>
    </head>
    <body class="min-h-screen bg-[var(--color-surface)] text-[var(--color-ink)] antialiased @yield('print_document_class')">
        <main class="mx-auto max-w-[90rem] px-6 py-8 lg:px-8">
            @yield('content')
        </main>
    </body>
</html>
