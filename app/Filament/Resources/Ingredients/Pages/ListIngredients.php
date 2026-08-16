<?php

namespace App\Filament\Resources\Ingredients\Pages;

use App\Filament\Exports\IngredientExporter;
use App\Filament\Resources\Ingredients\IngredientResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListIngredients extends ListRecords
{
    protected static string $resource = IngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->exporter(IngredientExporter::class)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                    'identifiers',
                    'aliases',
                    'substanceEntries.substance',
                ])),
            CreateAction::make(),
        ];
    }
}
