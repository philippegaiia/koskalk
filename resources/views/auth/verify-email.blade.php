@extends('layouts.public')

@section('title', __('auth.verification.page_title').' · '.config('app.name'))

@section('content')
<section class="flex flex-1 items-center px-5 pb-12 pt-[calc(58px+3rem)] lg:px-10">
    <div class="mx-auto w-full max-w-xl rounded-lg border border-line bg-panel p-7 shadow-sm">
        <p class="sk-eyebrow">{{ __('auth.verification.eyebrow') }}</p>
        <h1 class="mt-3 text-2xl font-semibold text-ink-strong">{{ __('auth.verification.heading') }}</h1>
        <p class="mt-4 text-sm leading-7 text-ink-soft">{{ __('auth.verification.body') }}</p>

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="sk-btn sk-btn-outline">{{ __('auth.verification.sign_out') }}</button>
        </form>
    </div>
</section>
@endsection
