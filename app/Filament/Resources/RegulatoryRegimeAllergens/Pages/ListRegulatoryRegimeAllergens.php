<?php

namespace App\Filament\Resources\RegulatoryRegimeAllergens\Pages;

use App\Filament\Resources\RegulatoryRegimeAllergens\RegulatoryRegimeAllergenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegulatoryRegimeAllergens extends ListRecords
{
    protected static string $resource = RegulatoryRegimeAllergenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
