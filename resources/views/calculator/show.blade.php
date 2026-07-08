@extends('layouts.calculator')

@section('title', 'Free soap calculator · '.config('app.name'))

@section('content')
<section aria-labelledby="calculator-heading" class="mx-auto w-full max-w-[96rem] px-4 py-5 sm:px-6 lg:px-8">
    <div class="grid gap-5 lg:grid-cols-[19rem_minmax(0,1fr)] lg:items-start">
        @include('calculator.partials.aside')

        <div class="order-1 min-w-0 lg:order-2">
            <div class="mb-5">
                <div class="min-w-0">
                    <p class="ledger-eyebrow">No account needed</p>
                    <h1 id="calculator-heading" class="font-display mt-2 text-2xl text-[var(--color-ink-strong)] lg:text-3xl">Soap lye calculator</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-[var(--color-ink-soft)]">Choose oils, lye type, water mode, and superfat. Get the working numbers without creating an account.</p>
                </div>
            </div>

            <livewire:dashboard.recipe-workbench product-family-slug="soap" />
        </div>
    </div>
</section>
@endsection
