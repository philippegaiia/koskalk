<?php

namespace App\Filament\Resources\IngredientVersions\Pages;

use App\Filament\Resources\IngredientVersions\IngredientVersionResource;
use Filament\Resources\Pages\EditRecord;

class EditIngredientVersion extends EditRecord
{
    protected static string $resource = IngredientVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
