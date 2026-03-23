<?php

namespace App\Filament\Resources\IngredientAllergenEntries\Pages;

use App\Filament\Resources\IngredientAllergenEntries\IngredientAllergenEntryResource;
use Filament\Resources\Pages\EditRecord;

class EditIngredientAllergenEntry extends EditRecord
{
    protected static string $resource = IngredientAllergenEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
