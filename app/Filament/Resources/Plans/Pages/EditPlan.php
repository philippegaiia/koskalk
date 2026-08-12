<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Models\Plan;
use App\Services\PlanLimitDefaultsService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function afterSave(): void
    {
        if ($this->record instanceof Plan) {
            app(PlanLimitDefaultsService::class)->ensureFormulaItemsPerRecipe($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
