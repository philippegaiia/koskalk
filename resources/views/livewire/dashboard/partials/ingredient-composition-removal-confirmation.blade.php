@php
    $editor = $this;
@endphp

<aside
    class="sk-inset sk-tone-warning space-y-3 p-4 sm:p-5"
    aria-labelledby="composition-removal-warning"
>
    <p id="composition-removal-warning" class="text-sm leading-6 text-[var(--color-warning-strong)]">
        {{ __('ingredients.editor.details.composition_removal_warning') }}
    </p>

    {{ $editor->compositionRemovalConfirmationForm }}
</aside>
