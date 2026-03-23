<?php

namespace App\Filament\Resources\IfraProductCategories\Pages;

use App\Filament\Resources\IfraProductCategories\IfraProductCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIfraProductCategories extends ListRecords
{
    protected static string $resource = IfraProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
