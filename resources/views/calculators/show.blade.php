@extends('layouts.public', ['usesWorkbenchStyles' => true])

@section('title', $calculator['title'].' · '.config('app.name'))

@push('head')
    @filamentStyles
    @vite('resources/js/app.js')
@endpush

@push('scripts')
    @livewireScripts
    @filamentScripts
@endpush

@section('content')
    <div data-user-shell class="min-h-full bg-[var(--color-surface)] px-4 pt-24 pb-10 text-[var(--color-ink)] sm:px-6 lg:px-8" style="--app-sidebar-width: 0rem">
        <div class="mx-auto mb-4 flex max-w-app items-center justify-between gap-3 text-sm">
            <a href="{{ route('calculators.index') }}" class="font-medium text-[var(--color-accent-strong)] hover:underline"><span aria-hidden="true">←</span> {{ __('calculators.all_calculators') }}</a>
            <div id="contextual-help-topbar"></div>
        </div>
        @include('livewire.dashboard.recipe-workbench', ['canEditRecipe' => true])
        <x-contextual-help.panel />
    </div>
@endsection
