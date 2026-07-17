@extends('layouts.public')

@section('title', 'Create your workspace · '.config('app.name'))

@section('content')
<section aria-labelledby="beta-invite-heading" class="flex flex-1 items-center px-4 pb-8 pt-[calc(58px+2rem)] sm:px-6 lg:px-10">
    <div class="mx-auto w-full max-w-[440px] rounded-lg border border-forest-light/60 bg-forest-deep p-5 shadow-sm sm:p-7">
        <p class="text-sm font-medium text-accent-soft">Soapkraft beta</p>
        <h1 id="beta-invite-heading" class="mt-2 text-2xl font-semibold text-inverse">Create your workspace</h1>
        <p class="mt-3 text-sm leading-6 text-inverse-soft">You are joining {{ $invite->workspace_name }} with {{ $invite->email }}.</p>

        <form method="POST" action="{{ route('beta-invites.accept', ['token' => $token]) }}" class="mt-7 grid gap-5">
            @csrf

            <label class="grid content-start gap-2">
                <span class="text-sm font-medium text-inverse">Name</span>
                <input type="text" name="name" value="{{ old('name') }}" required autocomplete="name" aria-invalid="@error('name') true @else false @enderror" @error('name') aria-describedby="beta-invite-name-error" @enderror class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                @error('name')
                    <span id="beta-invite-name-error" role="alert" class="text-sm text-danger-soft">{{ $message }}</span>
                @enderror
            </label>

            <label class="grid content-start gap-2">
                <span class="text-sm font-medium text-inverse">Create password</span>
                <input type="password" name="password" required autocomplete="new-password" aria-invalid="@error('password') true @else false @enderror" aria-describedby="beta-invite-password-requirements @error('password') beta-invite-password-error @enderror" class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                <p id="beta-invite-password-requirements" class="text-xs leading-5 text-inverse-soft">{{ __('auth.password_requirements') }}</p>
                @if ($errors->has('password'))
                    <ul id="beta-invite-password-error" role="alert" class="grid list-disc gap-1 pl-5 text-sm leading-5 text-danger-soft">
                        @foreach ($errors->get('password') as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </label>

            <label class="grid content-start gap-2">
                <span class="text-sm font-medium text-inverse">Confirm password</span>
                <input type="password" name="password_confirmation" required autocomplete="new-password" aria-invalid="@error('password') true @else false @enderror" aria-describedby="beta-invite-password-requirements @error('password') beta-invite-password-error @enderror" class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus-visible:outline-accent">
            </label>

            <button type="submit" class="min-h-11 rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">Create workspace</button>
        </form>

        <p class="mt-6 text-center text-sm text-inverse-soft">This invitation expires {{ $invite->expires_at->diffForHumans() }}.</p>
    </div>
</section>
@endsection
