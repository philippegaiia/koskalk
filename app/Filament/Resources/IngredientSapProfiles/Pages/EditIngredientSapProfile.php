<?php

namespace App\Filament\Resources\IngredientSapProfiles\Pages;

use App\Filament\Resources\IngredientSapProfiles\IngredientSapProfileResource;
use Filament\Resources\Pages\EditRecord;

class EditIngredientSapProfile extends EditRecord
{
    protected static string $resource = IngredientSapProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
