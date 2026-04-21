@extends('layouts.print')

@php
    $isProductionMode = $printMode === 'production' || $printMode === 'recipe';
    $isTechnicalMode = $printMode === 'technical' || $printMode === 'details';
    $isCostingMode = $printMode === 'costing';
    $modeTitle = match (true) {
        $isCostingMode => 'Costing sheet',
        $isTechnicalMode => 'Technical recipe sheet',
        default => 'Batch production sheet',
    };
    $modeDescription = match (true) {
        $isCostingMode => 'Costs used for the current official recipe.',
        $isTechnicalMode => 'Formula, labeling, and declaration details for review.',
        default => 'Working document for making the batch.',
    };
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $formatSummaryNumber = static fn (mixed $value, mixed $unit): string => $unit === 'g'
        ? number_format((float) $value, 0, '.', '')
        : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $formatMoney = static fn (mixed $value, string $currency): string => $value === null
        ? 'Not set'
        : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').' '.$currency;
    $oilUnit = $snapshot['draft']['oilUnit'] ?? 'g';
    $printQuery = [
        'recipe' => $recipe->id,
        'oil_weight' => $selectedOilWeight,
    ];

    foreach (['batch_number', 'batch_basis', 'manufacture_date', 'units_produced'] as $batchQueryKey) {
        if (filled($batchContext[$batchQueryKey] ?? null)) {
            $printQuery[$batchQueryKey] = $batchContext[$batchQueryKey];
        }
    }
@endphp

@section('title', $recipe->name.' · '.$modeTitle.' · '.config('app.name'))

