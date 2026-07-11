<?php

namespace App\Filament\Resources\InterfaceTranslations\Pages;

use App\Filament\Resources\InterfaceTranslations\InterfaceTranslationResource;
use Filament\Resources\Pages\EditRecord;

class EditInterfaceTranslation extends EditRecord
{
    protected static string $resource = InterfaceTranslationResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['text'] = array_filter(
            $data['text'] ?? [],
            fn (mixed $value): bool => is_string($value) && filled($value),
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
