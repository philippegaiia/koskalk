@extends('layouts.public')

@section('title', 'Sign in · '.config('app.name'))

@section('content')
<section aria-labelledby="login-heading" class="mx-auto flex min-h-[calc(100vh-58px)] w-full max-w-[1180px] items-center px-5 pb-16 pt-28 lg:px-10">
    <div class="mx-auto w-full max-w-md">
        <div class="sk-shell-line rounded-lg bg-panel p-6 shadow-sm">
            <p class="sk-eyebrow">Account</p>
            <h1 id="login-heading" class="mt-3 text-2xl font-semibold text-ink-strong">Sign in to your workspace.</h1>

            <form method="POST" action="{{ route('login') }}" class="mt-8 grid gap-5">
                @csrf

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-label="Email" aria-invalid="@error('email') true @else false @enderror" @error('email') aria-describedby="login-email-error" @enderror class="rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-strong outline-none transition focus:border-accent">
                    @error('email')
                        <span id="login-email-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Password</span>
                    <input type="password" name="password" required autocomplete="current-password" aria-label="Password" aria-invalid="@error('password') true @else false @enderror" @error('password') aria-describedby="login-password-error" @enderror class="rounded-lg border border-line bg-cream px-4 py-3 text-sm text-ink-strong outline-none transition focus:border-accent">
                    @error('password')
                        <span id="login-password-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="flex items-center gap-3 text-sm text-ink-soft">
                    <input type="checkbox" name="remember" value="1" aria-label="Remember me" class="size-4 rounded border-line text-accent">
                    Remember me
                </label>

                <button type="submit" class="rounded-md bg-accent px-5 py-3 text-sm font-semibold text-inverse transition hover:bg-accent-hover">Sign in</button>
            </form>

            <p class="mt-6 text-center text-sm text-ink-soft">
                New to Soapkraft?
                <a href="{{ route('register') }}" class="font-medium text-accent-strong no-underline hover:underline">Create an account</a>
            </p>
        </div>
    </div>
</section>
@endsection
