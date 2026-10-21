<?php

namespace App\Filament\Resources\ToxicologyProfiles\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\ToxicologyProfiles\ToxicologyProfileResource;
use Filament\Resources\Pages\ViewRecord;

class ViewToxicologyProfile extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = ToxicologyProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [
            ...$data,
            'value_references' => collect($data['value_references'] ?? [])
                ->map(fn (array $numbers): string => implode(', ', $numbers))
                ->all(),
        ];
    }
}
