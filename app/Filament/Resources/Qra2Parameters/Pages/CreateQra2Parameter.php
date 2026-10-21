<?php

namespace App\Filament\Resources\Qra2Parameters\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\Qra2Parameters\Qra2ParameterResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQra2Parameter extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = Qra2ParameterResource::class;
}
