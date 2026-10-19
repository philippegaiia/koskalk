<?php

namespace App\Filament\Resources\UserIngredients\Pages;

use App\Filament\Resources\UserIngredients\UserIngredientResource;
use Filament\Resources\Pages\ListRecords;

class ListUserIngredients extends ListRecords
{
    protected static string $resource = UserIngredientResource::class;

    public function getSubheading(): ?string
    {
        return 'Read-only catalog review with workspace names and owner email addresses.';
    }
}
