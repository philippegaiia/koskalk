@props(['document'])

@php
    $percentageHeading = $document['percentage_basis'] === 'formula'
        ? __('formula_documents.columns.percentage_formula')
        : __('formula_documents.columns.percentage_oils');
@endphp

<div data-formula-document-table {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl border border-[var(--color-line)] bg-white']) }}>
    <table class="min-w-full border-collapse text-sm">
        <thead class="bg-[var(--color-panel-strong)] text-left text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-soft)] uppercase print:table-header-group">
            <tr>
                <th scope="col" class="px-4 py-3">{{ __('formula_documents.columns.ingredient') }}</th>
                <th scope="col" class="px-4 py-3 text-right">{{ $percentageHeading }}</th>
                <th scope="col" class="px-4 py-3 text-right">{{ __('formula_documents.columns.weight', ['unit' => $document['unit']]) }}</th>
                <th scope="col" class="px-4 py-3">{{ __('formula_documents.columns.note') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--color-line)]">
            @foreach ($document['sections'] as $section)
                <tr class="bg-[var(--color-panel)]">
                    <th scope="rowgroup" colspan="4" class="px-4 py-2 text-left text-xs font-semibold tracking-[0.08em] text-[var(--color-ink-strong)] uppercase">
                        {{ $section['label'] }}
                    </th>
                </tr>
                @foreach ($section['rows'] as $row)
                    <tr>
                        <td class="px-4 py-2.5 font-medium text-[var(--color-ink-strong)]">
                            {{ $row['name'] }} <x-ingredient-source-marker :is-user-owned="$row['is_user_owned']" />
                        </td>
                        <td class="numeric px-4 py-2.5 text-right">{{ number_format($row['percentage'], 2) }}%</td>
                        <td class="numeric px-4 py-2.5 text-right">{{ number_format($row['weight'], 2) }}</td>
                        <td class="px-4 py-2.5 text-[var(--color-ink-soft)]">{{ $row['note'] ?: '—' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
