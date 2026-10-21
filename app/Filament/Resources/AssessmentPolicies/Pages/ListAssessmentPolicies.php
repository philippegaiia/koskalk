<?php

namespace App\Filament\Resources\AssessmentPolicies\Pages;

use App\Filament\Resources\AssessmentPolicies\AssessmentPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssessmentPolicies extends ListRecords
{
    protected static string $resource = AssessmentPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
