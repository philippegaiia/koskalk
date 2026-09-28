@props(['composition', 'numberLocale', 'isSelected' => true])

@php
    $incorporated = $composition['incorporated'] ?? [];
    $rawRows = $incorporated['raw_material_rows'] ?? [];
    $inciRows = $incorporated['inci_rows'] ?? [];
    $calculatedOutput = $composition['calculated_output'] ?? null;
    $formatNumber = fn (mixed $value): string => \App\Support\NumberLocale::formatAdaptiveDecimal((float) $value, 2, 8, $numberLocale);
@endphp

<section data-formula-section="composition" @if (! $isSelected) hidden @endif class="mt-5 space-y-4 break-inside-auto">
    <h2 class="text-sm font-semibold uppercase">{{ __('formula_documents.composition.section') }}</h2>

    <p class="text-xs text-slate-700">
        {{ $incorporated['basis_label'] ?? '' }}: <span class="numeric font-semibold">{{ $formatNumber($incorporated['basis_weight'] ?? 0) }} {{ $composition['unit'] ?? 'g' }}</span>
        <span class="mx-1">·</span>
        {{ $incorporated['actual_mass_label'] ?? '' }}: <span class="numeric font-semibold">{{ $formatNumber($incorporated['actual_mass'] ?? 0) }} {{ $composition['unit'] ?? 'g' }}</span>
    </p>

    <div class="break-inside-auto">
        <h3 class="text-xs font-semibold uppercase">{{ __('formula_documents.composition.raw_material_inputs') }}</h3>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full border-collapse text-left text-[11px] leading-4">
                <thead>
                    <tr class="border-b border-slate-400 text-slate-600">
                        <th class="px-1.5 py-1">{{ __('formula_documents.exports.phase') }}</th>
                        <th class="px-1.5 py-1">{{ __('formula_documents.exports.ingredient') }}</th>
                        <th class="px-1.5 py-1">{{ __('formula_documents.composition.recorded_inci') }}</th>
                        <th class="px-1.5 py-1 text-right">{{ __('formula_documents.exports.quantity') }} ({{ $composition['unit'] ?? 'g' }})</th>
                        <th class="px-1.5 py-1 text-right">{{ __('formula_documents.composition.percentage_basis') }}</th>
                        <th class="px-1.5 py-1">{{ __('formula_documents.exports.role') }}</th>
                        <th class="px-1.5 py-1">{{ __('formula_documents.exports.note') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rawRows as $row)
                        <tr class="break-inside-avoid border-b border-slate-200 align-top">
                            <td class="px-1.5 py-1">{{ $row['phase'] ?? '' }}</td>
                            <td class="px-1.5 py-1">{{ $row['name'] ?? '' }}</td>
                            <td class="px-1.5 py-1">{{ $row['recorded_inci'] ?? '' }}</td>
                            <td class="numeric px-1.5 py-1 text-right">{{ $formatNumber($row['quantity'] ?? 0) }}</td>
                            <td class="numeric px-1.5 py-1 text-right">{{ $formatNumber($row['percentage'] ?? 0) }}%</td>
                            <td class="px-1.5 py-1">{{ __('formula_documents.composition.roles.'.($row['role'] ?? 'ingredient')) }}</td>
                            <td class="px-1.5 py-1">{{ $row['note'] ?? '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-1.5 py-2 text-slate-600">{{ __('formula_documents.composition.no_raw_rows') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($inciRows !== [])
        <div class="break-inside-auto">
            <h3 class="text-xs font-semibold uppercase">{{ __('formula_documents.composition.incorporated_inci_breakdown') }}</h3>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full border-collapse text-left text-[11px] leading-4">
                    <thead>
                        <tr class="border-b border-slate-400 text-slate-600">
                            <th class="px-1.5 py-1">{{ __('formula_documents.composition.inci_ingredient') }}</th>
                            <th class="px-1.5 py-1 text-right">{{ __('formula_documents.exports.quantity') }} ({{ $composition['unit'] ?? 'g' }})</th>
                            <th class="px-1.5 py-1 text-right">{{ __('formula_documents.composition.percentage_accounted') }}</th>
                            <th class="px-1.5 py-1">{{ __('formula_documents.composition.kind') }}</th>
                            <th class="px-1.5 py-1">{{ __('formula_documents.composition.sources') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($inciRows as $row)
                            <tr class="break-inside-avoid border-b border-slate-200 align-top">
                                <td class="px-1.5 py-1">{{ $row['name'] ?? '' }}</td>
                                <td class="numeric px-1.5 py-1 text-right">{{ $formatNumber($row['quantity'] ?? 0) }}</td>
                                <td class="numeric px-1.5 py-1 text-right">{{ $formatNumber($row['percentage'] ?? 0) }}%</td>
                                <td class="px-1.5 py-1">{{ __('formula_documents.composition.inci_kinds.'.($row['kind'] ?? 'ingredient')) }}</td>
                                <td class="px-1.5 py-1">{{ implode(', ', $row['sources'] ?? []) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-700">
                {{ __('formula_documents.composition.accounted_inci_weight') }}: <span class="numeric font-semibold">{{ $formatNumber($incorporated['accounted_inci_weight'] ?? 0) }} {{ $composition['unit'] ?? 'g' }}</span>
                <span class="mx-1">·</span>
                {{ __('formula_documents.composition.accounted_inci_percentage') }}: <span class="numeric font-semibold">{{ $formatNumber($incorporated['accounted_inci_percentage'] ?? 0) }}%</span>
            </p>
        </div>
    @endif

    @if (is_array($calculatedOutput))
        <div class="break-inside-auto border-t border-slate-300 pt-3">
            <h3 class="text-xs font-semibold uppercase">{{ __('formula_documents.exports.calculated_output') }}</h3>
            <p class="mt-1 text-xs text-slate-700">
                {{ __('formula_documents.composition.cured_basis') }}: <span class="numeric font-semibold">{{ $formatNumber($calculatedOutput['basis_weight'] ?? 0) }} {{ $composition['unit'] ?? 'g' }}</span>
                <span class="mx-1">·</span>
                {{ __('formula_documents.composition.retained_dilution_liquid') }}: <span class="numeric font-semibold">{{ $formatNumber($calculatedOutput['residual_water_percentage'] ?? 0) }}%</span>
            </p>
            @if (filled($calculatedOutput['inci'] ?? null))
                <p class="mt-1 text-xs leading-5"><span class="font-semibold">{{ __('formula_documents.composition.calculated_inci') }}:</span> {{ $calculatedOutput['inci'] }}</p>
            @endif
            <div class="mt-2 overflow-x-auto">
                <table class="w-full border-collapse text-left text-[11px] leading-4">
                    <thead>
                        <tr class="border-b border-slate-400 text-slate-600">
                            <th class="px-1.5 py-1">{{ __('formula_documents.composition.component') }}</th>
                            <th class="px-1.5 py-1">{{ __('formula_documents.exports.role') }}</th>
                            <th class="px-1.5 py-1 text-right">{{ __('formula_documents.composition.percentage_cured') }}</th>
                            <th class="px-1.5 py-1 text-right">{{ __('formula_documents.exports.quantity') }} ({{ $composition['unit'] ?? 'g' }})</th>
                            <th class="px-1.5 py-1">{{ __('formula_documents.composition.sources') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (($calculatedOutput['rows'] ?? []) as $row)
                            <tr class="break-inside-avoid border-b border-slate-200 align-top">
                                <td class="px-1.5 py-1">{{ $row['name'] ?? '' }}</td>
                                <td class="px-1.5 py-1">{{ __('formula_documents.composition.roles.'.($row['role'] ?? 'ingredient')) }}</td>
                                <td class="numeric px-1.5 py-1 text-right">{{ $formatNumber($row['percentage'] ?? 0) }}%</td>
                                <td class="numeric px-1.5 py-1 text-right">{{ $formatNumber($row['weight'] ?? 0) }}</td>
                                <td class="px-1.5 py-1">{{ implode(', ', $row['sources'] ?? []) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if (($composition['settings'] ?? []) !== [])
        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            <span class="font-semibold">{{ __('formula_documents.composition.settings') }}:</span>
            @foreach ($composition['settings'] as $setting)
                <span>{{ $setting['label'] }}: {{ $setting['value'] }}</span>
            @endforeach
        </div>
    @endif

    @if (filled(data_get($composition, 'label_context.generated_inci_text')) || filled(data_get($composition, 'label_context.authored_label_text')))
        <div class="space-y-1 text-xs leading-5">
            <h3 class="font-semibold">{{ __('formula_documents.composition.selected_label_context') }}</h3>
            @if (filled(data_get($composition, 'label_context.default_variant_key')))
                <p>{{ __('formula_documents.composition.default_label_variant') }}: {{ data_get($composition, 'label_context.default_variant_key') }}</p>
            @endif
            @if (filled(data_get($composition, 'label_context.generated_inci_text')))
                <p><span class="font-semibold">{{ __('formula_documents.composition.generated_inci') }}:</span> {{ data_get($composition, 'label_context.generated_inci_text') }}</p>
            @endif
            @if (filled(data_get($composition, 'label_context.authored_label_text')))
                <p><span class="font-semibold">{{ __('formula_documents.composition.authored_label') }}:</span> {{ data_get($composition, 'label_context.authored_label_text') }}</p>
            @endif
        </div>
    @endif

    @if (filled($composition['assumption_note'] ?? null))
        <p class="text-xs leading-5 text-slate-700">{{ $composition['assumption_note'] }}</p>
    @endif

    @if (($composition['warnings'] ?? []) !== [])
        <ul class="list-disc space-y-1 pl-5 text-xs text-amber-900">
            @foreach ($composition['warnings'] as $warning)
                <li>{{ __('formula_documents.composition.warnings.'.$warning) }}</li>
            @endforeach
        </ul>
    @endif

    <p class="border-t border-slate-300 pt-2 text-xs font-medium leading-5">{{ $composition['review_note'] ?? '' }}</p>
</section>
