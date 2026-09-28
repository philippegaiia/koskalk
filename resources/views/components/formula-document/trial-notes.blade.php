@props(['isSelected' => true])

<section data-formula-section="notes" @if (! $isSelected) hidden @endif class="mt-6 break-inside-avoid">
    <h2 class="text-sm font-semibold uppercase">{{ __('formula_documents.sections.trial_notes') }}</h2>
    <div class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
        @foreach ([
            __('formula_documents.print.reference'),
            __('formula_documents.print.date'),
            __('formula_documents.print.prepared_by'),
            __('formula_documents.print.checked_by'),
        ] as $field)
            <div class="min-h-12 border border-slate-400 p-2">
                <p class="font-semibold">{{ $field }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-3 text-xs">
        <div class="min-h-28 border border-slate-400 p-3"><h3 class="font-semibold uppercase">{{ __('formula_documents.print.observations') }}</h3></div>
        <div class="min-h-28 border border-slate-400 p-3"><h3 class="font-semibold uppercase">{{ __('formula_documents.print.result') }}</h3></div>
    </div>
</section>
