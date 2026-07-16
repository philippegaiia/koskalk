<?php

namespace App\Filament\Resources\BetaInvites\Pages;

use App\Filament\Resources\BetaInvites\BetaInviteResource;
use App\Models\BetaInvite;
use App\Models\User;
use App\Services\BetaInviteService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateBetaInvite extends CreateRecord
{
    protected static string $resource = BetaInviteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $administrator = auth()->user();

        abort_unless($administrator instanceof User, 403);

        app(BetaInviteService::class)->issue(
            $administrator,
            (string) $data['email'],
            (string) $data['workspace_name'],
        );

        return BetaInvite::query()
            ->where('email', Str::lower(trim((string) $data['email'])))
            ->sole();
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Beta invitation sent')
            ->body('The recipient can create their verified Free beta workspace from the email link.')
            ->success()
            ->send();
    }
}
