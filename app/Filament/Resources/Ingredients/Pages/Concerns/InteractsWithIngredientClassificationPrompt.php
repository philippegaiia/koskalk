<?php

namespace App\Filament\Resources\Ingredients\Pages\Concerns;

use App\Data\IngredientClassificationPromptInput;
use App\Services\IngredientClassificationPromptBuilder;
use Filament\Notifications\Notification;

trait InteractsWithIngredientClassificationPrompt
{
    public ?string $generatedIngredientClassificationPrompt = null;

    public function generateIngredientClassificationPrompt(
        IngredientClassificationPromptBuilder $builder,
    ): void {
        $name = trim((string) data_get($this->data, 'current_version.display_name'));
        $inciName = trim((string) data_get($this->data, 'current_version.inci_name'));

        if ($name === '' && $inciName === '') {
            Notification::make()
                ->title(__('ingredients.editor.classification_prompt.identity_required'))
                ->danger()
                ->send();

            return;
        }

        $this->generatedIngredientClassificationPrompt = $builder->build(
            new IngredientClassificationPromptInput(
                name: data_get($this->data, 'current_version.display_name'),
                inciName: data_get($this->data, 'current_version.inci_name'),
                casNumber: data_get($this->data, 'current_version.cas_number'),
                ecNumber: data_get($this->data, 'current_version.ec_number'),
                supplierNotes: null,
                responseLocale: app()->getLocale(),
            ),
        );
    }
}
