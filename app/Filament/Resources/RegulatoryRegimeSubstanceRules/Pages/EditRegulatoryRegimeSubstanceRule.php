<?php

namespace App\Filament\Resources\RegulatoryRegimeSubstanceRules\Pages;

use App\Filament\Resources\RegulatoryRegimeSubstanceRules\RegulatoryRegimeSubstanceRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegulatoryRegimeSubstanceRule extends EditRecord
{
    protected static string $resource = RegulatoryRegimeSubstanceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
