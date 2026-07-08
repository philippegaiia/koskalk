@extends('layouts.public')

@section('title', 'Create account · '.config('app.name'))

@section('content')
<section aria-labelledby="register-heading" class="flex flex-1 items-center px-4 pb-8 pt-[calc(58px+2rem)] sm:px-6 lg:px-10">
    <div class="mx-auto grid w-full max-w-[980px] gap-6 lg:grid-cols-[minmax(360px,460px)_minmax(0,0.85fr)] lg:items-stretch">
        <div class="rounded-lg border border-line bg-panel p-5 shadow-sm sm:p-7">
            <div class="max-w-md">
                <p class="sk-eyebrow">Free account</p>
                <h1 id="register-heading" class="mt-3 text-2xl font-semibold text-ink-strong">Create your Soapkraft account.</h1>
                <p class="mt-3 text-sm leading-6 text-ink-soft">No credit card required. You can start with the free beta plan and upgrade when your workspace grows.</p>
            </div>

            <form method="POST" action="{{ route('register') }}" class="mt-7 grid gap-5">
                @csrf

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Name</span>
                    <input name="name" value="{{ old('name') }}" required autocomplete="name" aria-label="Name" aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="register-name-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    @error('name')
                        <span id="register-name-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" aria-label="Email" aria-invalid="@error('email') true @else false @enderror" @error('email') aria-describedby="register-email-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    @error('email')
                        <span id="register-email-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Password</span>
                    <input type="password" name="password" required autocomplete="new-password" aria-label="Password" aria-invalid="@error('password') true @else false @enderror" @error('password') aria-describedby="register-password-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    @error('password')
                        <span id="register-password-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <label class="grid gap-2">
                    <span class="text-sm font-medium text-ink-strong">Confirm password</span>
                    <input type="password" name="password_confirmation" required autocomplete="new-password" aria-label="Confirm password" aria-invalid="@error('password_confirmation') true @else false @enderror" @error('password_confirmation') aria-describedby="register-password-confirmation-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    @error('password_confirmation')
                        <span id="register-password-confirmation-error" role="alert" class="text-sm text-red-700">{{ $message }}</span>
                    @enderror
                </label>

                <button type="submit" class="min-h-11 rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-inverse transition hover:bg-accent-hover">Create account</button>
            </form>

            <p class="mt-6 text-center text-sm text-ink-soft">
                Already registered?
                <a href="{{ route('login') }}" class="font-medium text-accent-strong no-underline hover:underline">Sign in</a>
            </p>
        </div>

        <aside class="rounded-lg border border-line bg-forest-deep p-6 text-inverse shadow-sm sm:p-7 lg:flex lg:flex-col lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.08em] text-sage-light">Free beta · full formulation tools</p>
                <h2 class="mt-4 max-w-md text-2xl font-semibold leading-tight">Save complete formulas from your very first batch.</h2>
                <p class="mt-4 max-w-md text-sm leading-6 text-inverse-soft">Keep every oil blend, superfat, and lye calculation — alongside your own ingredient library, priced at the real cost per gram.</p>
            </div>

            <dl class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                <div class="rounded-lg border border-forest-light/60 bg-forest-mid/60 px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-[0.06em] text-inverse-soft">Saved formulas</dt>
                    <dd class="mt-1 text-2xl font-semibold text-inverse">15</dd>
                </div>
                <div class="rounded-lg border border-forest-light/60 bg-forest-mid/60 px-4 py-3">
                    <dt class="text-xs font-medium uppercase tracking-[0.06em] text-inverse-soft">Private ingredients</dt>
                    <dd class="mt-1 text-2xl font-semibold text-inverse">20</dd>
                </div>
                <p class="text-xs leading-5 text-inverse-soft">No card, no trial timer — the beta plan stays put until you choose to scale.</p>
            </dl>
        </aside>
    </div>
</section>
@endsection
