@extends('layouts.print')

@section('title', __('production_bench.run_print.'.($document['record'] ? 'record' : 'sheet')).' · '.$document['identifier'])
@section('print_document_class', 'formula-sheet-document')

@section('content')
    @php
        $unavailable = __('production_bench.run_print.unavailable');
        $numberLocale = auth()->user()?->number_locale;
        $number = fn (mixed $value, int $min = 2, int $max = 4): string => $value === null ? '' : \App\Support\NumberLocale::formatAdaptiveDecimal($value, $min, $max, $numberLocale);
    @endphp
    <div class="print-hidden mb-4 flex justify-end">
        <button type="button" data-print-document class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap"><x-action-icon name="printer" />{{ __('production_bench.output.print') }}</button>
    </div>
    <article class="document-sheet mx-auto max-w-5xl space-y-5 bg-white p-6 text-slate-900 print:mx-0 print:max-w-none print:p-0">
        <header class="border-b border-slate-400 pb-4">
            <p class="text-xs font-semibold uppercase text-slate-600">{{ __('production_bench.run_print.'.($document['record'] ? 'record' : 'sheet')) }} · {{ __('production_bench.production.status.'.$document['status']) }}</p>
            <div class="mt-2 flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <h1 class="text-2xl font-semibold">{{ $document['formula'] ?? $unavailable }}</h1>
                <p class="numeric text-xl font-semibold">{{ $document['identifier'] }}</p>
            </div>
            @if ($document['aborted'])
                <p class="my-3 border-2 border-red-700 p-3 text-xl font-bold text-red-800">{{ __('production_bench.production.status.aborted') }}: {{ $document['abort_reason'] }}</p>
            @endif
            <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-2 text-xs sm:grid-cols-4">
                <div><dt class="font-semibold text-slate-600">{{ in_array($document['calculation_basis'], ['initial_oils', 'total_formula'], true) ? __('production_bench.run_print.basis_'.$document['calculation_basis']) : __('production_bench.production.basis') }}</dt><dd class="numeric mt-0.5">{{ $number($document['basis_quantity_grams']) }} g</dd></div>
                <div><dt class="font-semibold text-slate-600">{{ __('production_bench.settings.expected_units') }}</dt><dd class="numeric mt-0.5">{{ $number($document['expected_units'], 0, 0) }}</dd></div>
                @foreach (['planned_for', 'started_at', 'manufacture_date', 'completed_at', 'aborted_at'] as $key)
                    @if ($document[$key] !== null)
                        <div><dt class="font-semibold text-slate-600">{{ __('production_bench.run_print.'.$key) }}</dt><dd class="mt-0.5">{{ $document[$key] }}</dd></div>
                    @endif
                @endforeach
                @if ($document['batch_number'] && $document['planning_reference'])
                    <div><dt class="font-semibold text-slate-600">{{ __('production_bench.production.planning_reference') }}</dt><dd class="numeric mt-0.5">{{ $document['planning_reference'] }}</dd></div>
                @endif
                @if ($document['record'])
                    <div><dt class="font-semibold text-slate-600">{{ __('production_bench.run_print.output') }}</dt><dd class="numeric mt-0.5">{{ $document['actual_output_mass_grams'] !== null ? $number($document['actual_output_mass_grams']).' g' : ($document['actual_output_units'] !== null ? $number($document['actual_output_units'], 0, 0).' '.__('production_bench.inventory.units') : $unavailable) }}</dd></div>
                @endif
                <div><dt class="font-semibold text-slate-600">{{ __('production_bench.run_print.generated') }}</dt><dd class="mt-0.5">{{ $generatedAt }}</dd></div>
            </dl>
        </header>
        @foreach (['formula', 'packaging'] as $tableKind)
            @php
                $isPackaging = $tableKind === 'packaging';
                $groups = $isPackaging ? [['key' => 'packaging', 'label' => '', 'rows' => $document['packaging']]] : $document['sections'];
                $hasRows = $isPackaging ? $document['packaging'] !== [] : $document['materials'] !== [];
                $unit = $isPackaging ? __('production_bench.inventory.units') : 'g';
                $minimumDecimals = $isPackaging ? 0 : 2;
                $maximumDecimals = $isPackaging ? 0 : 4;
            @endphp
            @if ($hasRows)
                <section data-formula-section="{{ $tableKind }}" data-batch-material-section="{{ $tableKind }}">
                    <h2 class="mb-2 text-sm font-semibold uppercase">{{ $isPackaging ? __('production_bench.production.packaging_requirement') : __('production_bench.run_print.formula') }}</h2>
                    <div data-formula-document-table>
                        <table class="w-full border-collapse text-left text-[11px] leading-4">
                            <thead><tr class="border-b border-slate-400 text-slate-600">
                                <th scope="col" class="px-1.5 py-1">{{ __('production_bench.run_print.material') }}</th>
                                @if (! $isPackaging)<th scope="col" class="px-1.5 py-1 text-right">{{ $document['calculation_basis'] === 'initial_oils' ? __('formula_documents.columns.percentage_oils') : __('formula_documents.columns.percentage_formula') }}</th>@endif
                                <th scope="col" class="px-1.5 py-1 text-right">{{ __('production_bench.run_print.planned') }} ({{ $unit }})</th>
                                <th scope="col" class="px-1.5 py-1">{{ __('production_bench.run_print.'.($document['record'] ? 'consumption' : 'reservations')) }}</th>
                                @if (! $isPackaging)<th scope="col" class="px-1.5 py-1 text-right">{{ __('production_bench.run_print.actual') }} ({{ $unit }})</th>@endif
                                <th scope="col" class="px-1.5 py-1">{{ __('production_bench.run_print.notes') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($groups as $group)
                                    @if (! $isPackaging)
                                        <tr data-batch-material-group="{{ $group['key'] }}" class="break-after-avoid border-b border-slate-300">
                                            <th scope="rowgroup" colspan="6" class="px-1.5 py-2 text-left font-semibold uppercase">{{ $group['label'] }}</th>
                                        </tr>
                                    @endif
                                    @foreach ($group['rows'] as $row)
                                        <tr data-batch-material-row class="break-inside-avoid border-b border-slate-200 align-top">
                                            <td class="px-1.5 py-2 font-medium">{{ $row['name'] }}@if ($row['code'])<span class="block text-slate-600">{{ $row['code'] }}</span>@endif</td>
                                            @if (! $isPackaging)<td data-batch-percentage class="numeric whitespace-nowrap px-1.5 py-2 text-right">{{ $row['percentage'] === null ? '' : $number($row['percentage'], 2, 2).'%' }}</td>@endif
                                            <td data-batch-planned class="numeric whitespace-nowrap px-1.5 py-2 text-right">{{ $number($row['planned'], $minimumDecimals, $maximumDecimals) }}</td>
                                            <td data-batch-lots class="px-1.5 py-2">
                                                @forelse ($row['lots'] as $lot)
                                                    <div class="mb-1 flex flex-wrap items-baseline gap-x-3 gap-y-0.5 last:mb-0">
                                                        <span data-internal-lot class="font-semibold">{{ __('production_bench.run_print.internal') }}: {{ $lot['lot'] ?? $unavailable }}</span>
                                                        <span data-supplier-batch>{{ __('production_bench.run_print.supplier') }}: {{ $lot['supplier_batch'] ?? $unavailable }}</span>
                                                        <span class="numeric">{{ $number($lot['quantity'], $minimumDecimals, $maximumDecimals) }} {{ $unit }}</span>
                                                        @if ($lot['note'])<p class="w-full">{{ $lot['note'] }}</p>@endif
                                                    </div>
                                                @empty
                                                    <span class="text-slate-600">—</span>
                                                @endforelse
                                            </td>
                                            @if (! $isPackaging)<td data-actual class="numeric h-10 whitespace-nowrap px-1.5 py-2 text-right">{{ $number($row['actual'], $minimumDecimals, $maximumDecimals) }}</td>@endif
                                            <td class="px-1.5 py-2">{{ $row['note'] }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endforeach
        @if ($document['liquid_process'] !== null)
            <x-formula-document.liquid-process data-formula-section :document="['unit' => 'g', 'liquid_process' => $document['liquid_process']]" :number-locale="$numberLocale" />
        @endif
        @if ($document['unmatched_consumption'] !== [])
            <section data-formula-section>
                <h2 class="mb-2 text-sm font-semibold uppercase">{{ __('production_bench.run_print.consumption') }}</h2>
                <div data-formula-document-table>
                    <table class="w-full border-collapse text-left text-[11px] leading-4">
                        <thead><tr class="border-b border-slate-400 text-slate-600">
                            @foreach (['material', 'lot', 'supplier_batch', 'quantity', 'notes'] as $key)
                                <th class="px-1.5 py-1">{{ __('production_bench.run_print.'.$key) }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody>
                            @foreach ($document['unmatched_consumption'] as $row)
                                <tr class="break-inside-avoid border-b border-slate-200 align-top">
                                    <td class="px-1.5 py-2">{{ $row['name'] }}</td>
                                    <td class="px-1.5 py-2 font-semibold">{{ $row['lot'] ?? $unavailable }}</td>
                                    <td class="px-1.5 py-2">{{ $row['supplier_batch'] ?? $unavailable }}</td>
                                    <td class="px-1.5 py-2">{{ $number($row['quantity'], $row['unit'] === 'unit' ? 0 : 2, $row['unit'] === 'unit' ? 0 : 4) }} {{ $row['unit'] }}</td>
                                    <td class="px-1.5 py-2">{{ $row['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
        <section data-formula-section>
            <h2 class="mb-2 text-sm font-semibold uppercase">{{ __('production_bench.run_print.procedure') }}</h2>
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
            <h2 class="mb-2 text-sm font-semibold uppercase">{{ __('production_bench.run_print.notes') }}</h2>
            <p class="min-h-16 whitespace-pre-wrap text-sm">{{ $document['notes'] }}</p>
        </section>
    </article>
@endsection
