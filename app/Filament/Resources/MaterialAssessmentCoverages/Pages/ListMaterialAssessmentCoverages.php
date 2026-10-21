<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages\Pages;

use App\Filament\Resources\MaterialAssessmentCoverages\MaterialAssessmentCoverageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaterialAssessmentCoverages extends ListRecords
{
    protected static string $resource = MaterialAssessmentCoverageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
