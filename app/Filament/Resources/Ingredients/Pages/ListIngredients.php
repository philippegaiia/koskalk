<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Exports\IngredientExporter;
use App\Filament\Resources\Ingredients\IngredientResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredients extends ListRecords
{
    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->exporter(IngredientExporter::class),
            CreateAction::make(),
        ];
    }
}
