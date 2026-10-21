<?php

namespace App\Filament\Resources\WorkspaceDossierGrants\Tables;

use App\Models\WorkspaceDossierGrant;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkspaceDossierGrantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['workspace', 'grantedBy']))
            ->columns([
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->searchable(),
                IconColumn::make('active')
                    ->label('Active')
                    ->state(fn (WorkspaceDossierGrant $record): bool => $record->isActive())
                    ->boolean(),
                TextColumn::make('grantedBy.email')
                    ->label('Granted by')
                    ->placeholder('—'),
                TextColumn::make('granted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('revoked_at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (WorkspaceDossierGrant $record): bool => $record->isActive())
                    ->action(fn (WorkspaceDossierGrant $record) => $record->update(['revoked_at' => now()])),
                Action::make('restore')
                    ->label('Restore')
                    ->visible(fn (WorkspaceDossierGrant $record): bool => ! $record->isActive())
                    ->action(fn (WorkspaceDossierGrant $record) => $record->update(['revoked_at' => null])),
            ])
            ->defaultSort('granted_at', 'desc')
            ->emptyStateHeading('No workspace has the DIP module yet');
    }
}
