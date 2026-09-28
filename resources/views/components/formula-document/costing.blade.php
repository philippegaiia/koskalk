@props(['projection', 'numberLocale', 'isSelected' => false])

<section data-formula-section="costing" @if (! $isSelected) hidden @endif class="mt-5 border-t border-slate-300 pt-4">
    <h2 class="text-xl font-semibold">{{ __('formula_documents.section_options.costing') }}</h2>
    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.costing_basis') }}</dt>
            <dd class="numeric mt-0.5">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($projection['basis_weight'], 2, 8, $numberLocale) }} {{ $projection['basis_unit'] }}</dd>
        </div>
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.currency') }}</dt>
            <dd class="mt-0.5">{{ $projection['currency'] }}</dd>
        </div>
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.units_produced') }}</dt>
            <dd class="numeric mt-0.5">{{ $projection['units_produced'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($projection['units_produced'], 0, 6, $numberLocale) }}</dd>
        </div>
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.ingredient_total') }}</dt>
            <dd class="numeric mt-0.5">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($projection['ingredient_total'], 2, 8, $numberLocale) }} {{ $projection['currency'] }}</dd>
        </div>
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.packaging_total') }}</dt>
            <dd class="numeric mt-0.5">{{ $projection['packaging_total'] === null ? __('formula_documents.exports.units_required') : \App\Support\NumberLocale::formatAdaptiveDecimal($projection['packaging_total'], 2, 8, $numberLocale).' '.$projection['currency'] }}</dd>
        </div>
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.total_cost') }}</dt>
            <dd class="numeric mt-0.5">{{ $projection['total_cost'] === null ? __('formula_documents.exports.packaging_total_incomplete') : \App\Support\NumberLocale::formatAdaptiveDecimal($projection['total_cost'], 2, 8, $numberLocale).' '.$projection['currency'] }}</dd>
        </div>
        <div>
            <dt class="font-semibold text-slate-600">{{ __('formula_documents.exports.cost_per_unit') }}</dt>
            <dd class="numeric mt-0.5">{{ $projection['cost_per_unit'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($projection['cost_per_unit'], 2, 8, $numberLocale).' '.$projection['currency'] }}</dd>
        </div>
    </dl>

    @if ($projection['has_unpriced_rows'])
        <p class="mt-2 text-sm text-amber-800">{{ __('formula_documents.exports.includes_unpriced_rows') }}</p>
    @endif

    <h3 class="mt-4 text-sm font-semibold">{{ __('formula_documents.exports.costing_ingredients') }}</h3>
    <div data-formula-document-table class="mt-2 overflow-x-auto">
        <table class="w-full border-collapse text-left text-xs">
            <thead><tr class="border-b border-slate-400">
                <th class="px-2 py-2">{{ __('formula_documents.exports.phase') }}</th>
                <th class="px-2 py-2">{{ __('formula_documents.exports.ingredient') }}</th>
                <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.percentage_'.$projection['percentage_basis']) }}</th>
                <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.quantity') }}</th>
                <th class="px-2 py-2">{{ __('formula_documents.exports.unit') }}</th>
                <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.price_per_kg') }}</th>
                <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.line_cost') }}</th>
                <th class="px-2 py-2">{{ __('formula_documents.exports.status') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($projection['ingredient_rows'] as $row)
                    <tr class="border-b border-slate-200">
                        <td class="px-2 py-2">{{ $row['phase_name'] }}</td>
                        <td class="px-2 py-2">{{ $row['ingredient_name'] }}</td>
                        <td class="numeric px-2 py-2 text-right">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['percentage'], 2, 8, $numberLocale) }}</td>
                        <td class="numeric px-2 py-2 text-right">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['quantity'], 2, 8, $numberLocale) }}</td>
                        <td class="px-2 py-2">{{ $row['unit'] }}</td>
                        <td class="numeric px-2 py-2 text-right">{{ $row['price_per_kg'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($row['price_per_kg'], 2, 8, $numberLocale) }}</td>
                        <td class="numeric px-2 py-2 text-right">{{ $row['line_cost'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($row['line_cost'], 2, 8, $numberLocale) }}</td>
                        <td class="px-2 py-2">{{ $row['is_unpriced'] ? __('formula_documents.exports.unpriced') : __('formula_documents.exports.priced') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($projection['packaging_rows'] !== [])
        <h3 class="mt-5 text-sm font-semibold">{{ __('formula_documents.exports.costing_packaging') }}</h3>
        <div data-formula-document-table class="mt-2 overflow-x-auto">
            <table class="w-full border-collapse text-left text-xs">
                <thead><tr class="border-b border-slate-400">
                    <th class="px-2 py-2">{{ __('formula_documents.exports.packaging') }}</th>
                    <th class="px-2 py-2">{{ __('formula_documents.exports.material_code') }}</th>
                    <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.components_per_unit') }}</th>
                    <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.unit_cost') }}</th>
                    <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.cost_per_finished_unit') }}</th>
                    <th class="px-2 py-2 text-right">{{ __('formula_documents.exports.batch_cost') }}</th>
                    <th class="px-2 py-2">{{ __('formula_documents.exports.status') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($projection['packaging_rows'] as $row)
                        <tr class="border-b border-slate-200">
                            <td class="px-2 py-2">{{ $row['name'] }}</td>
                            <td class="px-2 py-2">{{ $row['material_code'] ?? '—' }}</td>
                            <td class="numeric px-2 py-2 text-right">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['components_per_unit'], 2, 8, $numberLocale) }}</td>
                            <td class="numeric px-2 py-2 text-right">{{ $row['unit_cost'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($row['unit_cost'], 2, 8, $numberLocale) }}</td>
                            <td class="numeric px-2 py-2 text-right">{{ $row['cost_per_finished_unit'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($row['cost_per_finished_unit'], 2, 8, $numberLocale) }}</td>
                            <td class="numeric px-2 py-2 text-right">{{ $row['line_cost'] === null ? '—' : \App\Support\NumberLocale::formatAdaptiveDecimal($row['line_cost'], 2, 8, $numberLocale) }}</td>
                            <td class="px-2 py-2">{{ $row['is_unpriced'] ? __('formula_documents.exports.unpriced') : ($row['line_cost'] === null ? __('formula_documents.exports.units_required') : __('formula_documents.exports.priced')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
