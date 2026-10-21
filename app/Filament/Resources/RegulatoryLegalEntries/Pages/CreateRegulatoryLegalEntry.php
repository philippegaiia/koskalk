<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRegulatoryLegalEntry extends CreateRecord
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'review_status' => 'draft'];
    }
}
