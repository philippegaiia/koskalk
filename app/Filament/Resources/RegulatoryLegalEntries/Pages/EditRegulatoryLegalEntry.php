<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use App\Models\RegulatoryLegalEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRegulatoryLegalEntry extends EditRecord
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    protected function beforeSave(): void
    {
        /** @var RegulatoryLegalEntry $record */
        $record = $this->getRecord();

        if ($record->isReviewed()) {
            Notification::make()->title('Reviewed entries are frozen')->body('End this entry and add a new one for an amendment.')->warning()->send();
            $this->halt();
        }
    }

    protected function getFormActions(): array
    {
        /** @var RegulatoryLegalEntry $record */
        $record = $this->getRecord();

        return $record->isReviewed() ? [] : parent::getFormActions();
    }
}
