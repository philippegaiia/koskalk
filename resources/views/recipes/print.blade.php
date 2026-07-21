@extends('layouts.print')

@section('title', $formulaDocument['identity']['name'].' · '.__('formula_documents.print.title').' · '.config('app.name'))

@section('content')
    @php
        $description = (string) ($formulaDocument['identity']['description'] ?? '');
        $manufacturingProcedure = (string) ($formulaDocument['identity']['manufacturing_procedure'] ?? '');
        $hasDescription = filled(preg_replace('/\s+/u', '', html_entity_decode(strip_tags($description))));
        $hasManufacturingProcedure = filled(preg_replace('/\s+/u', '', html_entity_decode(strip_tags($manufacturingProcedure))));
    @endphp

    <div class="print-hidden mb-4 flex items-center justify-between gap-3 border border-slate-300 bg-white p-4">
        <a href="{{ route('recipes.saved', ['recipe' => $recipe]) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium">{{ __('formula_documents.actions.back') }}</a>
        <button type="button" data-print-document class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">{{ __('formula_documents.actions.print') }}</button>
    </div>

    <article class="document-sheet mx-auto max-w-5xl bg-white p-6 text-slate-900 print:max-w-none print:p-0">
        <header class="flex items-start justify-between gap-6 border-b border-slate-400 pb-4">
            <div>
                <p class="text-xs font-semibold tracking-[0.12em] text-slate-500 uppercase">{{ __('formula_documents.print.title') }}</p>
                <h1 class="mt-1 text-2xl font-semibold">{{ $formulaDocument['identity']['name'] }}</h1>
            </div>
            <p class="text-right text-xs text-slate-600">{{ number_format($formulaDocument['basis_weight'], 2) }} {{ $formulaDocument['unit'] }}</p>
        </header>

        <section class="mt-4 grid grid-cols-4 border border-slate-400 text-xs">
            @foreach ([
                __('formula_documents.print.batch_number'),
                __('formula_documents.print.date'),
                __('formula_documents.print.made_by'),
                __('formula_documents.print.checked_by'),
            ] as $field)
                <div class="min-h-14 border-r border-slate-300 p-2 last:border-r-0">
                    <p class="font-semibold">{{ $field }}</p>
                </div>
            @endforeach
        </section>

        <x-formula-document.table :document="$formulaDocument" class="mt-4" />
        <x-formula-document.results :document="$formulaDocument" class="mt-4" />

        @if ($hasDescription)
            <section class="mt-5 break-inside-avoid">
                <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.description') }}</h2>
                <div class="prose prose-sm mt-2 max-w-none">{!! str($description)->sanitizeHtml() !!}</div>
            </section>
        @endif

        @if ($hasManufacturingProcedure)
            <section class="mt-5 break-inside-avoid">
                <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.manufacturing_procedure') }}</h2>
                <div class="prose prose-sm mt-2 max-w-none">{!! str($manufacturingProcedure)->sanitizeHtml() !!}</div>
            </section>
        @endif

        @if (filled($formulaDocument['label_text']))
            <section class="mt-5 break-inside-avoid">
                <h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.sections.label') }}</h2>
                <p class="mt-2 text-sm leading-6">{{ $formulaDocument['label_text'] }}</p>
            </section>
        @endif

        <section class="mt-5 grid grid-cols-2 gap-4 break-inside-avoid">
            <div class="min-h-28 border border-slate-400 p-3"><h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.print.observations') }}</h2></div>
            <div class="min-h-28 border border-slate-400 p-3"><h2 class="text-xs font-semibold uppercase">{{ __('formula_documents.print.result') }}</h2></div>
        </section>
    </article>

    @if ($includeAnalysis && is_array($formulaDocument['soap_analysis']))
        <article class="document-sheet mx-auto max-w-5xl break-before-page bg-white p-6 text-slate-900 print:max-w-none print:p-0">
            <h1 class="text-xl font-semibold">{{ __('formula_documents.analysis.title') }}</h1>

            <section class="mt-5">
                <h2 class="text-xs font-semibold tracking-[0.08em] uppercase">{{ __('formula_documents.analysis.qualities') }}</h2>
                <table class="mt-2 w-full border-collapse text-xs">
                    <thead>
                        <tr>
                            <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.quality') }}</th>
                            <th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.value') }}</th>
                            <th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.suggested_range') }}</th>
                            <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($formulaDocument['soap_analysis']['qualities'] as $row)
                            <tr>
                                <th class="border border-slate-300 p-2 text-left font-medium">{{ $row['label'] }}</th>
                                <td class="numeric border border-slate-300 p-2 text-right">{{ number_format($row['value'], 2) }}</td>
                                <td class="numeric border border-slate-300 p-2 text-right">{{ $row['range'] }}</td>
                                <td class="border border-slate-300 p-2">{{ $row['status'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="mt-5">
                <h2 class="text-xs font-semibold tracking-[0.08em] uppercase">{{ __('formula_documents.analysis.fatty_acids') }}</h2>
                <table class="mt-2 w-full border-collapse text-xs">
                    <thead>
                        <tr>
                            <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.fatty_acid') }}</th>
                            <th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.percentage') }}</th>
                            <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.contribution') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($formulaDocument['soap_analysis']['fatty_acids'] as $row)
                            <tr>
                                <th class="border border-slate-300 p-2 text-left font-medium">{{ $row['label'] }}</th>
                                <td class="numeric border border-slate-300 p-2 text-right">{{ number_format($row['value'], 2) }}%</td>
                                <td class="border border-slate-300 p-2">{{ $row['contribution'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        </article>
    @endif
@endsection
