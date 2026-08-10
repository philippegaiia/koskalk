<x-filament::section data-ingredient-classification-helper>
    <x-slot name="heading">
        {{ __('ingredients.editor.admin.classification_prompt.heading') }}
    </x-slot>

    <x-slot name="description">
        {{ __('ingredients.editor.admin.classification_prompt.description') }}
    </x-slot>

    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button
            type="button"
            color="gray"
            wire:click="generateIngredientClassificationPrompt"
            wire:loading.attr="disabled"
            wire:target="generateIngredientClassificationPrompt"
        >
            {{ __('ingredients.editor.classification_prompt.generate') }}
        </x-filament::button>

        <x-filament::button
            type="button"
            color="gray"
            data-ingredient-classification-copy
            data-copy-label="{{ __('ingredients.editor.classification_prompt.copy') }}"
            data-copied-label="{{ __('ingredients.editor.classification_prompt.copied') }}"
            :disabled="$this->generatedIngredientClassificationPrompt === null"
        >
            {{ __('ingredients.editor.classification_prompt.copy') }}
        </x-filament::button>
    </div>

    @if ($this->generatedIngredientClassificationPrompt !== null)
        <div class="mt-4 space-y-3">
            <textarea
                data-ingredient-classification-prompt
                readonly
                rows="16"
                aria-label="{{ __('ingredients.editor.classification_prompt.preview') }}"
                class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-xs leading-5 text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
            >{{ $this->generatedIngredientClassificationPrompt }}</textarea>

            <p data-ingredient-classification-copy-failed class="hidden text-sm text-danger-600 dark:text-danger-400">
                {{ __('ingredients.editor.classification_prompt.copy_failed') }}
            </p>
        </div>
    @endif
</x-filament::section>
