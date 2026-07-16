<?php

namespace App\Filament\Resources\BetaInvites\Pages;

use App\Filament\Resources\BetaInvites\BetaInviteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBetaInvites extends ListRecords
{
    protected static string $resource = BetaInviteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
