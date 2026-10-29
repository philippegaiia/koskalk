@extends('layouts.print')

@section('title', ($draft['name'] ?: $calculator['title']).' · '.config('app.name'))
@section('print_document_class', 'formula-sheet-document')

@section('content')
    <div class="print-hidden mx-auto mb-5 flex max-w-5xl flex-wrap items-center justify-between gap-4 border border-line bg-white p-4">
        <a data-return-to-workbench href="{{ route('calculators.show', ['calculator' => $calculator['key']]) }}" class="text-sm text-accent-strong">{{ __('calculators.back_to_calculator') }}</a>
        <div class="flex flex-col items-start gap-1">
            <button type="button" data-print-document class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"><x-action-icon name="printer" />{{ __('calculators.print_sheet') }}</button>
            <p class="text-xs text-ink-soft">{{ __('calculators.print_hint') }}</p>
        </div>
    </div>
    <article class="document-sheet mx-auto flex max-w-5xl flex-col gap-5 bg-white p-6 text-slate-900 print:mx-0 print:max-w-none print:p-0">
        <header class="flex flex-col gap-2 border-b border-slate-300 pb-4">
            <h1 class="text-2xl font-semibold">{{ $draft['name'] ?: $calculator['title'] }}</h1>
            <p class="text-xs text-slate-600" data-local-calculation-time data-timestamp="{{ $calculated_at }}" data-template="{{ __('calculators.calculated_at', ['date' => ':date']) }}">{{ __('calculators.calculated_at', ['date' => \Carbon\CarbonImmutable::parse($calculated_at)->utc()->locale(app()->getLocale())->isoFormat('LLL').' UTC']) }}</p>
            <p class="text-xs text-slate-600">{{ __('calculators.provenance') }}</p>
        </header>
        @foreach ($warnings as $warning)
            <p class="rounded border border-slate-300 p-3 text-sm">{{ $warning }}</p>
        @endforeach
        <x-formula-document.settings :document="$document" />
        <x-formula-document.table :document="$document" :number-locale="$numberLocale" />
        <x-formula-document.liquid-process :document="$document" :number-locale="$numberLocale" />
        <x-formula-document.results :document="$document" :number-locale="$numberLocale" />
        @if (filled($draft['notes'] ?? ''))
            <section class="flex flex-col gap-2">
                <h2 class="text-sm font-semibold">{{ __('calculators.notes') }}</h2>
                <p class="whitespace-pre-wrap text-sm">{{ $draft['notes'] }}</p>
            </section>
        @endif
        <section class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold">{{ $labeling['incomplete'] ? __('calculators.label_incomplete') : __('calculators.label_draft') }}</h2>
            <p class="text-sm">{{ $labeling['text'] }}</p>
            <p class="text-xs text-slate-600">{{ __('calculators.allergens_unchecked') }}</p>
            @if ($labeling['incomplete'])
                <p class="text-xs text-slate-600">{{ __('calculators.label_incomplete') }}</p>
            @endif
        </section>
        @if (is_array($document['soap_analysis'] ?? null))
            <x-formula-document.soap-analysis :analysis="$document['soap_analysis']" :number-locale="$numberLocale" />
        @endif
    </article>
@endsection
