<?php

namespace App\Filament\Resources\UserIngredients\Pages;

use App\Filament\Resources\UserIngredients\UserIngredientResource;
use Filament\Resources\Pages\ListRecords;

class ListUserIngredients extends ListRecords
{
    protected static string $resource = UserIngredientResource::class;

    public function getSubheading(): ?string
    {
        return 'Anonymous catalog signals only. Account and recipe details remain private.';
    }
}
