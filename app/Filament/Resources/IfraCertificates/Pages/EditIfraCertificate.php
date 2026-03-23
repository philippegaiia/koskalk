<?php

namespace App\Filament\Resources\IfraCertificates\Pages;

use App\Filament\Resources\IfraCertificates\IfraCertificateResource;
use Filament\Resources\Pages\EditRecord;

class EditIfraCertificate extends EditRecord
{
    protected static string $resource = IfraCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
