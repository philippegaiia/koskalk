@extends('layouts.app-shell')

@section('title', 'Checkout · '.config('app.name'))
@section('page_heading', 'Checkout')

@push('head')
    @paddleJS
@endpush

@section('content')
<div class="mx-auto w-full max-w-3xl">
    <section aria-labelledby="billing-checkout-heading" class="sk-card p-6">
        <p class="sk-eyebrow">Paddle checkout</p>
        <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 id="billing-checkout-heading" class="text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $plan->name }}</h3>
                @if ($plan->description)
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-[var(--color-ink-soft)]">{{ $plan->description }}</p>
                @endif
            </div>

            @if ($plan->price_label)
                <p class="numeric rounded-lg bg-[var(--color-accent-soft)] px-3 py-2 text-sm font-semibold text-[var(--color-accent-strong)]">{{ $plan->price_label }}</p>
            @endif
        </div>

        <div class="mt-6 rounded-lg border border-[var(--color-line)] bg-white p-4">
            <x-paddle-button :checkout="$checkout" class="sk-btn sk-btn-primary w-full justify-center text-center sm:w-auto" data-theme="light">
                Continue with Paddle
            </x-paddle-button>
        </div>

        <div class="mt-5">
            <a href="{{ route('account') }}" class="text-sm font-medium text-[var(--color-ink-soft)] transition hover:text-[var(--color-ink-strong)]">Back to account</a>
        </div>
    </section>
</div>
@endsection