@section('content')
    <div class="space-y-4 text-[13px] leading-5 text-slate-950">
        <div class="print-hidden flex flex-col gap-3 border border-slate-300 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $modeTitle }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $modeDescription }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('recipes.saved', $printQuery) }}" class="inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 transition hover:bg-slate-50">
                    Back
                </a>
                <a href="{{ route('recipes.print.production', $printQuery) }}" class="inline-flex rounded-lg border px-4 py-2 text-sm font-medium transition {{ $isProductionMode ? 'border-slate-900 text-slate-950' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">
                    Batch production sheet
                </a>
                <a href="{{ route('recipes.print.technical', $printQuery) }}" class="inline-flex rounded-lg border px-4 py-2 text-sm font-medium transition {{ $isTechnicalMode ? 'border-slate-900 text-slate-950' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">
                    Technical recipe sheet
                </a>
                <a href="{{ route('recipes.print.costing', $printQuery) }}" class="inline-flex rounded-lg border px-4 py-2 text-sm font-medium transition {{ $isCostingMode ? 'border-slate-900 text-slate-950' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">
                    Costing sheet
                </a>
                <button type="button" onclick="window.print()" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                    Print
                </button>
            </div>
        </div>

        <article class="document-sheet border border-slate-300 bg-white p-6 print:border-0 print:p-0">
            <header class="border-b-2 border-slate-950 pb-3">
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_16rem]">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $modeTitle }}</p>
                        <h1 class="mt-1 text-2xl font-semibold tracking-[-0.02em] text-slate-950">{{ $recipe->name }}</h1>
                        <p class="mt-1 text-xs text-slate-600">Official saved recipe</p>
                    </div>
                    <dl class="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-2 gap-y-1 text-xs text-slate-700 sm:text-right">
                        <dt class="font-semibold text-slate-500 sm:text-left">Saved</dt>
                        <dd class="numeric">{{ $version->saved_at?->format('Y-m-d H:i') ?? 'Not recorded' }}</dd>
                        <dt class="font-semibold text-slate-500 sm:text-left">Printed</dt>
                        <dd class="numeric">{{ now()->format('Y-m-d H:i') }}</dd>
                        <dt class="font-semibold text-slate-500 sm:text-left">Basis</dt>
                        <dd class="numeric">{{ $formatNumber($selectedOilWeight, 2) }} {{ $oilUnit }}</dd>
                        @if (filled($batchContext['batch_number'] ?? null))
                            <dt class="font-semibold text-slate-500 sm:text-left">Batch</dt>
                            <dd class="numeric">{{ $batchContext['batch_number'] }}</dd>
                        @endif
                        @if (filled($batchContext['units_produced'] ?? null))
                            <dt class="font-semibold text-slate-500 sm:text-left">Units</dt>
                            <dd class="numeric">{{ $batchContext['units_produced'] }}</dd>
                        @endif
                    </dl>
                </div>
            </header>

            @if ($isProductionMode)
                <section class="mt-4">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Batch record</h2>
                    <table class="mt-2 w-full border-collapse text-sm">
                        <tbody>
                            <tr class="border border-slate-300">
                                <th class="w-32 bg-slate-100 px-2 py-2 text-left font-semibold">Batch no.</th>
                                <td class="px-2 py-2">{{ $batchContext['batch_number'] ?: '' }}&nbsp;</td>
                                <th class="w-32 bg-slate-100 px-2 py-2 text-left font-semibold">Date made</th>
                                <td class="px-2 py-2">{{ $batchContext['manufacture_date'] ?: '' }}&nbsp;</td>
                            </tr>
                            <tr class="border border-slate-300">
                                <th class="bg-slate-100 px-2 py-2 text-left font-semibold">Units produced</th>
                                <td class="numeric px-2 py-2">{{ $batchContext['units_produced'] ?: '' }}&nbsp;</td>
                                <th class="bg-slate-100 px-2 py-2 text-left font-semibold">Batch basis</th>
                                <td class="numeric px-2 py-2">{{ $batchContext['batch_basis'] ?: $formatNumber($selectedOilWeight, 2) }} {{ $oilUnit }}</td>
                            </tr>
                            <tr class="border border-slate-300">
                                <th class="bg-slate-100 px-2 py-2 text-left font-semibold">Made by</th>
                                <td class="px-2 py-2">&nbsp;</td>
                                <th class="bg-slate-100 px-2 py-2 text-left font-semibold">Checked by</th>
                                <td class="px-2 py-2">&nbsp;</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            @endif

            @if (! $isCostingMode)
                <section class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Formula summary</h2>
                        <table class="mt-2 w-full border-collapse text-sm">
                            <tbody>
                                @foreach ($summaryCards as $card)
                                    <tr class="border border-slate-300">
                                        <th class="w-48 bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">{{ $card['label'] }}</th>
                                        <td class="numeric px-2 py-1.5 font-medium">
                                            {{ $formatSummaryNumber($card['value'], $card['unit']) }}@if ($card['unit']) {{ $card['unit'] }}@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Formula context</h2>
                        <table class="mt-2 w-full border-collapse text-sm">
                            <tbody>
                                @foreach ($contextRows as $row)
                                    <tr class="border border-slate-300">
                                        <th class="w-48 bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">{{ $row['label'] }}</th>
                                        <td class="px-2 py-1.5">{{ $row['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                @if ($lyeRows !== [])
                    <section class="mt-4">
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Lye and water</h2>
                        <table class="mt-2 w-full border-collapse text-sm">
                            <tbody>
                                @foreach ($lyeRows as $row)
                                    <tr class="border border-slate-300">
                                        <th class="w-48 bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">{{ $row['label'] }}</th>
                                        <td class="numeric px-2 py-1.5">
                                            @if (is_numeric($row['value']))
                                                {{ $formatNumber($row['value']) }}@if ($row['unit']) {{ $row['unit'] }}@endif
                                            @else
                                                {{ $row['value'] }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif

                <section class="mt-4 space-y-4">
                    @foreach ($phaseSections as $section)
                        <div class="break-inside-avoid">
                            <div class="flex items-end justify-between gap-3 border-b border-slate-500 pb-1">
                                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{{ $section['label'] }}</h2>
                                <p class="numeric text-xs text-slate-600">{{ $formatNumber($section['total_percentage']) }}% {{ $section['basis_label'] ?? 'oils' }} · {{ $formatNumber($section['total_weight']) }} {{ $oilUnit }}</p>
                            </div>

                            <table class="mt-2 w-full border-collapse text-sm">
                                <thead>
                                    <tr class="border border-slate-300 bg-slate-100 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                        <th class="px-2 py-1.5">Ingredient</th>
                                        @if ($isTechnicalMode)
                                            <th class="px-2 py-1.5">INCI</th>
                                        @endif
                                        <th class="px-2 py-1.5">%</th>
                                        <th class="px-2 py-1.5">Weight</th>
                                        <th class="px-2 py-1.5">Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['rows'] as $row)
                                        <tr class="border border-slate-300">
                                            <td class="px-2 py-1.5 font-medium">{{ $row['name'] }}</td>
                                            @if ($isTechnicalMode)
                                                <td class="px-2 py-1.5 text-slate-700">{{ $row['inci_name'] ?: 'Not recorded' }}</td>
                                            @endif
                                            <td class="numeric px-2 py-1.5">{{ $formatNumber($row['percentage']) }}%</td>
                                            <td class="numeric px-2 py-1.5 font-medium">{{ $formatNumber($row['weight']) }} {{ $oilUnit }}</td>
                                            <td class="px-2 py-1.5 text-slate-700">{{ $row['note'] ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </section>

                @if ($recipe->manufacturing_instructions)
                    <section class="mt-4 break-inside-avoid">
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Manufacturing instructions</h2>
                        <div class="prose prose-sm prose-slate mt-2 max-w-none border border-slate-300 px-3 py-2">
                            {!! str($recipe->manufacturing_instructions)->sanitizeHtml() !!}
                        </div>
                    </section>
                @endif
            @endif

            @if ($isTechnicalMode)
                <section class="mt-4 break-inside-avoid">
                    @php($listVariants = $snapshot['labeling']['list_variants'] ?? [])
                    <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Ingredient list preview</h2>
                    @if ($listVariants !== [])
                        <div class="mt-2 grid gap-3 lg:grid-cols-2">
                            @foreach ($listVariants as $variant)
                                <div class="border border-slate-300 px-3 py-2">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $variant['label'] }}</p>
                                    <p class="mt-1 text-sm leading-6 font-medium text-slate-950">{{ $variant['final_label_text'] ?: 'No generated ingredient list yet.' }}</p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-2 border border-slate-300 px-3 py-2 text-sm leading-6 font-medium text-slate-950">
                            {{ $snapshot['labeling']['final_label_text'] ?? 'No generated ingredient list yet.' }}
                        </div>
                    @endif

                    @if (($snapshot['labeling']['warnings'] ?? []) !== [])
                        <div class="mt-2 space-y-1">
                            @foreach ($snapshot['labeling']['warnings'] as $warning)
                                <div class="border border-slate-300 px-3 py-1.5 text-sm text-slate-800">{{ $warning }}</div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="mt-4">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Declaration details</h2>
                    @if (($snapshot['labeling']['declaration_rows'] ?? []) !== [])
                        <table class="mt-2 w-full border-collapse text-xs">
                            <thead>
                                <tr class="border border-slate-300 bg-slate-100 text-left font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    <th class="px-2 py-1.5">Label</th>
                                    <th class="px-2 py-1.5">Sources</th>
                                    <th class="px-2 py-1.5">% formula</th>
                                    <th class="px-2 py-1.5">Threshold</th>
                                    <th class="px-2 py-1.5">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($snapshot['labeling']['declaration_rows'] as $row)
                                    <tr class="border border-slate-300">
                                        <td class="px-2 py-1.5 font-medium">{{ $row['label'] }}</td>
                                        <td class="px-2 py-1.5">{{ implode(', ', $row['source_ingredients']) }}</td>
                                        <td class="numeric px-2 py-1.5">{{ $formatNumber($row['percent_of_formula'], 4) }}%</td>
                                        <td class="numeric px-2 py-1.5">{{ $formatNumber($row['threshold_percent'], 3) }}%</td>
                                        <td class="px-2 py-1.5">{{ $row['status_label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <div class="mt-2 border border-slate-300 px-3 py-2 text-sm text-slate-700">
                            No declaration rows are available for this saved recipe yet.
                        </div>
                    @endif
                </section>
            @endif

            @if ($isCostingMode)
                @if ($hasCostingData)
                    <section class="mt-4">
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Cost summary</h2>
                        <table class="mt-2 w-full border-collapse text-sm">
                            <tbody>
                                @foreach ($costingSummary as $row)
                                    <tr class="border border-slate-300">
                                        <th class="w-48 bg-slate-100 px-2 py-1.5 text-left font-semibold text-slate-700">{{ $row['label'] }}</th>
                                        <td class="numeric px-2 py-1.5 font-medium">{{ $row['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>

                    <section class="mt-4">
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Ingredient costs</h2>
                        <table class="mt-2 w-full border-collapse text-sm">
                            <thead>
                                <tr class="border border-slate-300 bg-slate-100 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                    <th class="px-2 py-1.5">Phase</th>
                                    <th class="px-2 py-1.5">Ingredient</th>
                                    <th class="px-2 py-1.5">Weight</th>
                                    <th class="px-2 py-1.5">Price/kg</th>
                                    <th class="px-2 py-1.5">Cost</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($costingIngredientRows as $row)
                                    <tr class="border border-slate-300">
                                        <td class="px-2 py-1.5">{{ $row['phase'] }}</td>
                                        <td class="px-2 py-1.5 font-medium">{{ $row['name'] }}</td>
                                        <td class="numeric px-2 py-1.5">{{ $formatNumber($row['weight']) }} {{ $oilUnit }}</td>
                                        <td class="numeric px-2 py-1.5">{{ $formatMoney($row['price_per_kg'], $costingCurrency) }}</td>
                                        <td class="numeric px-2 py-1.5 font-medium">{{ $formatMoney($row['line_cost'], $costingCurrency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>

                    <section class="mt-4">
                        <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Packaging costs</h2>
                        @if ($costingPackagingRows !== [])
                            <table class="mt-2 w-full border-collapse text-sm">
                                <thead>
                                    <tr class="border border-slate-300 bg-slate-100 text-left text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                        <th class="px-2 py-1.5">Packaging item</th>
                                        <th class="px-2 py-1.5">Unit cost</th>
                                        <th class="px-2 py-1.5">Components/unit</th>
                                        <th class="px-2 py-1.5">Cost/unit</th>
                                        <th class="px-2 py-1.5">Batch cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($costingPackagingRows as $row)
                                        <tr class="border border-slate-300">
                                            <td class="px-2 py-1.5 font-medium">{{ $row['name'] }}</td>
                                            <td class="numeric px-2 py-1.5">{{ $formatMoney($row['unit_cost'], $costingCurrency) }}</td>
                                            <td class="numeric px-2 py-1.5">{{ $formatNumber($row['quantity']) }}</td>
                                            <td class="numeric px-2 py-1.5">{{ $formatMoney($row['cost_per_finished_unit'], $costingCurrency) }}</td>
                                            <td class="numeric px-2 py-1.5 font-medium">{{ $formatMoney($row['line_cost'], $costingCurrency) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="mt-2 border border-slate-300 px-3 py-2 text-sm text-slate-700">
                                No packaging costs are saved for this recipe.
                            </div>
                        @endif
                    </section>
                @else
                    <section class="mt-4 border border-slate-300 px-3 py-2 text-sm text-slate-700">
                        No costing is saved for this official recipe yet.
                    </section>
                @endif
            @endif
        </article>
    </div>
@endsection
