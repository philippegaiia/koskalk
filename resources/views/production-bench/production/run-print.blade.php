@extends('layouts.print')

@section('title', __('production_bench.run_print.'.($document['record'] ? 'record' : 'sheet')).' · '.$document['identifier'])
@section('print_document_class', 'formula-sheet-document')

@section('content')
    @php
        $unavailable = __('production_bench.run_print.unavailable');
        $number = fn ($value) => $value === null ? '' : \App\Support\NumberLocale::formatAdaptiveDecimal($value, 0, 9, auth()->user()?->number_locale);
    @endphp
    <div class="print-hidden mb-4 flex justify-end">
        <button type="button" data-print-document class="sk-btn sk-btn-primary">{{ __('production_bench.output.print') }}</button>
    </div>
    <article class="space-y-6 bg-white p-5 text-slate-900 print:p-0">
        <header>
            <p class="text-sm">{{ $document['identifier'] }} · {{ __('production_bench.production.status.'.$document['status']) }}</p>
            <h1 class="mt-2 text-2xl font-semibold">{{ __('production_bench.run_print.'.($document['record'] ? 'record' : 'sheet')) }}</h1>
            <h2 class="mt-2 text-xl">{{ $document['formula'] ?? $unavailable }}</h2>
            <p class="text-sm">{{ __('production_bench.production.formula.source_version') }}: {{ $document['version'] ?? $unavailable }}</p>
            <p class="text-sm">{{ __('production_bench.production.basis') }}: {{ $number($document['basis_quantity_grams']) }} g · {{ in_array($document['calculation_basis'], ['initial_oils', 'total_formula'], true) ? __('production_bench.run_print.basis_'.$document['calculation_basis']) : $unavailable }}</p>
            <p class="text-xs">{{ __('production_bench.run_print.generated') }}: {{ $generatedAt }}</p>
            @if ($document['aborted'])
                <p class="my-3 border-2 border-red-700 p-3 text-xl font-bold text-red-800">{{ __('production_bench.production.status.aborted') }} — {{ $document['abort_reason'] }}</p>
            @endif
            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm">
                @foreach (['planned_for', 'started_at', 'manufacture_date', 'completed_at', 'aborted_at'] as $key)
                    @if ($document[$key] !== null)
                        <div><dt class="font-semibold">{{ __('production_bench.run_print.'.$key) }}</dt><dd>{{ $document[$key] }}</dd></div>
                    @endif
                @endforeach
                <div><dt class="font-semibold">{{ __('production_bench.settings.expected_units') }}</dt><dd>{{ $number($document['expected_units']) }}</dd></div>
                @if ($document['record'])
                    <div><dt class="font-semibold">{{ __('production_bench.run_print.output') }}</dt><dd>{{ $document['actual_output_mass_grams'] !== null ? $number($document['actual_output_mass_grams']).' g' : ($document['actual_output_units'] !== null ? $number($document['actual_output_units']).' '.__('production_bench.inventory.count') : $unavailable) }}</dd></div>
                @endif
            </dl>
        </header>
        <section data-formula-section>
            <h2 class="mb-2 text-lg font-semibold">{{ __('production_bench.run_print.formula') }}</h2>
            <div data-formula-document-table>
                <table class="w-full border-collapse text-left text-xs">
                    <thead><tr>
                        @foreach (['material', 'phase', 'percentage', 'planned', 'actual', 'unit', 'notes'] as $key)
                            <th class="border border-slate-400 p-2">{{ __('production_bench.run_print.'.$key) }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($document['materials'] as $row)
                            <tr>
                                <td class="border border-slate-300 p-2">{{ $row['name'] }}@if ($row['code'])<span class="block text-slate-600">{{ $row['code'] }}</span>@endif</td>
                                <td class="border border-slate-300 p-2">{{ $row['phase'] }}</td>
                                <td class="border border-slate-300 p-2">{{ $number($row['percentage']) }}</td>
                                <td class="border border-slate-300 p-2">{{ $number($row['planned']) }}</td>
                                <td data-actual class="h-10 border border-slate-300 p-2">{{ $number($row['actual']) }}</td>
                                <td class="border border-slate-300 p-2">{{ $row['unit'] }}</td>
                                <td class="border border-slate-300 p-2">{{ $row['note'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        @foreach (['reservations', 'consumption'] as $section)
            @if ($document[$section] !== [])
                <section data-formula-section>
                    <h2 class="mb-2 text-lg font-semibold">{{ __('production_bench.run_print.'.$section) }}</h2>
                    <div data-formula-document-table>
                        <table class="w-full border-collapse text-left text-xs">
                            <thead><tr>
                                @foreach (['material', 'lot', 'supplier_batch', 'expiry', 'quantity', 'notes'] as $key)
                                    <th class="border border-slate-400 p-2">{{ __('production_bench.run_print.'.$key) }}</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                @foreach ($document[$section] as $row)
                                    <tr>
                                        <td class="border border-slate-300 p-2">{{ $row['name'] }}</td>
                                        <td class="border border-slate-300 p-2">{{ $row['lot'] ?? __('production_bench.run_print.lot_id', ['id' => $row['lot_id'] ?? $unavailable]) }}@if (! $row['identity_available'])<span class="block">{{ $unavailable }}</span>@endif</td>
                                        <td class="border border-slate-300 p-2">{{ $row['supplier_batch'] ?? $unavailable }}</td>
                                        <td class="border border-slate-300 p-2">{{ $row['expiry'] ?? $unavailable }}</td>
                                        <td class="border border-slate-300 p-2">{{ $number($row['quantity']) }} {{ $row['unit'] }}</td>
                                        <td class="border border-slate-300 p-2">{{ $row['note'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endforeach
        <section data-formula-section>
            <h2 class="mb-2 text-lg font-semibold">{{ __('production_bench.run_print.procedure') }}</h2>
            @if (! $document['procedure_available'])
                <p>{{ $unavailable }}</p>
            @elseif (trim(strip_tags($document['procedure'])) === '')
                <p>{{ __('production_bench.run_print.no_procedure') }}</p>
            @else
                <div data-formula-procedure-content class="space-y-2 text-sm [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6">{!! $document['procedure'] !!}</div>
            @endif
            @if ($document['illustrations_omitted'])<p class="mt-2 text-xs">{{ __('production_bench.run_print.illustrations_omitted') }}</p>@endif
        </section>
        <section data-formula-section>
            <h2 class="mb-2 text-lg font-semibold">{{ __('production_bench.run_print.notes') }}</h2>
            <p class="min-h-16 whitespace-pre-wrap text-sm">{{ $document['notes'] }}</p>
        </section>
    </article>
@endsection
