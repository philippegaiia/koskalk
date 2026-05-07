<?php

namespace App\Filament\Resources\RegulatoryRegimeAllergens\Pages;

use App\Filament\Resources\RegulatoryRegimeAllergens\RegulatoryRegimeAllergenResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegulatoryRegimeAllergen extends EditRecord
{
    protected static string $resource = RegulatoryRegimeAllergenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
