<?php

namespace App\Filament\Resources\ToxicologyProfiles\Pages;

use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListToxicologyProfiles extends ListRecords
{
    protected static string $resource = ToxicologyProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
