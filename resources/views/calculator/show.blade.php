@extends('layouts.calculator')

@section('title', 'Free soap calculator · '.config('app.name'))

@section('content')
<section aria-labelledby="calculator-heading" class="mx-auto w-full max-w-[90rem] px-4 py-5 sm:px-6 lg:px-8">
    <div class="mb-5 grid gap-4 rounded-[0.875rem] border border-[var(--color-line)] bg-[color:color-mix(in_oklab,var(--color-panel)_78%,var(--color-surface)_22%)] p-4 shadow-sm lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center lg:p-5">
        <div class="min-w-0">
            <p class="sk-eyebrow">No account needed</p>
            <h1 id="calculator-heading" class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)] lg:text-3xl">Soap lye calculator</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--color-ink-soft)]">Use the real Soapkraft formula bench with platform oils, fragrances, additives, fatty-acid analysis, and output preview. Save formulas, private ingredients, packaging, and costing when you create an account.</p>
        </div>

        @guest
            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <a href="{{ route('register') }}" class="inline-flex min-h-10 items-center rounded-lg bg-[var(--color-accent)] px-4 py-2 text-sm font-semibold text-white no-underline transition hover:bg-[var(--color-accent-hover)]">Create free account</a>
                <a href="{{ route('login') }}" class="inline-flex min-h-10 items-center rounded-lg border border-[var(--color-line)] bg-white px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] no-underline transition hover:bg-[var(--color-panel-strong)] hover:text-[var(--color-ink-strong)]">Sign in</a>
            </div>
        @endguest
    </div>

    <livewire:dashboard.recipe-workbench product-family-slug="soap" />
</section>
@endsection
