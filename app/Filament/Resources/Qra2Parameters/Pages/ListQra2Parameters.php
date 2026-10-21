<?php

namespace App\Filament\Resources\Qra2Parameters\Pages;

use App\Filament\Resources\Qra2Parameters\Qra2ParameterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQra2Parameters extends ListRecords
{
    protected static string $resource = Qra2ParameterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
