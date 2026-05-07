<?php

namespace App\Filament\Resources\RegulatoryRegimes\Pages;

use App\Filament\Resources\RegulatoryRegimes\RegulatoryRegimeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegulatoryRegimes extends ListRecords
{
    protected static string $resource = RegulatoryRegimeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
