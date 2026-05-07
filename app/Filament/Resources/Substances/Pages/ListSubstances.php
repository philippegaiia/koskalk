<?php

namespace App\Filament\Resources\Substances\Pages;

use App\Filament\Resources\Substances\SubstanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubstances extends ListRecords
{
    protected static string $resource = SubstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
