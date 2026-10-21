<?php

namespace App\Filament\Resources\AssessmentPolicies\Pages;

use App\Filament\Concerns\OffersNewVersion;
use App\Filament\Resources\AssessmentPolicies\AssessmentPolicyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAssessmentPolicy extends ViewRecord
{
    use OffersNewVersion;

    protected static string $resource = AssessmentPolicyResource::class;
}
