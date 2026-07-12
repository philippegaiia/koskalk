@extends('layouts.print')

@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => $decimals === 0
        ? number_format((float) $value, 0, '.', '')
        : rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $formatMoney = static fn (mixed $value, string $currency): string => $formatNumber($value, 2).' '.$currency;
    $formatQuantity = static fn (mixed $value, string $unit): string => $formatNumber($value, $unit === 'g' ? 0 : 2).' '.$unit;
    $versionLabel = $productionBatch->recipe_version_number
        ? 'Version '.$productionBatch->recipe_version_number
        : 'Saved formula';
@endphp

@section('title', $productionBatch->recipe_name.' · Production Snapshot Print · '.config('app.name'))

@section('content')
    <div class="space-y-4 text-[13px] leading-5 text-slate-950">
        <div class="print-hidden flex flex-col gap-3 border border-slate-300 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Production snapshot</p>
                <p class="mt-1 text-sm text-slate-600">{{ $productionBatch->recipe_name }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('production-batches.show', $productionBatch) }}" class="inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 transition hover:bg-slate-50">
                    Back
                </a>
                <button type="button" onclick="window.print()" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                    Print
                </button>
            </div>
        </div>

        <article class="document-sheet border border-slate-300 bg-white p-6 print:border-0 print:p-0">
            <header class="border-b-2 border-slate-950 pb-3">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_17rem]">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Production snapshot</p>
                        <h1 class="mt-1 text-2xl font-semibold text-slate-950">{{ $productionBatch->recipe_name }}</h1>
                        <p class="mt-1 text-xs text-slate-600">{{ $versionLabel }}</p>
                    </div>

                    <dl class="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-2 gap-y-1 text-xs text-slate-700 sm:text-right">
                        <dt class="font-semibold text-slate-500 sm:text-left">Batch</dt>
                        <dd class="numeric">{{ $productionBatch->production_batch_number ?: 'No batch number' }}</dd>
                        <dt class="font-semibold text-slate-500 sm:text-left">Date made</dt>
                        <dd class="numeric">{{ $productionBatch->manufacture_date?->format('Y-m-d') ?? 'Not recorded' }}</dd>
                        <dt class="font-semibold text-slate-500 sm:text-left">Batch basis</dt>
                        <dd class="numeric">{{ $formatQuantity($productionBatch->batch_basis_value, $productionBatch->batch_basis_unit) }}</dd>
                        <dt class="font-semibold text-slate-500 sm:text-left">Units</dt>
                        <dd class="numeric">{{ $productionBatch->units_produced }}</dd>
                    </dl>
                </div>
            </header>

            <section class="mt-4">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Ingredients</h2>
                <table class="mt-2 w-full border-collapse text-sm">
                    <thead>
                        <tr class="border border-slate-300 bg-slate-100 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                            <th class="px-2 py-1.5">Ingredient</th>
                            <th class="px-2 py-1.5">Quantity</th>
                            <th class="px-2 py-1.5">Lot</th>
                            <th class="px-2 py-1.5">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productionBatch->ingredients as $ingredient)
                            <tr class="border border-slate-300">
                                <td class="px-2 py-1.5 font-medium">{{ $ingredient->ingredient_name }}@if ($ingredient->ingredient?->owner_type !== null)<span class="ml-1 text-slate-500" aria-label="User-created or user-modified ingredient">•</span>@endif</td>
                                <td class="numeric px-2 py-1.5">{{ $formatQuantity($ingredient->quantity, $ingredient->unit) }}</td>
                                <td class="px-2 py-1.5">{{ $ingredient->ingredient_lot_number ?: '' }}&nbsp;</td>
                                <td class="numeric px-2 py-1.5 font-medium">{{ $formatMoney($ingredient->line_cost, $productionBatch->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($productionBatch->ingredients->contains(fn ($row): bool => $row->ingredient?->owner_type !== null))
                    <p class="mt-2 text-[9px] leading-3 text-slate-500">• User-created or user-modified ingredient. Data has not been verified by Soapkraft.</p>
                @endif
            </section>

            <section class="mt-4">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Packaging</h2>
                @if ($productionBatch->packagingItems->isNotEmpty())
                    <table class="mt-2 w-full border-collapse text-sm">
                        <thead>
                            <tr class="border border-slate-300 bg-slate-100 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                <th class="px-2 py-1.5">Item</th>
                                <th class="px-2 py-1.5">Components/unit</th>
                                <th class="px-2 py-1.5">Batch cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($productionBatch->packagingItems as $packagingItem)
                                <tr class="border border-slate-300">
                                    <td class="px-2 py-1.5 font-medium">{{ $packagingItem->name }}</td>
                                    <td class="numeric px-2 py-1.5">{{ $formatNumber($packagingItem->components_per_unit, 3) }}</td>
                                    <td class="numeric px-2 py-1.5 font-medium">{{ $formatMoney($packagingItem->line_cost, $productionBatch->currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="mt-2 border border-slate-300 px-3 py-2 text-sm text-slate-700">
                        No packaging rows were recorded.
                    </div>
                @endif
            </section>

            <section class="mt-4 break-inside-avoid">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Cost summary</h2>
                <table class="mt-2 w-full border-collapse text-sm">
                    <tbody>
                        <tr class="border border-slate-300">
                            <th class="w-56 bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">Ingredient cost</th>
                            <td class="numeric px-2 py-1.5">{{ $formatMoney($productionBatch->ingredient_total, $productionBatch->currency) }}</td>
                        </tr>
                        <tr class="border border-slate-300">
                            <th class="bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">Packaging cost</th>
                            <td class="numeric px-2 py-1.5">{{ $formatMoney($productionBatch->packaging_total, $productionBatch->currency) }}</td>
                        </tr>
                        <tr class="border border-slate-300">
                            <th class="bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">Total production cost</th>
                            <td class="numeric px-2 py-1.5 font-medium">{{ $formatMoney($productionBatch->total_cost, $productionBatch->currency) }}</td>
                        </tr>
                        <tr class="border border-slate-300">
                            <th class="bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">Cost per unit</th>
                            <td class="numeric px-2 py-1.5 font-medium">{{ $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="mt-4 break-inside-avoid">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Production notes</h2>
                <div class="mt-2 min-h-[8rem] whitespace-pre-line border border-slate-300 px-3 py-2 text-sm leading-6">{{ $productionBatch->production_notes ?: '' }}</div>
            </section>
        </article>
    </div>
@endsection
