@props(['analysis', 'numberLocale', 'isSelected' => true])

<article data-formula-section="analysis" @if (! $isSelected) hidden @endif class="document-sheet mx-auto mt-6 max-w-5xl break-before-page bg-white text-slate-900 print:max-w-none">
    <h2 class="text-xl font-semibold">{{ __('formula_documents.analysis.title') }}</h2>

    <section class="mt-5">
        <h3 class="text-xs font-semibold tracking-[0.08em] uppercase">{{ __('formula_documents.analysis.qualities') }}</h3>
        <table class="mt-2 w-full border-collapse text-xs">
            <thead class="print:table-header-group">
                <tr>
                    <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.quality') }}</th>
                    <th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.value') }}</th>
                    <th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.suggested_range') }}</th>
                    <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($analysis['qualities'] as $row)
                    <tr>
                        <th class="border border-slate-300 p-2 text-left font-medium">{{ $row['label'] }}</th>
                        <td class="numeric border border-slate-300 p-2 text-right">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['value'], 2, 2, $numberLocale) }}</td>
                        <td class="numeric border border-slate-300 p-2 text-right">{{ $row['range'] }}</td>
                        <td class="border border-slate-300 p-2">{{ $row['status'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="mt-5">
        <h3 class="text-xs font-semibold tracking-[0.08em] uppercase">{{ __('formula_documents.analysis.fatty_acids') }}</h3>
        <table class="mt-2 w-full border-collapse text-xs">
            <thead class="print:table-header-group">
                <tr>
                    <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.fatty_acid') }}</th>
                    <th class="border border-slate-300 p-2 text-right">{{ __('formula_documents.analysis.percentage') }}</th>
                    <th class="border border-slate-300 p-2 text-left">{{ __('formula_documents.analysis.contribution') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($analysis['fatty_acids'] as $row)
                    <tr>
                        <th class="border border-slate-300 p-2 text-left font-medium">{{ $row['label'] }}</th>
                        <td class="numeric border border-slate-300 p-2 text-right">{{ \App\Support\NumberLocale::formatAdaptiveDecimal($row['value'], 2, 2, $numberLocale) }}%</td>
                        <td class="border border-slate-300 p-2">{{ $row['contribution'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</article>
