<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Enums\IngredientCategory;
use App\Enums\IngredientLabelMarket;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientClassificationPrompt;
use App\Filament\Resources\Ingredients\Pages\Concerns\InteractsWithIngredientDataEntry;
use App\Models\Ingredient;
use App\Services\IngredientDataEntryService;
use App\Services\IngredientMarketLabelService;
use App\Services\IngredientTranslationService;
use App\Services\PlatformIngredientDeletionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class EditIngredient extends EditRecord
{
    use InteractsWithIngredientClassificationPrompt;
    use InteractsWithIngredientDataEntry;

    protected static string $resource = IngredientResource::class;

    public static bool $formActionsAreSticky = true;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('marketColourLabels')
                ->label(__('ingredient_admin.market_labels.action.label'))
                ->icon(Heroicon::Swatch)
                ->modalHeading(__('ingredient_admin.market_labels.action.heading'))
                ->modalDescription(__('ingredient_admin.market_labels.action.description'))
                ->modalSubmitActionLabel(__('ingredient_admin.market_labels.action.submit'))
                ->visible(fn (Ingredient $record): bool => $record->owner_type === null
                    && $record->owner_id === null
                    && $record->category === IngredientCategory::Colourants)
                ->fillForm(fn (Ingredient $record): array => [
                    'market_labels' => app(IngredientMarketLabelService::class)->formData($record),
                ])
                ->schema([
                    Repeater::make('market_labels')
                        ->label(__('ingredient_admin.market_labels.action.declarations'))
                        ->schema([
                            Select::make('market_code')
                                ->label(__('ingredient_admin.market_labels.action.market'))
                                ->options(collect(IngredientLabelMarket::cases())
                                    ->mapWithKeys(fn (IngredientLabelMarket $market): array => [$market->value => $market->label()])
                                    ->all())
                                ->required(),
                            TextInput::make('declaration_name')
                                ->label(__('ingredient_admin.market_labels.action.declaration_name'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('source_name')
                                ->label(__('ingredient_admin.market_labels.action.source'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('source_url')
                                ->label(__('ingredient_admin.market_labels.action.source_url'))
                                ->url()
                                ->required()
                                ->maxLength(2000),
                            DatePicker::make('effective_from')
                                ->label(__('ingredient_admin.market_labels.action.effective_from')),
                            DatePicker::make('effective_until')
                                ->label(__('ingredient_admin.market_labels.action.effective_until')),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->maxItems(count(IngredientLabelMarket::cases()))
                        ->addActionLabel(__('ingredient_admin.market_labels.action.add')),
                ])
                ->action(function (Action $action, Ingredient $record, array $data, IngredientMarketLabelService $labelService): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    try {
                        $labelService->replaceReviewed($record, $data['market_labels'] ?? [], $actor);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(__('ingredient_admin.market_labels.action.failed'))
                            ->body($exception->errors()['ingredient'][0] ?? __('ingredient_admin.market_labels.action.failed_body'))
                            ->danger()
                            ->send();

                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title(__('ingredient_admin.market_labels.action.saved'))
                        ->success()
                        ->send();
                }),
            Action::make('delete')
                ->label(__('ingredient_admin.delete.label'))
                ->color('danger')
                ->icon(Heroicon::Trash)
                ->modalIcon(Heroicon::OutlinedTrash)
                ->modalHeading(__('ingredient_admin.delete.heading'))
                ->modalDescription(__('ingredient_admin.delete.description'))
                ->modalSubmitActionLabel(__('ingredient_admin.delete.submit'))
                ->requiresConfirmation()
                ->visible(fn (Ingredient $record): bool => $record->owner_type === null && $record->owner_id === null)
                ->action(function (Action $action, Ingredient $record, PlatformIngredientDeletionService $deletionService): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    try {
                        $deletionService->delete($actor, $record);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title(__('ingredient_admin.delete.failed'))
                            ->body($exception->errors()['ingredient'][0] ?? __('ingredient_admin.delete.failed_body'))
                            ->danger()
                            ->send();

                        $action->halt();

                        return;
                    }

                    Notification::make()
                        ->title(__('ingredient_admin.delete.saved'))
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
                'market_labels' => $this->marketLabelFormData($this->record),
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
