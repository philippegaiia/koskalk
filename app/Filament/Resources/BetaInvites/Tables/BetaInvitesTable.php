<?php

namespace App\Filament\Resources\BetaInvites\Tables;

use App\Models\BetaInvite;
use App\Models\User;
use App\Services\BetaInviteService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BetaInvitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('workspace_name')
                    ->label('Workspace')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->state(fn (BetaInvite $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Accepted' => 'success',
                        'Pending' => 'warning',
                        'Expired', 'Revoked' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('accepted_at')
                    ->label('Accepted')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('resend')
                    ->label('Resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (BetaInvite $record): bool => $record->accepted_at === null)
                    ->action(function (BetaInvite $record, BetaInviteService $betaInviteService): void {
                        $administrator = auth()->user();

                        abort_unless($administrator instanceof User, 403);

                        $betaInviteService->issue($administrator, $record->email, $record->workspace_name);

                        Notification::make()
                            ->title('Invitation email sent')
                            ->success()
                            ->send();
                    }),
                Action::make('revoke')
                    ->label('Revoke')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (BetaInvite $record): bool => $record->isPending())
                    ->action(function (BetaInvite $record): void {
                        $record->update(['revoked_at' => now()]);

                        Notification::make()
                            ->title('Invitation revoked')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
