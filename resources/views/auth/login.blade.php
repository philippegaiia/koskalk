@extends('layouts.public')

@section('title', 'Sign in · '.config('app.name'))

@section('content')
<section aria-labelledby="login-heading" class="flex flex-1 items-center px-4 pb-8 pt-[calc(58px+2rem)] sm:px-6 lg:px-10">
    <div class="mx-auto grid w-full max-w-[960px] gap-6 lg:grid-cols-[minmax(0,0.85fr)_minmax(360px,440px)] lg:items-stretch">
        <aside class="hidden rounded-lg border border-line bg-forest-deep p-8 text-inverse shadow-sm lg:flex lg:flex-col lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-sage-light">Your formulation workspace</p>
                <h2 class="mt-4 max-w-md text-3xl font-semibold leading-tight">Your formulas, library, and costings — exactly as you left them.</h2>
                <p class="mt-4 max-w-md text-sm leading-6 text-inverse-soft">Every saved recipe keeps its oil ratios, superfat, and lye calculation — and the batch cost alongside it.</p>
            </div>

            <div class="mt-10 grid gap-3 text-sm text-inverse-soft">
                <div class="rounded-lg border border-forest-light/60 bg-forest-mid/60 px-4 py-3">Every version of a formula stays tied to its product.</div>
                <div class="rounded-lg border border-forest-light/60 bg-forest-mid/60 px-4 py-3">Your oil and ingredient library is ready for the next batch.</div>
            </div>
        </aside>

        <div class="rounded-lg border border-line bg-panel p-5 shadow-sm sm:p-7">
            <div class="max-w-md">
                <p class="sk-eyebrow">Account</p>
                <h1 id="login-heading" class="mt-3 text-2xl font-semibold text-ink-strong lg:hidden">Sign in to your workspace.</h1>
                <h1 class="mt-3 hidden text-2xl font-semibold text-ink-strong lg:block">Sign in</h1>
            </div>

            <form method="POST" action="{{ route('login') }}" class="mt-7 grid gap-5">
                @csrf

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-label="Email" aria-invalid="@error('email') true @else false @enderror" @error('email') aria-describedby="login-email-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    @error('email')
                        <span id="login-email-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Password</span>
                    <input type="password" name="password" required autocomplete="current-password" aria-label="Password" aria-invalid="@error('password') true @else false @enderror" @error('password') aria-describedby="login-password-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    @error('password')
                        <span id="login-password-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="flex items-center gap-3 text-sm text-ink-soft">
                    <input type="checkbox" name="remember" value="1" aria-label="Remember me" class="size-4 rounded border-line text-accent">
                    Remember me
                </label>

                <button type="submit" class="min-h-11 rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-inverse transition hover:bg-accent-hover">Sign in</button>
            </form>

            <p class="mt-6 text-center text-sm text-ink-soft">
                New to Soapkraft?
                <a href="{{ route('register') }}" class="font-medium text-accent-strong no-underline hover:underline">Create an account</a>
            </p>
        </div>
    </div>
</section>
@endsection
