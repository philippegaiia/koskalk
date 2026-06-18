@extends('layouts.app-shell')

@section('title', 'Account · '.config('app.name'))
@section('page_heading', 'Account')

@php
    $recipeUsage = $usage['saved_recipes'];
    $ingredientUsage = $usage['private_ingredients'];
    $usagePercent = function (array $line): int {
        if (($line['limit'] ?? null) === null || (int) $line['limit'] <= 0) {
            return 0;
        }

        return min(100, (int) round(((int) $line['used'] / (int) $line['limit']) * 100));
    };
    $usageLabel = fn (array $line): string => ($line['limit'] ?? null) === null
        ? "{$line['used']} / unlimited"
        : "{$line['used']} / {$line['limit']}";
    $hasActiveSubscription = $currentSubscription !== null;
@endphp

@section('content')
<div class="mx-auto grid w-full max-w-6xl gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-6">
        <section aria-labelledby="account-profile-heading" class="sk-card p-6">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="sk-eyebrow">Profile</p>
                    <h3 id="account-profile-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $user->name }}</h3>
                    <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ $user->email }}</p>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="sk-btn sk-btn-outline">Sign out</button>
                </form>
            </div>

            @if (session('profile_status'))
                <p role="status" class="mt-5 rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm font-medium text-[var(--color-success-strong)]">{{ session('profile_status') }}</p>
            @endif

            <form method="POST" action="{{ route('account.profile.update') }}" class="mt-6 grid gap-5">
                @csrf
                @method('PATCH')

                <div class="grid gap-5 md:grid-cols-2">
                    <label class="grid gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Name</span>
                        <input name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" aria-label="Name" aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="account-name-error" @enderror class="sk-input">
                        @error('name')
                            <span id="account-name-error" role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="grid gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Email</span>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email" aria-label="Email" aria-invalid="@error('email') true @else false @enderror" @error('email') aria-describedby="account-email-error" @enderror class="sk-input">
                        @error('email')
                            <span id="account-email-error" role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div>
                    <button type="submit" class="sk-btn sk-btn-primary">Save profile</button>
                </div>
            </form>
        </section>

        <section aria-labelledby="account-password-heading" class="sk-card p-6">
            <p class="sk-eyebrow">Security</p>
            <h3 id="account-password-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">Password</h3>

            @if (session('password_status'))
                <p role="status" class="mt-5 rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm font-medium text-[var(--color-success-strong)]">{{ session('password_status') }}</p>
            @endif

            <form method="POST" action="{{ route('account.password.update') }}" class="mt-6 grid gap-5">
                @csrf
                @method('PATCH')

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">Current password</span>
                    <input type="password" name="current_password" required autocomplete="current-password" aria-label="Current password" aria-invalid="@error('current_password') true @else false @enderror" @error('current_password') aria-describedby="account-current-password-error" @enderror class="sk-input">
                    @error('current_password')
                        <span id="account-current-password-error" role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</span>
                    @enderror
                </label>

                <div class="grid gap-5 md:grid-cols-2">
                    <label class="grid gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">New password</span>
                        <input type="password" name="password" required autocomplete="new-password" aria-label="New password" aria-invalid="@error('password') true @else false @enderror" @error('password') aria-describedby="account-password-error" @enderror class="sk-input">
                        @error('password')
                            <span id="account-password-error" role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="grid gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">Confirm new password</span>
                        <input type="password" name="password_confirmation" required autocomplete="new-password" aria-label="Confirm new password" class="sk-input">
                    </label>
                </div>

                <div>
                    <button type="submit" class="sk-btn sk-btn-primary">Update password</button>
                </div>
            </form>
        </section>
    </div>

    <aside class="space-y-6">
        <section aria-labelledby="account-plan-heading" class="sk-card p-6">
            <p class="sk-eyebrow">Plan</p>
            <h3 id="account-plan-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $plan?->name ?? 'No plan assigned' }}</h3>
            @if ($plan?->description)
                <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">{{ $plan->description }}</p>
            @endif

            <div class="mt-6 space-y-4">
                <div class="rounded-lg border border-[var(--color-line)] bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">Saved recipes</p>
                        <p class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $usageLabel($recipeUsage) }}</p>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
                        <div class="h-full rounded-full bg-[var(--color-accent)]" style="width: {{ $usagePercent($recipeUsage) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $recipeUsage['remaining'] === null ? 'Unlimited remaining' : $recipeUsage['remaining'].' remaining' }}</p>
                </div>

                <div class="rounded-lg border border-[var(--color-line)] bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">Private ingredients</p>
                        <p class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $usageLabel($ingredientUsage) }}</p>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
                        <div class="h-full rounded-full bg-[var(--color-accent)]" style="width: {{ $usagePercent($ingredientUsage) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $ingredientUsage['remaining'] === null ? 'Unlimited remaining' : $ingredientUsage['remaining'].' remaining' }}</p>
                </div>
            </div>
        </section>

        <section aria-labelledby="account-billing-heading" class="sk-card p-6">
            <p class="sk-eyebrow">Billing</p>
            <h3 id="account-billing-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $hasActiveSubscription ? 'Paddle subscription' : 'Free account' }}</h3>

            @if (session('billing_status'))
                <p role="status" class="mt-5 rounded-lg bg-[var(--color-warning-soft)] px-4 py-3 text-sm font-medium text-[var(--color-warning-strong)]">{{ session('billing_status') }}</p>
            @endif

            <dl class="mt-5 grid gap-3 text-sm">
                <div class="flex items-center justify-between gap-3 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5">
                    <dt class="text-[var(--color-ink-soft)]">Provider</dt>
                    <dd class="font-medium text-[var(--color-ink-strong)]">Paddle</dd>
                </div>
                <div class="flex items-center justify-between gap-3 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5">
                    <dt class="text-[var(--color-ink-soft)]">Status</dt>
                    <dd class="font-medium text-[var(--color-ink-strong)]">{{ $hasActiveSubscription ? 'Active' : 'No payment method' }}</dd>
                </div>
            </dl>

            @if ($billingPlans->isNotEmpty())
                <div class="mt-6 grid gap-3">
                    @foreach ($billingPlans as $billingPlan)
                        <div class="rounded-lg border border-[var(--color-line)] bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $billingPlan->name }}</p>
                                    @if ($billingPlan->price_label)
                                        <p class="numeric mt-1 text-sm text-[var(--color-ink-soft)]">{{ $billingPlan->price_label }}</p>
                                    @endif
                                </div>
                            </div>

                            @if ($billingReady)
                                <a href="{{ route('billing.checkout', $billingPlan) }}" class="sk-btn sk-btn-primary mt-4 w-full justify-center text-center">Upgrade</a>
                            @else
                                <button type="button" class="sk-btn sk-btn-outline mt-4 w-full justify-center" disabled>Checkout disabled</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($hasActiveSubscription)
                <form method="POST" action="{{ route('billing.payment-method.update') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="sk-btn sk-btn-outline w-full justify-center">Update payment method</button>
                </form>
            @elseif (! $billingReady)
                <p class="mt-4 text-sm leading-6 text-[var(--color-ink-soft)]">Connect the Paddle API key and client-side token to enable checkout.</p>
            @endif
        </section>
    </aside>
</div>
@endsection
