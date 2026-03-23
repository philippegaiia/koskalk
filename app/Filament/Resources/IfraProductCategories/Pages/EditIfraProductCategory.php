<?php

namespace App\Filament\Resources\IfraProductCategories\Pages;

use App\Filament\Resources\IfraProductCategories\IfraProductCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditIfraProductCategory extends EditRecord
{
    protected static string $resource = IfraProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
