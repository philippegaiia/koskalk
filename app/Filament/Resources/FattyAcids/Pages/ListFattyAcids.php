<?php

namespace App\Filament\Resources\FattyAcids\Pages;

use App\Filament\Exports\FattyAcidExporter;
use App\Filament\Resources\FattyAcids\FattyAcidResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListFattyAcids extends ListRecords
{
    protected static string $resource = FattyAcidResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->exporter(FattyAcidExporter::class),
            CreateAction::make(),
        ];
    }
}
