<?php

namespace App\Filament\Resources\RegulatoryLegalEntries\Pages;

use App\Filament\Resources\RegulatoryLegalEntries\RegulatoryLegalEntryResource;
use App\Models\RegulatoryLegalEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRegulatoryLegalEntry extends EditRecord
{
    protected static string $resource = RegulatoryLegalEntryResource::class;

    /**
     * Field and substance-link writes share one transaction, so the row lock taken in beforeSave
     * serializes them against a concurrent review.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeSave(): void
    {
        $stored = RegulatoryLegalEntry::query()->whereKey($this->getRecord()->getKey())->lockForUpdate()->firstOrFail();

        if ($stored->isReviewed()) {
            Notification::make()->title('Reviewed entries are frozen')->body('End this entry and add a new one for an amendment.')->warning()->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function getFormActions(): array
    {
        /** @var RegulatoryLegalEntry $record */
        $record = $this->getRecord();

        return $record->isReviewed() ? [] : parent::getFormActions();
    }
}
