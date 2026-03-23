<?php

namespace App\Filament\Resources\IngredientVersions\Pages;

use App\Filament\Resources\IngredientVersions\IngredientVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientVersions extends ListRecords
{
    protected static string $resource = IngredientVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
