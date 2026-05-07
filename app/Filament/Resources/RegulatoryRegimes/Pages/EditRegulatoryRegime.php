<?php

namespace App\Filament\Resources\RegulatoryRegimes\Pages;

use App\Filament\Resources\RegulatoryRegimes\RegulatoryRegimeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegulatoryRegime extends EditRecord
{
    protected static string $resource = RegulatoryRegimeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
