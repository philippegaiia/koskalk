<?php

namespace App\Filament\Resources\IngredientIntakeBatches\Pages;

use App\Filament\Resources\IngredientIntakeBatches\IngredientIntakeBatchResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditIngredientIntakeBatch extends EditRecord
{
    protected static string $resource = IngredientIntakeBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
