@extends('layouts.print')

@php
    $isDetailsMode = $printMode === 'details';
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $oilUnit = $snapshot['draft']['oilUnit'] ?? 'g';
@endphp

@section('title', $recipe->name.' · Saved Formula · '.($isDetailsMode ? 'Full Details' : 'Recipe Print'))

@section('content')
    <div class="space-y-6 text-[15px] leading-6 text-slate-900">
        <div class="print-hidden flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $isDetailsMode ? 'Full recipe details' : 'Recipe print' }}</p>
                <p class="mt-1 text-sm text-slate-600">Compact document view for printing. This is separate from the app UI on purpose.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('recipes.saved', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 transition hover:bg-slate-50">
                    Back to saved formula
                </a>
                <a href="{{ route('recipes.print.recipe', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full border px-4 py-2 text-sm font-medium transition {{ $isDetailsMode ? 'border-slate-300 text-slate-600 hover:bg-slate-50' : 'border-slate-800 text-slate-900' }}">
                    Recipe
                </a>
                <a href="{{ route('recipes.print.details', ['recipe' => $recipe->id, 'oil_weight' => $selectedOilWeight]) }}" class="inline-flex rounded-full border px-4 py-2 text-sm font-medium transition {{ $isDetailsMode ? 'border-slate-800 text-slate-900' : 'border-slate-300 text-slate-600 hover:bg-slate-50' }}">
                    Full details
                </a>
                <button type="button" onclick="window.print()" class="inline-flex rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
                    Print
                </button>
            </div>
        </div>

        <article class="rounded-xl border border-slate-300 bg-white p-8 shadow-sm print:shadow-none">
            <header class="border-b border-slate-300 pb-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $isDetailsMode ? 'Full recipe details' : 'Recipe sheet' }}</p>
                        <h1 class="mt-2 text-3xl font-semibold tracking-[-0.03em] text-slate-950">{{ $recipe->name }}</h1>
                        <p class="mt-2 text-sm text-slate-600">Current saved formula</p>
                    </div>
                    <div class="text-sm text-slate-600 sm:text-right">
                        <div>Saved {{ $version->saved_at?->format('Y-m-d H:i') ?? 'not recorded yet' }}</div>
                        <div>Oil basis {{ $formatNumber($selectedOilWeight, 2) }} {{ $oilUnit }}</div>
                    </div>
                </div>
            </header>

            <section class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,0.85fr)]">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Formula summary</h2>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($summaryCards as $card)
                            <div class="border border-slate-200 px-4 py-3">
                                <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $card['label'] }}</div>
                                <div class="mt-1 text-lg font-semibold text-slate-950">{{ $formatNumber($card['value']) }} {{ $card['unit'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Formula context</h2>
                    <dl class="mt-3 divide-y divide-slate-200 border border-slate-200">
                        @foreach ($contextRows as $row)
                            <div class="grid grid-cols-[10rem_minmax(0,1fr)] gap-3 px-4 py-2.5 text-sm">
                                <dt class="font-medium text-slate-500">{{ $row['label'] }}</dt>
                                <dd class="text-slate-900">{{ $row['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>

            @if ($lyeRows !== [])
                <section class="mt-6">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Lye and water sheet</h2>
                    <div class="mt-3 overflow-hidden border border-slate-200">
                        <table class="min-w-full text-sm">
                            <tbody class="divide-y divide-slate-200">
                                @foreach ($lyeRows as $row)
                                    <tr>
                                        <td class="w-64 bg-slate-50 px-4 py-2.5 font-medium text-slate-600">{{ $row['label'] }}</td>
                                        <td class="px-4 py-2.5 text-slate-900">
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
                    </div>
                </section>
            @endif

            <section class="mt-6 space-y-5">
                @foreach ($phaseSections as $section)
                    <div>
                        <div class="flex flex-col gap-2 border-b border-slate-300 pb-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $section['label'] }}</h2>
                                <p class="mt-1 text-xs text-slate-500">Locked percentages from the current saved formula, recalculated on the selected oil basis.</p>
                            </div>
                            <div class="text-xs text-slate-500">
                                {{ $formatNumber($section['total_percentage']) }}% oils · {{ $formatNumber($section['total_weight']) }} {{ $oilUnit }}
                            </div>
                        </div>

                        <div class="mt-3 overflow-hidden border border-slate-200">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2.5">Ingredient</th>
                                        <th class="px-4 py-2.5">INCI</th>
                                        <th class="px-4 py-2.5">%</th>
                                        <th class="px-4 py-2.5">Weight</th>
                                        <th class="px-4 py-2.5">Note</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($section['rows'] as $row)
                                        <tr>
                                            <td class="px-4 py-2.5 font-medium text-slate-950">{{ $row['name'] }}</td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ $row['inci_name'] ?: 'Not recorded yet' }}</td>
                                            <td class="px-4 py-2.5 text-slate-900">{{ $formatNumber($row['percentage']) }}%</td>
                                            <td class="px-4 py-2.5 text-slate-900">{{ $formatNumber($row['weight']) }} {{ $oilUnit }}</td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ $row['note'] ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </section>

            @if ($recipe->description)
                <section class="mt-6">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Presentation</h2>
                    <div class="prose prose-sm prose-slate mt-3 max-w-none">
                        {!! str($recipe->description)->sanitizeHtml() !!}
                    </div>
                </section>
            @endif

            @if ($recipe->manufacturing_instructions)
                <section class="mt-6">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Manufacturing instructions</h2>
                    <div class="prose prose-sm prose-slate mt-3 max-w-none">
                        {!! str($recipe->manufacturing_instructions)->sanitizeHtml() !!}
                    </div>
                </section>
            @endif

            @if ($isDetailsMode)
                <section class="mt-6 border-t border-slate-300 pt-6">
                    @php($listVariants = $snapshot['labeling']['list_variants'] ?? [])
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Ingredient list preview</h2>
                    @if ($listVariants !== [])
                        <div class="mt-3 grid gap-3 lg:grid-cols-2">
                            @foreach ($listVariants as $variant)
                                <div class="border border-slate-200 px-4 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $variant['label'] }}</p>
                                    @if (filled($variant['note'] ?? null))
                                        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $variant['note'] }}</p>
                                    @endif
                                    <p class="mt-3 text-[0.95rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-slate-900">
                                        {{ $variant['final_label_text'] ?: 'No generated ingredient list yet.' }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-3 border border-slate-200 px-4 py-3 text-[0.95rem] leading-8 font-medium tracking-[0.01em] [font-stretch:88%] text-slate-900">
                            {{ $snapshot['labeling']['final_label_text'] ?? 'No generated ingredient list yet.' }}
                        </div>
                    @endif

                    @if (($snapshot['labeling']['warnings'] ?? []) !== [])
                        <div class="mt-3 space-y-2">
                            @foreach ($snapshot['labeling']['warnings'] as $warning)
                                <div class="border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm text-amber-900">
                                    {{ $warning }}
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="mt-6">
                    <h2 class="text-sm font-semibold uppercase tracking-[0.16em] text-slate-500">Declaration details</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Threshold statuses below correspond to the default soap-style list.</p>
                    @if (($snapshot['labeling']['declaration_rows'] ?? []) !== [])
                        <div class="mt-3 overflow-hidden border border-slate-200">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                                    <tr>
                                        <th class="px-4 py-2.5">Label</th>
                                        <th class="px-4 py-2.5">Sources</th>
                                        <th class="px-4 py-2.5">% formula</th>
                                        <th class="px-4 py-2.5">Threshold</th>
                                        <th class="px-4 py-2.5">Status</th>
                                        <th class="px-4 py-2.5">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($snapshot['labeling']['declaration_rows'] as $row)
                                        <tr>
                                            <td class="px-4 py-2.5 font-medium text-slate-950">{{ $row['label'] }}</td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ implode(', ', $row['source_ingredients']) }}</td>
                                            <td class="px-4 py-2.5 text-slate-900">{{ $formatNumber($row['percent_of_formula'], 4) }}%</td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ $formatNumber($row['threshold_percent'], 3) }}%</td>
                                            <td class="px-4 py-2.5 text-slate-900">{{ $row['status_label'] }}</td>
                                            <td class="px-4 py-2.5 text-slate-600">{{ $row['notes'] ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="mt-3 border border-slate-200 px-4 py-3 text-sm text-slate-600">
                            No declaration rows are available for this saved formula yet.
                        </div>
                    @endif
                </section>
            @endif
        </article>
    </div>
@endsection
