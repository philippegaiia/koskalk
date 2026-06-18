@extends('layouts.public')

@section('title', 'Create account · '.config('app.name'))

@section('content')
<section aria-labelledby="register-heading" class="mx-auto flex min-h-[calc(100vh-58px)] w-full max-w-[1180px] items-center px-5 pb-16 pt-28 lg:px-10">
    <div class="mx-auto w-full max-w-md">
        <div class="sk-shell-line rounded-lg bg-panel p-6 shadow-sm">
            <p class="sk-eyebrow">Free account</p>
            <h1 id="register-heading" class="mt-3 text-2xl font-semibold text-ink-strong">Create your Soapkraft account.</h1>
            <p class="mt-3 text-sm leading-6 text-ink-soft">Save 15 formulas and 20 private ingredients. No credit card required.</p>

            <form method="POST" action="{{ route('register') }}" class="mt-8 grid gap-5">
                @csrf

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Name</span>
                    <input name="name" value="{{ old('name') }}" required autocomplete="name" aria-label="Name" aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="register-name-error" @enderror class="rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-strong outline-none transition focus:border-accent">
                    @error('name')
                        <span id="register-name-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-label="Email" aria-invalid="@error('email') true @else false @enderror" @error('email') aria-describedby="register-email-error" @enderror class="rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-strong outline-none transition focus:border-accent">
                    @error('email')
                        <span id="register-email-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Password</span>
                    <input type="password" name="password" required autocomplete="new-password" aria-label="Password" aria-invalid="@error('password') true @else false @enderror" @error('password') aria-describedby="register-password-error" @enderror class="rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-strong outline-none transition focus:border-accent">
                    @error('password')
                        <span id="register-password-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Confirm password</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password" aria-label="Confirm password" aria-invalid="@error('password_confirmation') true @else false @enderror" @error('password_confirmation') aria-describedby="register-password-confirmation-error" @enderror class="rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-strong outline-none transition focus:border-accent">
                    @error('password_confirmation')
                        <span id="register-password-confirmation-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <button type="submit" class="rounded-md bg-accent px-5 py-3 text-sm font-semibold text-inverse transition hover:bg-accent-hover">Create account</button>
            </form>

            <p class="mt-6 text-center text-sm text-ink-soft">
                Already registered?
                <a href="{{ route('login') }}" class="font-medium text-accent-strong no-underline hover:underline">Sign in</a>
            </p>
        </div>
    </div>
</section>
@endsection
