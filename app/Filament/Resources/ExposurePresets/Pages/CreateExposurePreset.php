<?php

namespace App\Filament\Resources\ExposurePresets\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\ExposurePresets\ExposurePresetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExposurePreset extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = ExposurePresetResource::class;
}
