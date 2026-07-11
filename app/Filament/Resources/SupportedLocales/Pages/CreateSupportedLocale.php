<?php

namespace App\Filament\Resources\SupportedLocales\Pages;

use App\Filament\Resources\SupportedLocales\SupportedLocaleResource;
use App\Services\SupportedLocaleCatalog;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportedLocale extends CreateRecord
{
    protected static string $resource = SupportedLocaleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $locale = app(SupportedLocaleCatalog::class)->metadata($data['catalog_locale']);
        unset($data['catalog_locale']);

        return [...$data, ...$locale];
    }
}
