@php
    $editor = $this;
@endphp

<section
    class="sk-inset sk-tone-info p-4 sm:p-5"
    x-data="classificationPrompt()"
    aria-labelledby="classification-prompt-title"
>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <p class="sk-eyebrow">{{ __('ingredients.editor.classification_prompt.eyebrow') }}</p>
            <h2 id="classification-prompt-title" class="mt-1 text-base font-semibold text-[var(--color-ink-strong)]">
                {{ __('ingredients.editor.classification_prompt.heading') }}
            </h2>
            <p class="mt-1 text-sm leading-6 text-[var(--color-ink-soft)]">
                {{ __('ingredients.editor.classification_prompt.description') }}
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <button
                type="button"
                class="sk-btn sk-btn-outline"
                wire:click="generateClassificationPrompt"
                wire:loading.attr="disabled"
                wire:target="generateClassificationPrompt"
            >
                {{ __('ingredients.editor.classification_prompt.generate') }}
            </button>
            <button
                type="button"
                class="sk-btn sk-btn-outline"
                data-classification-prompt-copy
                @disabled($editor->generatedClassificationPrompt === null)
                x-on:click="copy($refs.classificationPrompt?.value ?? '')"
            >
                <span x-show="! copied">{{ __('ingredients.editor.classification_prompt.copy') }}</span>
                <span x-cloak x-show="copied">{{ __('ingredients.editor.classification_prompt.copied') }}</span>
            </button>
        </div>
    </div>

    @if ($editor->generatedClassificationPrompt !== null)
        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-[var(--color-ink-strong)]">
                {{ __('ingredients.editor.classification_prompt.preview') }}
            </summary>
            <textarea
                x-ref="classificationPrompt"
                readonly
                aria-label="{{ __('ingredients.editor.classification_prompt.preview') }}"
                class="mt-3 h-56 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field-muted)] p-3 text-xs leading-5 text-[var(--color-ink-strong)]"
            >{{ $editor->generatedClassificationPrompt }}</textarea>
            <p x-cloak x-show="copyFailed" class="mt-3 text-sm text-[var(--color-danger-strong)]">
                {{ __('ingredients.editor.classification_prompt.copy_failed') }}
            </p>
        </details>
    @endif
</section>
