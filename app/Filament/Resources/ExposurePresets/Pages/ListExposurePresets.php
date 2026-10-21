<?php

namespace App\Filament\Resources\ExposurePresets\Pages;

use App\Filament\Resources\ExposurePresets\ExposurePresetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExposurePresets extends ListRecords
{
    protected static string $resource = ExposurePresetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
