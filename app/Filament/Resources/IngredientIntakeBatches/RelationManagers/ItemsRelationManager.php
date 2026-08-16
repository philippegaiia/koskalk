<?php

namespace App\Filament\Resources\IngredientIntakeBatches\RelationManagers;

use App\Actions\IngredientIntake\RemoveIngredientIntakeRow;
use App\Actions\IngredientIntake\ResolveIngredientIntakeDuplicate;
use App\Actions\IngredientIntake\UpdateIngredientIntakeRow;
use App\Enums\IngredientDuplicateResolution;
use App\Enums\IngredientIntakeItemStatus;
use App\Filament\Resources\Ingredients\IngredientResource;
use App\Models\Ingredient;
use App\Models\IngredientIntakeItem;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static bool $isLazy = false;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('row_number')->label(__('ingredient_intake_admin.item.row')),
            TextEntry::make('original_current_name')->label(__('ingredient_intake_admin.item.current_name')),
            TextEntry::make('original_inci_name')->label(__('ingredient_intake_admin.item.inci_name')),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state): string => $state->label()),
            TextEntry::make('duplicate_resolution')
                ->label(__('ingredient_intake_admin.item.resolution'))
                ->formatStateUsing(fn ($state): string => $state?->label() ?? __('ingredient_intake_admin.item.not_resolved')),
            TextEntry::make('duplicate_candidates')
                ->label(__('ingredient_intake_admin.item.duplicate_candidates'))
                ->state(fn (IngredientIntakeItem $record): array => collect($record->duplicate_candidates ?? [])
                    ->map(fn (mixed $candidate): ?string => is_array($candidate) ? (string) ($candidate['label'] ?? $candidate['matched_value'] ?? '') : null)
                    ->filter()
                    ->values()
                    ->all())
                ->listWithLineBreaks(),
            TextEntry::make('existingIngredient.display_name')
                ->label(__('ingredient_intake_admin.item.existing_ingredient'))
                ->url(fn (IngredientIntakeItem $record): ?string => $record->existingIngredient instanceof Ingredient
                    ? IngredientResource::getUrl('edit', ['record' => $record->existingIngredient])
                    : null),
            TextEntry::make('promotedIngredient.display_name')
                ->label(__('ingredient_intake_admin.item.promoted_ingredient'))
                ->url(fn (IngredientIntakeItem $record): ?string => $record->promotedIngredient instanceof Ingredient
                    ? IngredientResource::getUrl('edit', ['record' => $record->promotedIngredient])
                    : null),
            TextEntry::make('failure_message')
                ->label(__('ingredient_intake_admin.item.failure'))
                ->visible(fn (IngredientIntakeItem $record): bool => filled($record->failure_message))
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_current_name')
            ->poll(fn (): ?string => $this->getOwnerRecord()->status->isTerminal() ? null : '5s')
            ->columns([
                TextColumn::make('row_number')
                    ->label(__('ingredient_intake_admin.item.row'))
                    ->sortable(),
                TextColumn::make('original_current_name')
                    ->label(__('ingredient_intake_admin.item.current_name'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('original_inci_name')
                    ->label(__('ingredient_intake_admin.item.inci_name'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('duplicates')
                    ->label(__('ingredient_intake_admin.item.duplicates'))
                    ->state(fn (IngredientIntakeItem $record): string => (string) count($record->duplicate_candidates ?? []))
                    ->badge(),
                TextColumn::make('existingIngredient.display_name')
                    ->label(__('ingredient_intake_admin.item.existing_ingredient'))
                    ->placeholder('—'),
                TextColumn::make('promotedIngredient.display_name')
                    ->label(__('ingredient_intake_admin.item.promoted_ingredient'))
                    ->placeholder('—'),
            ])
            ->recordActions([
                ViewAction::make()->label(__('ingredient_intake_admin.actions.view_row')),
                Action::make('editRow')
                    ->label(__('ingredient_intake_admin.actions.edit_row'))
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (IngredientIntakeItem $record): bool => in_array($record->status, [
                        IngredientIntakeItemStatus::Draft,
                        IngredientIntakeItemStatus::NeedsResolution,
                    ], true))
                    ->fillForm(fn (IngredientIntakeItem $record): array => [
                        'current_name' => $record->original_current_name,
                        'inci_name' => $record->original_inci_name,
                    ])
                    ->schema([
                        TextInput::make('current_name')
                            ->label(__('ingredient_intake_admin.form.current_name'))
                            ->maxLength(255),
                        TextInput::make('inci_name')
                            ->label(__('ingredient_intake_admin.form.inci_name'))
                            ->maxLength(255),
                    ])
                    ->successNotificationTitle(__('ingredient_intake_admin.notifications.row_updated'))
                    ->action(fn (IngredientIntakeItem $record, array $data, UpdateIngredientIntakeRow $updateRow) => $updateRow->handle(
                        auth()->user(),
                        $record,
                        $data['current_name'] ?? null,
                        $data['inci_name'] ?? null,
                    )),
                Action::make('resolveDuplicate')
                    ->label(__('ingredient_intake_admin.actions.resolve_duplicate'))
                    ->icon('heroicon-o-link')
                    ->visible(fn (IngredientIntakeItem $record): bool => is_array($record->duplicate_candidates)
                        && $record->duplicate_candidates !== []
                        && in_array($record->status, [IngredientIntakeItemStatus::Draft, IngredientIntakeItemStatus::NeedsResolution], true))
                    ->schema(fn (IngredientIntakeItem $record): array => [
                        Radio::make('resolution')
                            ->label(__('ingredient_intake_admin.form.resolution'))
                            ->options(collect(IngredientDuplicateResolution::cases())->mapWithKeys(
                                fn (IngredientDuplicateResolution $resolution): array => [$resolution->value => $resolution->label()],
                            )->all())
                            ->live()
                            ->required(),
                        Select::make('existing_ingredient_id')
                            ->label(__('ingredient_intake_admin.form.existing_ingredient'))
                            ->options($this->existingCandidateOptions($record))
                            ->searchable()
                            ->visible(fn (Get $get): bool => $get('resolution') === IngredientDuplicateResolution::ExistingIngredient->value)
                            ->required(fn (Get $get): bool => $get('resolution') === IngredientDuplicateResolution::ExistingIngredient->value),
                        Text::make(__('ingredient_intake_admin.form.resolution_help')),
                    ])
                    ->successNotificationTitle(__('ingredient_intake_admin.notifications.duplicate_resolved'))
                    ->action(function (IngredientIntakeItem $record, array $data, ResolveIngredientIntakeDuplicate $resolve): IngredientIntakeItem {
                        $resolution = IngredientDuplicateResolution::tryFrom((string) ($data['resolution'] ?? ''));
                        if (! $resolution instanceof IngredientDuplicateResolution) {
                            throw ValidationException::withMessages([
                                'resolution' => __('ingredient_intake_admin.validation.duplicate_resolution_required'),
                            ]);
                        }

                        $existing = isset($data['existing_ingredient_id'])
                            ? Ingredient::query()->withoutGlobalScopes()->find($data['existing_ingredient_id'])
                            : null;

                        return $resolve->handle(auth()->user(), $record, $resolution, $existing);
                    }),
                Action::make('removeRow')
                    ->label(__('ingredient_intake_admin.actions.remove_row'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (IngredientIntakeItem $record): bool => in_array($record->status, [
                        IngredientIntakeItemStatus::Draft,
                        IngredientIntakeItemStatus::NeedsResolution,
                    ], true))
                    ->requiresConfirmation()
                    ->successNotificationTitle(__('ingredient_intake_admin.notifications.row_removed'))
                    ->action(function (IngredientIntakeItem $record, RemoveIngredientIntakeRow $removeRow): void {
                        $removeRow->handle(auth()->user(), $record);
                    }),
            ])
            ->defaultSort('row_number');
    }

    /** @return array<int, string> */
    private function existingCandidateOptions(IngredientIntakeItem $record): array
    {
        return collect($record->duplicate_candidates ?? [])
            ->filter(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['candidate_type'] ?? null) === 'ingredient'
                && isset($candidate['ingredient_id']))
            ->mapWithKeys(fn (array $candidate): array => [(int) $candidate['ingredient_id'] => (string) ($candidate['label'] ?? $candidate['catalog_key'] ?? '')])
            ->all();
    }
}
