<?php

namespace App\Filament\Resources\UserIngredients\Tables;

use App\Enums\IngredientCategory;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserIngredientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Ingredient')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('inci_name')
                    ->label('INCI')
                    ->placeholder('Not provided')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('workspace_name')
                    ->label('Workspace')
                    ->placeholder('No workspace')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereLike('ingredient_workspace.name', "%{$search}%"))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('ingredient_workspace.name', $direction))
                    ->wrap(),
                TextColumn::make('owner_email')
                    ->label('Owner email')
                    ->placeholder('Not available')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereLike('ingredient_account.email', "%{$search}%"))
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date()
                    ->sortable(['ingredients.created_at']),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(['ingredients.updated_at']),
            ])
            ->filters([
                Filter::make('workspace')
                    ->schema([TextInput::make('name')->label('Workspace name')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['name'] ?? null),
                        fn (Builder $query): Builder => $query->whereLike('ingredient_workspace.name', '%'.trim($data['name']).'%'),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['name'] ?? null) ? 'Workspace: '.$data['name'] : null),
                Filter::make('owner_email')
                    ->schema([TextInput::make('email')->label('Owner email')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['email'] ?? null),
                        fn (Builder $query): Builder => $query->whereLike('ingredient_account.email', '%'.trim($data['email']).'%'),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['email'] ?? null) ? 'Owner email: '.$data['email'] : null),
                SelectFilter::make('category')
                    ->multiple()
                    ->options(IngredientCategory::options()),
                Filter::make('missing_inci')
                    ->label('Missing INCI')
                    ->query(fn (Builder $query): Builder => $query->where(
                        fn (Builder $query): Builder => $query
                            ->whereNull('inci_name')
                            ->orWhere('inci_name', ''),
                    )),
                TernaryFilter::make('is_active')
                    ->attribute('ingredients.is_active')
                    ->label('Active'),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('ingredients.created_at', 'desc')
            ->emptyStateHeading('No user ingredients yet')
            ->emptyStateDescription('User-created ingredients will appear here with their workspace and owner email for catalog review.')
            ->paginated([25, 50, 100]);
    }
}
