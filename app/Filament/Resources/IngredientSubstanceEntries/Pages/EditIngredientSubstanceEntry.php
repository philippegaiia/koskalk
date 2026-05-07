<?php

namespace App\Filament\Resources\IngredientSubstanceEntries\Pages;

use App\Filament\Resources\IngredientSubstanceEntries\IngredientSubstanceEntryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIngredientSubstanceEntry extends EditRecord
{
    protected static string $resource = IngredientSubstanceEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
