@extends('layouts.print')
@section('title', __('production_bench.picking.title'))
@section('print_document_class', 'formula-sheet-document')
@section('content')
    <div class="print-hidden mb-4 flex justify-end"><button type="button" data-print-document class="sk-btn sk-btn-primary">{{ __('production_bench.output.print') }}</button></div>
    @foreach ($documents as $document)
        <section class="mb-8 bg-white p-5 text-slate-900 {{ $loop->first ? '' : 'print:break-before-page' }} print:p-0">
            <h1 class="text-2xl font-semibold">{{ __('production_bench.picking.title') }}</h1>
            <h2 class="my-2 text-xl">{{ $document['identifier'] }} · {{ $document['formula'] }}</h2>
            <p class="mb-2 text-xs">{{ __('production_bench.run_print.generated') }}: {{ $generatedAt }}</p>
            <p class="mb-4 text-sm">{{ __('production_bench.picking.help') }}</p>
            <div data-formula-document-table><table class="w-full border-collapse text-left text-xs">
                <thead><tr>
                    @foreach (['material', 'lot', 'supplier_batch', 'quantity'] as $key)<th class="border border-slate-400 p-2">{{ __('production_bench.run_print.'.$key) }}</th>@endforeach
                    @if ($showLocations)<th class="border border-slate-400 p-2">{{ __('production_bench.picking.location') }}</th>@endif
                    <th class="border border-slate-400 p-2">{{ __('production_bench.picking.allocation') }}</th>
                </tr></thead>
                <tbody>@foreach ($document['rows'] as $row)<tr>
                    <td class="border border-slate-300 p-2">{{ $row['material'] }} @if ($row['code'])<span class="block">{{ $row['code'] }}</span>@endif</td>
                    <td class="border border-slate-300 p-2">{{ $row['lot'] ?? '—' }}</td>
                    <td class="border border-slate-300 p-2">{{ $row['supplier_batch'] ?? '—' }}</td>
                    <td class="border border-slate-300 p-2">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['quantity'], 0, 9, auth()->user()?->number_locale) }} {{ $row['unit'] }}</td>
                    @if ($showLocations)<td class="border border-slate-300 p-2">{{ $row['location'] ?? '—' }}</td>@endif
                    <td class="border border-slate-300 p-2">{{ __('production_bench.picking.'.$row['status']) }}</td>
                </tr>@endforeach</tbody>
            </table></div>
        </section>
    @endforeach
@endsection
