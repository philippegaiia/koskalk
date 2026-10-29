@extends('layouts.public')

@section('title', __('calculators.index_title').' · '.config('app.name'))

@push('head')
    @vite('resources/css/app.css')
@endpush

@section('content')
    <div class="mx-auto flex w-full max-w-app flex-col gap-10 px-5 pt-28 pb-16 lg:px-10">
        <header class="flex max-w-2xl flex-col gap-4">
            <h1 class="font-display text-4xl tracking-tight text-ink-strong sm:text-5xl">{{ __('calculators.index_title') }}</h1>
            <p class="text-lg leading-relaxed text-ink-soft">{{ __('calculators.index_description') }}</p>
        </header>
        <div class="grid gap-5 md:grid-cols-3">
            @foreach ($calculators as $calculator)
                <article class="flex flex-col gap-4 rounded-xl border border-line bg-white p-6">
                    <h2 class="font-display text-2xl text-ink-strong">{{ $calculator['title'] }}</h2>
                    <p class="flex-1 text-sm leading-relaxed text-ink-soft">{{ $calculator['description'] }}</p>
                    @if ($calculator['available'] ?? true)
                        <a href="{{ route('calculators.show', ['calculator' => $calculator['key']]) }}" class="sk-btn sk-btn-outline min-h-11">{{ __('calculators.open_calculator') }}</a>
                    @else
                        <p class="text-sm font-medium text-ink-soft">{{ __('calculators.coming_soon') }}</p>
                    @endif
                </article>
            @endforeach
        </div>
        <p class="max-w-2xl text-sm leading-relaxed text-ink-soft">{{ __('calculators.guest_notice') }}</p>
    </div>
@endsection
