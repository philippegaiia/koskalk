<?php

namespace App\Filament\Resources\IngredientEnrichmentBatches\Pages;

use App\Filament\Resources\IngredientEnrichmentBatches\IngredientEnrichmentBatchResource;
use App\Filament\Resources\Ingredients\IngredientResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListIngredientEnrichmentBatches extends ListRecords
{
    protected static string $resource = IngredientEnrichmentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('ingredient_enrichment_admin.actions.create'))
                ->icon(Heroicon::Plus)
                ->url(IngredientResource::getUrl('index')),
        ];
    }
}
