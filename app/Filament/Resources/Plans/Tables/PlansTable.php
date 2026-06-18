<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Models\Plan;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('limits'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Plan $record): string => $record->slug),
                TextColumn::make('saved_recipes_limit')
                    ->label('Recipes')
                    ->state(fn (Plan $record): string => self::limitValue($record, 'saved_recipes'))
                    ->badge(),
                TextColumn::make('private_ingredients_limit')
                    ->label('Private ingredients')
                    ->state(fn (Plan $record): string => self::limitValue($record, 'private_ingredients'))
                    ->badge(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('display_order')
                    ->label('Sort')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('display_order')
            ->emptyStateHeading('No plans yet')
            ->emptyStateDescription('Create a default free plan before launch so account limits can be managed from the admin panel.');
    }

    private static function limitValue(Plan $plan, string $key): string
    {
        $limit = $plan->limits->firstWhere('key', $key);

        return $limit?->value === null ? 'Unlimited' : (string) $limit->value;
    }
}
