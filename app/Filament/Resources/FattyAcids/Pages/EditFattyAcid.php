<?php

namespace App\Filament\Resources\FattyAcids\Pages;

use App\Filament\Resources\FattyAcids\FattyAcidResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFattyAcid extends EditRecord
{
    protected static string $resource = FattyAcidResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
