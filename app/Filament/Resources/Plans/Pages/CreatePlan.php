<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use App\Services\PlanLimitDefaultsService;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    protected function afterCreate(): void
    {
        if ($this->record instanceof Plan) {
            app(PlanLimitDefaultsService::class)->ensureFormulaItemsPerRecipe($this->record);
        }
    }
}
