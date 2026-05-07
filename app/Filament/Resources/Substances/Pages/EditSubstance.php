<?php

namespace App\Filament\Resources\Substances\Pages;

use App\Filament\Resources\Substances\SubstanceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubstance extends EditRecord
{
    protected static string $resource = SubstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
