<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegulatoryLegalEntries extends ListRecords
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
