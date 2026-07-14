@extends('layouts.public')

@section('title', 'Account verification · '.config('app.name'))

@section('content')
<section class="flex flex-1 items-center px-5 pb-12 pt-[calc(58px+3rem)] lg:px-10">
    <div class="mx-auto w-full max-w-xl rounded-lg border border-line bg-panel p-7 shadow-sm">
        <p class="sk-eyebrow">Account security</p>
        <h1 class="mt-3 text-2xl font-semibold text-ink-strong">This account is not verified.</h1>
        <p class="mt-4 text-sm leading-7 text-ink-soft">Access is provisioned by the administrator. Contact the administrator to verify this account before opening the private workspace.</p>
    </div>
</section>
@endsection
