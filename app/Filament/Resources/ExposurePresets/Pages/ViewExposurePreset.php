<?php

namespace App\Filament\Resources\ExposurePresets\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\ExposurePresets\ExposurePresetResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExposurePreset extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = ExposurePresetResource::class;
}
