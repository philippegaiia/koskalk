<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientDataEntry;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientTranslationService;
use App\Services\PlatformIngredientDeletionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class EditIngredient extends EditRecord
{
    use InteractsWithIngredientDataEntry;

    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label('Delete')
                ->color('danger')
                ->icon(Heroicon::Trash)
                ->modalIcon(Heroicon::OutlinedTrash)
                ->modalHeading('Delete platform ingredient')
                ->modalDescription('Delete only accidental, unused catalog records. Deactivate ingredients that are already used.')
                ->modalSubmitActionLabel('Delete ingredient')
                ->requiresConfirmation()
                ->visible(fn (Ingredient $record): bool => $record->owner_type === null && $record->owner_id === null)
                ->action(function (Action $action, Ingredient $record, PlatformIngredientDeletionService $deletionService): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    try {
                        $deletionService->delete($actor, $record);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Ingredient was not deleted')
                            ->body($exception->errors()['ingredient'][0] ?? 'This ingredient cannot be deleted.')
                            ->danger()
                            ->send();

                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title('Ingredient deleted')
                        ->success()
                        ->send();

                    $this->redirect(IngredientResource::getUrl('index'), navigate: true);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge(
            $data,
            app(IngredientDataEntryService::class)->formData($this->record),
            [
                'translations' => app(IngredientTranslationService::class)->formData($this->record),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractIngredientDataEntryState($data);
    }

    protected function afterSave(): void
    {
        $this->syncIngredientDataEntryState($this->record);
    }
}
