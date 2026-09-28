@props(['labelText', 'isSelected' => true])

<section data-formula-section="label" @if (! $isSelected) hidden @endif class="mt-5">
    <h2 class="text-sm font-semibold uppercase">{{ __('formula_documents.sections.label') }}</h2>
    <p class="mt-2 text-sm leading-6">{{ $labelText }}</p>
</section>
