<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;

/**
 * For ViewRecord pages of append-only versioned resources.
 */
trait OffersNewVersion
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('newVersion')
                ->label('Publish new version')
                ->url(fn (): string => static::getResource()::getUrl('create', ['from' => $this->getRecord()->getKey()])),
        ];
    }
}
