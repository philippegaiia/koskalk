<?php

namespace App\Filament\Resources\MaterialAssessmentCoverages\Pages;

use App\Filament\Concerns\MapsServiceValidationToForm;
use App\Filament\Resources\MaterialAssessmentCoverages\MaterialAssessmentCoverageResource;
use App\Models\Ingredient;
use App\Models\Substance;
use App\Services\Dossiers\MaterialAssessmentCoverageService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMaterialAssessmentCoverage extends CreateRecord
{
    use MapsServiceValidationToForm;

    protected static string $resource = MaterialAssessmentCoverageResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $material = match ($data['coverable_type'] ?? null) {
            Ingredient::class => Ingredient::query()->whereNull('owner_type')->whereNull('workspace_id')->findOrFail($data['coverable_id']),
            Substance::class => Substance::query()->findOrFail($data['coverable_id']),
            default => throw ValidationException::withMessages(['data.coverable_type' => 'Choose an ingredient or a substance.']),
        };

        return $this->withFormValidationErrors(fn (): Model => app(MaterialAssessmentCoverageService::class)->review(auth()->user(), $material, $data));
    }
}
