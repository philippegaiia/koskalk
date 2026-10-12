@props(['availableSections', 'selectedSections'])

<div class="space-y-2">
    <div data-formula-document-options class="flex flex-wrap items-center justify-end gap-2">
        <details class="relative">
            <summary class="cursor-pointer list-none rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700">
                {{ __('formula_documents.print.include') }}
            </summary>
            <div class="absolute right-0 z-10 mt-2 min-w-56 rounded-lg border border-slate-300 bg-white p-3 shadow-lg">
                <fieldset class="space-y-2">
                    <legend class="sr-only">{{ __('formula_documents.print.include') }}</legend>
                    @foreach ($availableSections as $section)
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-800">
                            <input
                                type="checkbox"
                                name="sections[]"
                                value="{{ $section }}"
                                data-formula-section-toggle="{{ $section }}"
                                @checked(in_array($section, $selectedSections, true))
                                class="size-4 rounded border-slate-400 accent-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700"
                            >
                            <span>{{ __('formula_documents.section_options.'.$section) }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <p
                    data-formula-section-status
                    data-empty-selection-message="{{ __('formula_documents.validation.sections_required') }}"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                    class="sr-only"
                ></p>
            </div>
        </details>

        <button type="button" data-print-document aria-describedby="formula-print-pdf-hint" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
            <x-action-icon name="printer" />{{ __('formula_documents.actions.print_sheet') }}
        </button>
    </div>
    <p id="formula-print-pdf-hint" class="max-w-sm text-xs text-slate-600">{{ __('formula_documents.print.pdf_hint') }}</p>
</div>
