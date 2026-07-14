@extends('layouts.public')

@section('title', __('auth.login.page_title').' · '.config('app.name'))

@section('content')
<section aria-labelledby="login-heading" class="flex flex-1 items-center px-4 pb-8 pt-[calc(58px+2rem)] sm:px-6 lg:px-10">
    <div class="mx-auto w-full max-w-[440px] rounded-lg border border-forest-light/60 bg-forest-deep p-5 shadow-sm sm:p-7">
        <h1 id="login-heading" class="text-2xl font-semibold text-inverse">{{ __('auth.login.heading') }}</h1>

        <form method="POST" action="{{ route('login') }}" class="mt-7 grid gap-5">
            @csrf

            <label class="grid gap-2">
                <span class="text-sm font-medium text-inverse">{{ __('auth.login.email') }}</span>
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-label="{{ __('auth.login.email') }}" aria-invalid="@error('email') true @else false @enderror" @error('email') aria-describedby="login-email-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                @error('email')
                    <span id="login-email-error" role="alert" class="text-sm text-danger-soft">{{ $message }}</span>
                @enderror
            </label>

            <label class="grid gap-2">
                <span class="text-sm font-medium text-inverse">{{ __('auth.login.password') }}</span>
                <input type="password" name="password" required autocomplete="current-password" aria-label="{{ __('auth.login.password') }}" aria-invalid="@error('password') true @else false @enderror" @error('password') aria-describedby="login-password-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                @error('password')
                    <span id="login-password-error" role="alert" class="text-sm text-danger-soft">{{ $message }}</span>
                @enderror
            </label>

            <label class="flex items-center gap-3 text-sm text-inverse-soft">
                <input type="checkbox" name="remember" value="1" aria-label="{{ __('auth.login.remember_me') }}" class="size-4 rounded border-line accent-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                {{ __('auth.login.remember_me') }}
            </label>

            <button type="submit" class="min-h-11 rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ __('auth.login.submit') }}</button>
        </form>

        <p class="mt-6 text-center text-sm text-inverse-soft">
            {{ __('auth.login.invitation_only') }}
        </p>
    </div>
</section>
@endsection
