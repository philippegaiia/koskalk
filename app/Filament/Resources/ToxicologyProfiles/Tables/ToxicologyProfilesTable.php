<?php

namespace App\Filament\Resources\ToxicologyProfiles\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ToxicologyProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('substance'))
            ->columns([
                TextColumn::make('substance.name')->label('Material')->searchable()->sortable(),
                TextColumn::make('version')->sortable(),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('noael_mg_kg_bw_day')->label('NOAEL')->placeholder('—'),
                TextColumn::make('dermal_absorption_percent')->label('DAp %')->placeholder('—'),
                TextColumn::make('nesil_ug_cm2')->label('NESIL')->placeholder('—'),
                TextColumn::make('created_at')->label('Published')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_current')->label('Current versions')->default(true),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No toxicological profiles yet');
    }
}
