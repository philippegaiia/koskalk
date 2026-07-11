<?php

namespace App\Filament\Resources\InterfaceTranslations\Pages;

use App\Filament\Resources\InterfaceTranslations\InterfaceTranslationResource;
use Filament\Resources\Pages\ListRecords;

class ListInterfaceTranslations extends ListRecords
{
    protected static string $resource = InterfaceTranslationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
