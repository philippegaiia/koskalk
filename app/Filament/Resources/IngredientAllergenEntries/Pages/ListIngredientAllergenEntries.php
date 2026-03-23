<?php

namespace App\Filament\Resources\IngredientAllergenEntries\Pages;

use App\Filament\Resources\IngredientAllergenEntries\IngredientAllergenEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientAllergenEntries extends ListRecords
{
    protected static string $resource = IngredientAllergenEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
