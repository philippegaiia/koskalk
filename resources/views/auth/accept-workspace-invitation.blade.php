@extends('layouts.public')

@section('title', __('workspaces.acceptance.page_heading').' · '.config('app.name'))

@section('content')
<section aria-labelledby="workspace-invitation-heading" class="flex flex-1 items-center px-4 pb-8 pt-[calc(58px+2rem)] sm:px-6 lg:px-10">
    <div class="mx-auto w-full max-w-[440px] rounded-lg border border-forest-light/60 bg-forest-deep p-5 shadow-sm sm:p-7">
        <h1 id="workspace-invitation-heading" class="text-2xl font-semibold text-inverse">{{ __('workspaces.acceptance.page_heading') }}</h1>
        <p class="mt-3 text-sm leading-6 text-inverse-soft">{{ __('workspaces.acceptance.invited_description', ['company' => $invitation->workspace->name]) }}</p>
        <dl class="mt-5 grid gap-3 text-sm">
            <div>
                <dt class="text-inverse-soft">{{ __('workspaces.acceptance.invited_email') }}</dt>
                <dd class="mt-1 break-words font-medium text-inverse">{{ $invitation->email }}</dd>
            </div>
            <div>
                <dt class="text-inverse-soft">{{ __('workspaces.invitation.role') }}</dt>
                <dd class="mt-1 font-medium text-inverse">{{ __('workspaces.roles.'.$invitation->role->value) }}</dd>
            </div>
        </dl>

        @if ($errors->any())
            <ul role="alert" class="mt-5 grid list-disc gap-1 pl-5 text-sm leading-5 text-danger-soft">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        @if (auth()->check() && ! $emailMatches)
            <p class="mt-6 text-sm leading-6 text-inverse-soft">{{ __('workspaces.acceptance.wrong_email_description') }}</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-5">
                @csrf
                <button type="submit" class="min-h-11 w-full rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ __('auth.verification.sign_out') }}</button>
            </form>
        @elseif (auth()->check() && ! auth()->user()->hasVerifiedEmail())
            <p class="mt-6 text-sm leading-6 text-inverse-soft">{{ __('workspaces.acceptance.verify_email_description') }}</p>
            <a href="{{ route('verification.notice') }}" class="mt-5 flex min-h-11 items-center justify-center rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ __('workspaces.acceptance.verify_email') }}</a>
        @elseif ($requiresLogin)
            <p class="mt-6 text-sm leading-6 text-inverse-soft">{{ __('workspaces.acceptance.sign_in_description') }}</p>
            <a href="{{ route('login') }}" class="mt-5 flex min-h-11 items-center justify-center rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ __('auth.login.submit') }}</a>
        @else
            <form method="POST" action="{{ route('workspace-invitations.accept', ['token' => $token]) }}" class="mt-7 grid gap-5">
                @csrf
                @guest
                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-inverse">{{ __('auth.login.email') }}</span>
                        <input type="email" value="{{ $invitation->email }}" readonly autocomplete="email" class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline">
                    </label>
                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-inverse">{{ __('workspaces.acceptance.name') }}</span>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition placeholder:text-ink-soft focus:border-accent focus:outline-2 focus:outline-accent">
                    </label>
                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-inverse">{{ __('workspaces.acceptance.create_password') }}</span>
                        <input type="password" name="password" required autocomplete="new-password" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" aria-describedby="workspace-invitation-password-requirements" class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition focus:border-accent focus:outline-2 focus:outline-accent">
                        <p id="workspace-invitation-password-requirements" class="text-xs leading-5 text-inverse-soft">{{ __('auth.password_requirements') }}</p>
                    </label>
                    <label class="grid content-start gap-2">
                        <span class="text-sm font-medium text-inverse">{{ __('workspaces.acceptance.confirm_password') }}</span>
                        <input type="password" name="password_confirmation" required autocomplete="new-password" aria-invalid="{{ $errors->has('password_confirmation') ? 'true' : 'false' }}" class="w-full rounded-lg border border-line bg-field px-4 py-3 text-sm text-ink-strong outline outline-1 outline-field-outline transition focus:border-accent focus:outline-2 focus:outline-accent">
                    </label>
                @endguest
                <button type="submit" class="min-h-11 rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-on-accent transition hover:bg-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">{{ __('workspaces.acceptance.accept') }}</button>
            </form>
        @endif

        <p class="mt-6 text-center text-sm text-inverse-soft">{{ __('workspaces.acceptance.expires', ['time' => $invitation->expires_at->diffForHumans()]) }}</p>
    </div>
</section>
@endsection
