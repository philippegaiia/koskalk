@props(['procedure', 'isSelected' => true])

<section data-formula-section="procedure" @if (! $isSelected) hidden @endif class="mt-5">
    <h2 class="text-sm font-semibold uppercase">{{ __('formula_documents.sections.manufacturing_procedure') }}</h2>
    <div class="prose prose-sm mt-2 max-w-none text-sm leading-6" data-formula-procedure-content>{!! str($procedure)->sanitizeHtml() !!}</div>
</section>
