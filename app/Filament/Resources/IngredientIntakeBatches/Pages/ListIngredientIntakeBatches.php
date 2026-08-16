<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Pages;

use App\Filament\Resources\IngredientIntakeBatches\IngredientIntakeBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngredientIntakeBatches extends ListRecords
{
    protected static string $resource = IngredientIntakeBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('ingredient_intake_admin.actions.create')),
        ];
    }
}
