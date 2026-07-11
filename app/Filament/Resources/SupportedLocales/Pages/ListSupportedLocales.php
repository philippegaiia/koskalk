<?php

namespace App\Filament\Resources\SupportedLocales\Pages;

use App\Filament\Resources\SupportedLocales\SupportedLocaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupportedLocales extends ListRecords
{
    protected static string $resource = SupportedLocaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
