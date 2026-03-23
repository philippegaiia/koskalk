<?php

namespace App\Filament\Resources\IfraCertificates\Pages;

use App\Filament\Resources\IfraCertificates\IfraCertificateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIfraCertificates extends ListRecords
{
    protected static string $resource = IfraCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
