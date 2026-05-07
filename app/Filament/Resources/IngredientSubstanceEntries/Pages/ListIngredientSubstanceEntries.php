<?php

namespace App\Filament\Resources\IngredientSubstanceEntries\Pages;

use App\Filament\Resources\IngredientSubstanceEntries\IngredientSubstanceEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientSubstanceEntries extends ListRecords
{
    protected static string $resource = IngredientSubstanceEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
