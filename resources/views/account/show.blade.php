@extends('layouts.app-shell')

@section('title', __('account.page.title').' · '.config('app.name'))
@section('page_heading', __('account.page.title'))

@php
    $recipeUsage = $usage['saved_recipes'];
    $ingredientUsage = $usage['private_ingredients'];
    $productionBatchUsage = $usage['production_batches'];
    $usagePercent = function (array $line): int {
        if (($line['limit'] ?? null) === null || (int) $line['limit'] <= 0) {
            return 0;
        }

        return min(100, (int) round(((int) $line['used'] / (int) $line['limit']) * 100));
    };
    $usageLabel = fn (array $line): string => ($line['limit'] ?? null) === null
        ? __('account.usage.used_unlimited', ['used' => $line['used']])
        : __('account.usage.used', ['used' => $line['used'], 'limit' => $line['limit']]);
    $remainingLabel = fn (array $line): string => ($line['remaining'] ?? null) === null
        ? __('account.usage.unlimited')
        : __('account.usage.remaining', ['count' => $line['remaining']]);
    $hasActiveSubscription = $currentSubscription !== null;
@endphp

@section('content')
<div class="mx-auto grid w-full max-w-6xl gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-6">
        <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('account.page.intro') }}</p>

        <section aria-labelledby="account-profile-heading" class="sk-card p-6">
            <div class="flex flex-col gap-4 border-b border-[var(--color-line)] pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 id="account-profile-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('account.profile.heading') }}</h3>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('account.profile.description') }}</p>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="sk-btn sk-btn-outline">{{ __('account.actions.sign_out') }}</button>
                </form>
            </div>

            @if (session('profile_status'))
                <p role="status" class="mt-5 rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm font-medium text-[var(--color-success-strong)]">{{ session('profile_status') }}</p>
            @endif

            <form method="POST" action="{{ route('account.profile.update') }}" class="mt-6 grid gap-5">
                @csrf
                @method('PATCH')

                <div class="grid gap-5 md:grid-cols-2 md:items-start">
                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.profile.name') }}</span>
                        <input name="name" value="{{ old('name', $user->name) }}" required autocomplete="name" aria-label="{{ __('account.profile.name') }}" aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="account-name-error" @enderror class="sk-input">
                        @error('name')
                            <span id="account-name-error" role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</span>
                        @enderror
                    </label>

                    <div class="grid content-start gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.profile.email') }}</span>
                        <p class="sk-input cursor-not-allowed bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]" aria-label="{{ __('account.profile.email') }}">{{ $user->email }}</p>
                        <p class="text-xs text-[var(--color-ink-soft)]">{{ __('account.profile.email_help') }}</p>
                    </div>
                </div>

                <div>
                    <button type="submit" class="sk-btn sk-btn-primary">{{ __('account.actions.save_profile') }}</button>
                </div>
            </form>
        </section>

        <section aria-labelledby="account-password-heading" class="sk-card p-6">
            <h3 id="account-password-heading" class="text-xl font-semibold text-[var(--color-ink-strong)]">{{ __('account.security.heading') }}</h3>
            <p class="mt-2 text-sm text-[var(--color-ink-soft)]">{{ __('account.security.description') }}</p>

            @if (session('password_status'))
                <p role="status" class="mt-5 rounded-lg bg-[var(--color-success-soft)] px-4 py-3 text-sm font-medium text-[var(--color-success-strong)]">{{ session('password_status') }}</p>
            @endif

            <form method="POST" action="{{ route('account.password.update') }}" class="mt-6 grid gap-5">
                @csrf
                @method('PATCH')

                <label class="grid content-start gap-2">
                    <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.security.current_password') }}</span>
                    <input type="password" name="current_password" required autocomplete="current-password" aria-label="{{ __('account.security.current_password') }}" aria-invalid="@error('current_password') true @else false @enderror" @error('current_password') aria-describedby="account-current-password-error" @enderror class="sk-input">
                    @error('current_password')
                        <span id="account-current-password-error" role="alert" class="text-sm text-[var(--color-danger-strong)]">{{ $message }}</span>
                    @enderror
                </label>

                <div class="grid gap-5 md:grid-cols-2 md:items-start">
                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.security.new_password') }}</span>
                        <input type="password" name="password" required autocomplete="new-password" aria-label="{{ __('account.security.new_password') }}" aria-invalid="@error('password') true @else false @enderror" aria-describedby="account-password-requirements @error('password') account-password-error @enderror" class="sk-input">
                        <p id="account-password-requirements" class="text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('auth.password_requirements') }}</p>
                        @if ($errors->has('password'))
                            <ul id="account-password-error" role="alert" class="grid list-disc gap-1 pl-5 text-sm leading-5 text-[var(--color-danger-strong)]">
                                @foreach ($errors->get('password') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </label>

                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.security.confirm_new_password') }}</span>
                        <input type="password" name="password_confirmation" required autocomplete="new-password" aria-label="{{ __('account.security.confirm_new_password') }}" aria-invalid="@error('password') true @else false @enderror" aria-describedby="account-password-requirements @error('password') account-password-error @enderror" class="sk-input">
                    </label>
                </div>

                <div>
                    <button type="submit" class="sk-btn sk-btn-primary">{{ __('account.actions.update_password') }}</button>
                </div>
            </form>
        </section>
    </div>

    <aside class="space-y-6">
        <section aria-labelledby="account-plan-heading" class="sk-card p-6">
            <p class="sk-eyebrow">{{ __('account.plan.heading') }}</p>
            <h3 id="account-plan-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $plan?->name ?? __('account.plan.none') }}</h3>
            @if ($plan?->description)
                <p class="mt-2 text-sm leading-6 text-[var(--color-ink-soft)]">{{ $plan->description }}</p>
            @endif

            <p class="mt-6 text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('account.plan.usage_heading') }}</p>

            <div class="mt-3 space-y-4">
                <div class="rounded-lg border border-[var(--color-line)] bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.usage.products') }}</p>
                        <p class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $usageLabel($recipeUsage) }}</p>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
                        <div class="h-full rounded-full bg-[var(--color-accent)]" style="width: {{ $usagePercent($recipeUsage) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $remainingLabel($recipeUsage) }}</p>
                </div>

                <div class="rounded-lg border border-[var(--color-line)] bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.usage.ingredients') }}</p>
                        <p class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $usageLabel($ingredientUsage) }}</p>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
                        <div class="h-full rounded-full bg-[var(--color-accent)]" style="width: {{ $usagePercent($ingredientUsage) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $remainingLabel($ingredientUsage) }}</p>
                </div>

                <div class="rounded-lg border border-[var(--color-line)] bg-white p-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-medium text-[var(--color-ink-strong)]">{{ __('account.usage.production_batches') }}</p>
                        <p class="numeric text-sm font-semibold text-[var(--color-ink-strong)]">{{ $usageLabel($productionBatchUsage) }}</p>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--color-field-muted)]">
                        <div class="h-full rounded-full bg-[var(--color-accent)]" style="width: {{ $usagePercent($productionBatchUsage) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-[var(--color-ink-soft)]">{{ $remainingLabel($productionBatchUsage) }}</p>
                </div>
            </div>
        </section>

        <section aria-labelledby="account-billing-heading" class="sk-card p-6">
            <p class="sk-eyebrow">{{ __('account.billing.heading') }}</p>
            <h3 id="account-billing-heading" class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">{{ $hasActiveSubscription ? __('account.billing.active_subscription') : __('account.billing.free_account') }}</h3>

            @if (session('billing_status'))
                <p role="status" class="mt-5 rounded-lg bg-[var(--color-warning-soft)] px-4 py-3 text-sm font-medium text-[var(--color-warning-strong)]">{{ session('billing_status') }}</p>
            @endif

            <dl class="mt-5 grid gap-3 text-sm">
                <div class="flex items-center justify-between gap-3 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5">
                    <dt class="text-[var(--color-ink-soft)]">{{ __('account.billing.provider') }}</dt>
                    <dd class="font-medium text-[var(--color-ink-strong)]">Paddle</dd>
                </div>
                <div class="flex items-center justify-between gap-3 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5">
                    <dt class="text-[var(--color-ink-soft)]">{{ __('account.billing.status') }}</dt>
                    <dd class="font-medium text-[var(--color-ink-strong)]">{{ $hasActiveSubscription ? __('account.billing.active') : __('account.billing.no_payment_method') }}</dd>
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
                                <a href="{{ route('billing.checkout', $billingPlan) }}" class="sk-btn sk-btn-primary mt-4 w-full justify-center text-center">{{ __('account.actions.choose_plan') }}</a>
                            @else
                                <button type="button" class="sk-btn sk-btn-outline mt-4 w-full justify-center" disabled>{{ __('account.actions.checkout_unavailable') }}</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($hasActiveSubscription)
                <form method="POST" action="{{ route('billing.payment-method.update') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="sk-btn sk-btn-outline w-full justify-center">{{ __('account.actions.update_payment_method') }}</button>
                </form>
            @elseif (! $billingReady)
                <p class="mt-4 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('account.billing.online_checkout_unavailable') }}</p>
            @endif
        </section>
    </aside>
</div>
@endsection
