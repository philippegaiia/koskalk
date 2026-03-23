<?php

namespace App\Filament\Resources\IngredientSapProfiles\Pages;

use App\Filament\Resources\IngredientSapProfiles\IngredientSapProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientSapProfiles extends ListRecords
{
    protected static string $resource = IngredientSapProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
