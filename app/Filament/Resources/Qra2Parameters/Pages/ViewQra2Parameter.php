<?php

namespace App\Filament\Resources\Qra2Parameters\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\Qra2Parameters\Qra2ParameterResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQra2Parameter extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = Qra2ParameterResource::class;
}
