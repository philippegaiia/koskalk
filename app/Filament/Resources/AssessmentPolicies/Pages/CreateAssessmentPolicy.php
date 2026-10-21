<?php

namespace App\Filament\Resources\AssessmentPolicies\Pages;

use App\Filament\Concerns\PublishesAppendOnlyVersions;
use App\Filament\Resources\AssessmentPolicies\AssessmentPolicyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAssessmentPolicy extends CreateRecord
{
    use PublishesAppendOnlyVersions;

    protected static string $resource = AssessmentPolicyResource::class;
}
