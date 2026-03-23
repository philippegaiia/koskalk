<?php

namespace App\Filament\Resources\Allergens\Pages;

use App\Filament\Resources\Allergens\AllergenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAllergens extends ListRecords
{
    protected static string $resource = AllergenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
