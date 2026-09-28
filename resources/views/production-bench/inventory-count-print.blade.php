@extends('layouts.print')

@section('title', __('production_bench.output.count_title').' · '.$workspace->name)
@section('print_document_class', 'formula-sheet-document')

@section('content')
    <div class="print-hidden mb-4 flex justify-end">
        <button type="button" data-print-document class="sk-btn sk-btn-primary">{{ __('production_bench.output.print') }}</button>
    </div>
    <article class="bg-white p-5 text-slate-900 print:p-0">
        <h1 class="text-2xl font-semibold">{{ __('production_bench.output.count_title') }}</h1>
        <p class="mt-2 text-sm">{{ $workspace->name }} · {{ $generatedAt }}</p>
        <dl class="my-4 flex flex-wrap gap-x-5 gap-y-1 text-xs">
            @foreach ($filterLabels as $label => $value)
                <div><dt class="inline font-semibold">{{ $label }}:</dt> <dd class="inline">{{ $value }}</dd></div>
            @endforeach
        </dl>
        <div data-formula-document-table>
            <table class="w-full table-fixed border-collapse text-left text-xs">
                <thead>
                    <tr>
                        @foreach (['material', 'lot', 'supplier_batch', 'unit'] as $key)
                            <th class="border border-slate-400 p-2">{{ __('production_bench.output.columns.'.$key) }}</th>
                        @endforeach
                        @if ($workspace->uses_storage_locations)
                            <th class="border border-slate-400 p-2">{{ __('production_bench.output.columns.location') }}</th>
                        @endif
                        <th class="border border-slate-400 p-2">{{ __('production_bench.output.recorded') }}</th>
                        <th class="border border-slate-400 p-2">{{ __('production_bench.output.counted') }}</th>
                        <th class="border border-slate-400 p-2">{{ __('production_bench.output.notes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach (['material', 'lot', 'supplier_batch', 'unit'] as $key)
                                <td class="break-words border border-slate-300 p-2">{{ $row[$key] }}@if ($key === 'material' && filled($row['code']))<span class="mt-1 block text-[10px] text-slate-600">{{ $row['code'] }}</span>@endif</td>
                            @endforeach
                            @if ($workspace->uses_storage_locations)
                                <td class="break-words border border-slate-300 p-2">{{ $row['location'] }}</td>
                            @endif
                            <td class="border border-slate-300 p-2">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['physical'], 0, 9, auth()->user()?->number_locale) }}</td>
                            <td data-counted class="h-12 border border-slate-300 p-2"></td>
                            <td data-count-notes class="h-12 border border-slate-300 p-2"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
@endsection
