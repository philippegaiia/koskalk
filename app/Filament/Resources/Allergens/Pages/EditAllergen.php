<?php

namespace App\Filament\Resources\Allergens\Pages;

use App\Filament\Resources\Allergens\AllergenResource;
use Filament\Resources\Pages\EditRecord;

class EditAllergen extends EditRecord
{
    protected static string $resource = AllergenResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
