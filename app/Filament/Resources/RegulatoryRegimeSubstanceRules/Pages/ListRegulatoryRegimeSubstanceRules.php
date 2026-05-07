<?php

namespace App\Filament\Resources\RegulatoryRegimeSubstanceRules\Pages;

use App\Filament\Resources\RegulatoryRegimeSubstanceRules\RegulatoryRegimeSubstanceRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegulatoryRegimeSubstanceRules extends ListRecords
{
    protected static string $resource = RegulatoryRegimeSubstanceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
