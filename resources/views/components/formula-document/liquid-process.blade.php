@props(['document', 'numberLocale' => null])

@php
    $process = $document['liquid_process'] ?? null;
    $mass = fn (float $value): string => $numberLocale === null
        ? number_format($value, 2)
        : \App\Support\NumberLocale::formatAdaptiveDecimal($value, 2, 4, $numberLocale);
    $percent = fn (float $value): string => $numberLocale === null
        ? number_format($value, 1)
        : \App\Support\NumberLocale::formatDecimal($value, 1, $numberLocale);
    $soapGained = is_array($process)
        && ($process['final_anhydrous_soap_weight'] ?? null) !== null
        && round($process['final_anhydrous_soap_weight'], 4) !== round((float) $process['anhydrous_soap_weight'], 4);
@endphp

@if (is_array($process))
    <section data-formula-document-liquid-process aria-labelledby="formula-document-liquid-process-heading" {{ $attributes->merge(['class' => 'space-y-3']) }}>
        <h2 id="formula-document-liquid-process-heading" class="text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase">
            {{ __('formula_documents.liquid.title') }}
        </h2>

        <div class="overflow-x-auto rounded-xl border border-[var(--color-line)] bg-white">
            <table class="min-w-full border-collapse text-sm">
                <thead class="bg-[var(--color-panel-strong)] text-left text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase print:table-header-group">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('formula_documents.liquid.columns.step') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('formula_documents.liquid.columns.add', ['unit' => $document['unit']]) }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('formula_documents.liquid.columns.total', ['unit' => $document['unit']]) }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('formula_documents.liquid.columns.anhydrous') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-line)]">
                    @foreach ($process['steps'] as $step)
                        <tr>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-[var(--color-ink-strong)]">{{ $step['label'] }}</p>
                                <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">{{ $step['instruction'] }}</p>
                                @if ($step['key'] === 'neutralization' && is_array($process['neutralizer']))
                                    <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                        {{ __('formula_documents.liquid.neutralizer_amounts', [
                                            'acid' => $mass($process['neutralizer']['weight']),
                                            'recommended' => $mass($process['neutralizer']['recommended_weight']),
                                            'unit' => $document['unit'],
                                        ]) }}
                                    </p>
                                @endif
                                @if ($step['key'] === 'paste' && $process['anhydrous_soap_weight'] !== null)
                                    <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                        {{ __('formula_documents.liquid.anhydrous_soap_weight', ['amount' => $mass($process['anhydrous_soap_weight']), 'unit' => $document['unit']]) }}
                                    </p>
                                @endif
                                @if ($step['key'] === 'neutralization' && $soapGained)
                                    <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                        {{ __('formula_documents.liquid.neutralized_anhydrous_soap_weight', ['amount' => $mass($process['final_anhydrous_soap_weight']), 'unit' => $document['unit']]) }}
                                    </p>
                                @endif
                                @if ($step['key'] === 'paste' && $process['paste']['is_weighed'])
                                    <p class="numeric mt-0.5 text-xs text-[var(--color-ink-soft)]">
                                        {{ __('formula_documents.liquid.weighed_paste', ['theoretical' => $mass($process['paste']['theoretical_weight']), 'unit' => $document['unit']]) }}
                                    </p>
                                @endif
                            </td>
                            <td class="numeric px-4 py-2.5 text-right align-top">{{ $step['add'] === null ? '—' : $mass($step['add']) }}</td>
                            <td class="numeric px-4 py-2.5 text-right align-top">{{ $mass($step['mass']) }}</td>
                            <td class="numeric px-4 py-2.5 text-right align-top">{{ $percent($step['anhydrous_percentage']) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($process['additions'] !== [])
            <div>
                <h3 class="text-xs font-semibold text-[var(--color-ink-strong)]">{{ __('formula_documents.liquid.additions') }}</h3>
                <ul class="mt-1 space-y-0.5 text-sm">
                    @foreach ($process['additions'] as $addition)
                        <li class="flex justify-between gap-4">
                            <span>{{ $addition['name'] }}</span>
                            <span class="numeric">{{ $mass($addition['weight']) }} {{ $document['unit'] }}@if ($addition['final_percentage'] !== null) · {{ \App\Support\NumberLocale::formatDecimal($addition['final_percentage'], 2, $numberLocale ?? 'en_US') }}%@endif</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($process['warnings'] as $warning)
            <p class="text-xs text-[var(--color-ink-soft)]">{{ $warning }}</p>
        @endforeach
    </section>
@endif
