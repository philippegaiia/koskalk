<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages\Pages;

use App\Filament\Concerns\MapsServiceValidationToForm;
use App\Filament\Resources\MaterialAssessmentCoverages\MaterialAssessmentCoverageResource;
use App\Models\MaterialAssessmentCoverage;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMaterialAssessmentCoverage extends EditRecord
{
    use MapsServiceValidationToForm;

    protected static string $resource = MaterialAssessmentCoverageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MaterialAssessmentCoverage $record */
        $record = $this->getRecord();

        return [
            ...$data,
            'review_state' => $record->review_state->value,
            'supporting_substance_ids' => $record->supportingSubstances()->pluck('substance_catalog.id')->all(),
        ];
    }

    /**
     * @param  MaterialAssessmentCoverage  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->withFormValidationErrors(fn (): Model => app(MaterialAssessmentCoverageService::class)->review(auth()->user(), $record->coverable, $data));
    }
}
